<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\SupportTicket;
use App\Models\SupportTicketCategory;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Query-count regressions on the customer dashboard.
 *
 * Two different defects live here, and only one of them is the familiar one.
 *
 * **N+1 over rows.** A page whose query count grows with the number of records
 * shown. The test for this is not a fixed budget — a budget drifts and gets
 * raised — but a COMPARISON: render the same page with 2 records and with 12,
 * and require the count not to grow. That is the property "eager loading is
 * correct", stated directly, and it cannot be satisfied by loosening a number.
 *
 * **N+1 over settings.** Measured before any change: 17 of the 21 queries on
 * `/dashboard/orders` were single-key `site_settings` lookups, and 15 of those
 * returned nothing at all. This never showed up as an N+1 over rows, because it
 * scales with the number of settings a page reads rather than the number of
 * records — it was simply a constant ~17-query tax on every request in the
 * application. `SiteSetting` now loads the table once per request, and the
 * tests below pin both the memoisation and its invalidation.
 *
 * The counts are asserted on SQLite in-memory, where every statement is cheap
 * and the numbers are stable; the point is the SHAPE of the growth, not the
 * absolute cost on production hardware.
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    /** Pages a logged-in customer actually loads. */
    private const PAGES = [
        '/dashboard',
        '/dashboard/orders',
        '/dashboard/services',
        '/dashboard/tickets',
        '/dashboard/notifications',
        '/dashboard/wallet',
    ];

    // ── N+1 over rows ──────────────────────────────────────────────────────

    public function test_no_dashboard_page_issues_more_queries_as_records_grow(): void
    {
        $small = $this->userWithRecords(2);
        $large = $this->userWithRecords(12);

        foreach (self::PAGES as $page) {
            $withFew = $this->queryCount($small, $page);
            $withMany = $this->queryCount($large, $page);

            $this->assertLessThanOrEqual(
                $withFew,
                $withMany,
                "{$page} issued {$withMany} queries for 12 records but only {$withFew} for 2 — "
                .'a relation is being loaded per row',
            );
        }
    }

    public function test_the_measurement_would_actually_notice_an_n_plus_one(): void
    {
        // A guard on the guard. If the counter were broken — logging disabled,
        // the page erroring early, the fixtures not being rendered — every
        // assertion above would pass on two zeros. Deliberately induce a
        // per-row query and require the measurement to see it.
        $user = $this->userWithRecords(12);

        $baseline = $this->queryCount($user, '/dashboard/orders');

        $induced = $this->measure(function () use ($user) {
            $this->actingAs($user)->get('/dashboard/orders');
            foreach (Order::where('user_id', $user->id)->get() as $order) {
                $order->plan()->first(); // the classic per-row lookup
            }
        });

        $this->assertGreaterThan(
            $baseline + 10,
            $induced,
            'the query counter does not observe per-row queries, so the assertions above prove nothing',
        );
    }

    // ── N+1 over settings ──────────────────────────────────────────────────

    public function test_settings_are_read_once_per_request_not_once_per_key(): void
    {
        $user = $this->userWithRecords(3);

        $count = $this->measure(fn () => $this->actingAs($user)->get('/dashboard/orders'));
        $settingsQueries = $this->settingsQueryCount($user, '/dashboard/orders');

        // Before this change: 17 settings queries for 15 distinct keys, on a
        // table holding 2 rows.
        $this->assertLessThanOrEqual(
            1,
            $settingsQueries,
            "the settings table was queried {$settingsQueries} times in one request",
        );
        $this->assertGreaterThan(0, $count);
    }

    public function test_repeated_reads_of_many_keys_cost_one_query(): void
    {
        SiteSetting::set('qc_alpha', 'a');
        SiteSetting::set('qc_beta', 'b');
        SiteSetting::flush();

        $queries = $this->measure(function () {
            for ($i = 0; $i < 20; $i++) {
                SiteSetting::get('qc_alpha');
                SiteSetting::get('qc_beta');
                SiteSetting::get('qc_absent_key', 'fallback');
            }
        });

        $this->assertSame(1, $queries, 'sixty reads across three keys must cost exactly one query');
    }

    public function test_a_missing_key_still_returns_its_default(): void
    {
        SiteSetting::set('qc_present', 'yes');
        SiteSetting::flush();

        $this->assertSame('yes', SiteSetting::get('qc_present'));
        $this->assertSame('fallback', SiteSetting::get('qc_definitely_absent', 'fallback'));
        $this->assertNull(SiteSetting::get('qc_definitely_absent'));
    }

    public function test_value_coercion_is_unchanged(): void
    {
        SiteSetting::set('qc_true', 'true');
        SiteSetting::set('qc_false', 'false');
        SiteSetting::set('qc_int', '42');
        SiteSetting::set('qc_float', '4.5');
        SiteSetting::set('qc_string', 'hello');

        $this->assertTrue(SiteSetting::get('qc_true'));
        $this->assertFalse(SiteSetting::get('qc_false'));
        $this->assertSame(42, SiteSetting::get('qc_int'));
        $this->assertSame('4.5', SiteSetting::get('qc_float'), 'a decimal stays a string, as before');
        $this->assertSame('hello', SiteSetting::get('qc_string'));
    }

    // ── Invalidation: the risk the memo introduces ─────────────────────────

    public function test_a_write_is_visible_to_the_very_next_read(): void
    {
        SiteSetting::set('qc_live', 'before');
        $this->assertSame('before', SiteSetting::get('qc_live'));

        SiteSetting::set('qc_live', 'after');
        $this->assertSame('after', SiteSetting::get('qc_live'), 'a stale read after a write is the whole risk here');
    }

    public function test_a_key_created_after_a_miss_is_seen_immediately(): void
    {
        // The memo has to remember the whole TABLE, not just the keys asked
        // for: caching a miss and never revisiting it would strand a setting
        // created later in the same request.
        $this->assertNull(SiteSetting::get('qc_created_later'));

        SiteSetting::set('qc_created_later', 'now here');

        $this->assertSame('now here', SiteSetting::get('qc_created_later'));
    }

    public function test_a_builder_level_upsert_invalidates_the_memo(): void
    {
        // Query-builder writes fire no model events. A raw
        // `SiteSetting::query()->upsert(...)` therefore leaves the memo stale —
        // which is exactly what broke two EmailVerificationHardeningTest cases
        // while this was being introduced. The write path is now a method that
        // invalidates.
        SiteSetting::set('qc_upsert', 'original');
        $this->assertSame('original', SiteSetting::get('qc_upsert'));

        SiteSetting::upsertValue('qc_upsert', 'replaced');

        $this->assertSame('replaced', SiteSetting::get('qc_upsert'));
    }

    public function test_insert_missing_invalidates_and_never_overwrites(): void
    {
        SiteSetting::set('qc_existing', 'keep me');
        $this->assertSame('keep me', SiteSetting::get('qc_existing'));

        SiteSetting::insertMissing([
            ['key' => 'qc_existing', 'value' => 'MUST NOT WIN'],
            ['key' => 'qc_brand_new', 'value' => 'created'],
        ]);

        $this->assertSame('keep me', SiteSetting::get('qc_existing'), 'a live value must never be overwritten');
        $this->assertSame('created', SiteSetting::get('qc_brand_new'));
    }

    public function test_a_deleted_setting_disappears_immediately(): void
    {
        SiteSetting::set('qc_doomed', 'here');
        $this->assertSame('here', SiteSetting::get('qc_doomed'));

        SiteSetting::where('key', 'qc_doomed')->first()->delete();

        $this->assertNull(SiteSetting::get('qc_doomed'));
    }

    // ── Machinery ──────────────────────────────────────────────────────────

    private function measure(callable $body): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $body();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function queryCount(User $user, string $uri): int
    {
        return $this->measure(fn () => $this->actingAs($user)->get($uri));
    }

    private function settingsQueryCount(User $user, string $uri): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get($uri);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return count(array_filter(
            $log,
            fn (array $q) => str_contains((string) $q['query'], 'site_settings'),
        ));
    }

    private function userWithRecords(int $count): User
    {
        $user = User::factory()->create(['wallet_balance_toman' => 500000]);
        $plan = Plan::factory()->create(['price_toman' => 100000, 'is_active' => true]);
        $category = SupportTicketCategory::create([
            'name' => 'qc cat '.$count, 'slug' => 'qc-cat-'.$count,
            'is_active' => true, 'sort_order' => 1,
        ]);

        for ($i = 0; $i < $count; $i++) {
            $order = Order::create([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
                'price_toman' => 100000, 'final_price_toman' => 100000,
                'status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID,
                'paid_at' => now(),
            ]);

            UserService::create([
                'service_number' => 'qc-'.$count.'-'.$i.'-'.bin2hex(random_bytes(3)),
                'user_id' => $user->id, 'order_id' => $order->id, 'plan_id' => $plan->id,
                'plan_name' => $plan->name, 'status' => UserService::STATUS_ACTIVE,
                'traffic_total_gb' => 10, 'duration_days' => 30,
                'expires_at' => now()->addDays(30),
            ]);

            SupportTicket::create([
                'user_id' => $user->id, 'subject' => 'qc ticket '.$i,
                'status' => SupportTicket::STATUS_OPEN, 'category_id' => $category->id,
            ]);

            Notification::create([
                'user_id' => $user->id, 'type' => 'system',
                'title' => 'qc notification '.$i, 'message' => 'body',
            ]);
        }

        return $user;
    }
}
