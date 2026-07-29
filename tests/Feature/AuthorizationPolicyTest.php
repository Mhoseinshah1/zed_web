<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserService;
use App\Policies\NotificationPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentTransactionPolicy;
use App\Policies\SupportTicketPolicy;
use App\Policies\UserServicePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ownership authorization for every user-scoped dashboard route.
 *
 * The rule is simple — your orders, services, tickets and notifications are
 * yours and nobody else's — and until now it was written out 22 separate times
 * as an inline `abort_if`. Inline checks are only as complete as the discipline
 * of whoever adds the next controller action, and nothing failed when one was
 * forgotten.
 *
 * So the important test here is not "does route X deny a stranger" written 24
 * times. It is **discovered from the route table**: every registered route
 * carrying an owned-model parameter is enumerated at run time and asserted to
 * deny a logged-in stranger. A route added tomorrow without an ownership check
 * fails this test without anybody remembering to extend it — which is the only
 * kind of coverage that survives contact with a growing codebase.
 *
 * Denial means 403 or 404. Both are acceptable answers to "not yours"; a 404 is
 * arguably better because it does not confirm the record exists. What is never
 * acceptable is a 2xx or a redirect into the resource.
 */
class AuthorizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Owned model classes and the fixture that stands in for each.
     *
     * Keyed by CLASS, not by route-parameter name. The first version keyed on
     * the names `order`/`service`/`ticket`/`notification`, which silently misses
     * any future route that binds the same model under a different parameter
     * name — the exact failure the coverage test exists to prevent. Route
     * binding classes are resolved from the router itself.
     */
    private const OWNED_MODELS = [
        Order::class,
        UserService::class,
        SupportTicket::class,
        Notification::class,
        PaymentTransaction::class,
    ];

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['wallet_balance_toman' => 0]);
        $this->stranger = User::factory()->create(['wallet_balance_toman' => 0]);

        // Rate limiting must not be what answers these requests: a 429 would
        // look like "denied" while proving nothing about authorization.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ── The policies themselves ────────────────────────────────────────────

    /** @return array<string,array{0:string,1:string}> */
    public static function ownedPolicies(): array
    {
        return [
            'order' => [OrderPolicy::class, 'order'],
            'service' => [UserServicePolicy::class, 'service'],
            'ticket' => [SupportTicketPolicy::class, 'ticket'],
            'notification' => [NotificationPolicy::class, 'notification'],
            'payment transaction' => [PaymentTransactionPolicy::class, 'paymentTransaction'],
        ];
    }

    #[DataProvider('ownedPolicies')]
    public function test_the_customer_abilities_grant_only_the_owner(string $policyClass, string $fixture): void
    {
        $policy = new $policyClass;
        $model = $this->{$fixture}();

        $this->assertTrue($policy->viewOwned($this->owner, $model), 'the owner may view');
        $this->assertTrue($policy->updateOwned($this->owner, $model), 'the owner may update');
        $this->assertFalse($policy->viewOwned($this->stranger, $model), 'a stranger may not view');
        $this->assertFalse($policy->updateOwned($this->stranger, $model), 'a stranger may not update');
    }

    /**
     * The heart of this round. An administrator is NOT a customer.
     *
     * The previous version used `before()` returning true for administrators, to
     * repair Filament. Reproduced: an admin opening another user's order at
     * `GET /dashboard/orders/{order}` received HTTP 200. It is now 403.
     */
    #[DataProvider('ownedPolicies')]
    public function test_an_administrator_gets_no_customer_surface_bypass(string $policyClass, string $fixture): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $policy = new $policyClass;
        $model = $this->{$fixture}();

        $this->assertFalse(
            $policy->viewOwned($admin, $model),
            'an administrator must not read another user\'s record through the customer surface',
        );
        $this->assertFalse($policy->updateOwned($admin, $model));

        // …but the administrative surface, which is what Filament resolves,
        // remains open to them and closed to everyone else.
        $this->assertTrue($policy->view($admin, $model));
        $this->assertTrue($policy->viewAny($admin));
        $this->assertFalse($policy->view($this->stranger, $model));
        $this->assertFalse($policy->viewAny($this->stranger));
    }

    public function test_an_administrator_is_denied_another_users_customer_route(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('dashboard.orders.show', $this->order()))
            ->assertForbidden();
    }

    public function test_the_admin_panel_still_works_for_administrators(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get("/zed-admin/orders/{$this->order()->id}/edit")
            ->assertStatus(200);
    }

    /**
     * Mutating controller actions must take the MUTATION ability.
     *
     * `viewOwned` and `updateOwned` currently resolve to the same ownership
     * rule, so swapping one for the other changes no behaviour today and no
     * behavioural test can see it — verified by mutation. The split exists so
     * the two surfaces can diverge later (an audit trail, a read-only support
     * role, a frozen account that may still be read). If the classification is
     * allowed to rot now, that future change silently grants writes.
     *
     * So this asserts the classification structurally, from the controller
     * source, rather than pretending a behavioural test covers it.
     */
    public function test_state_changing_actions_use_the_mutation_ability(): void
    {
        $mutating = [
            'CentralPayController' => ['initiate'],
            'NotificationController' => ['markRead'],
            'NowPaymentsController' => ['create', 'checkStatus'],
            'OrderController' => ['applyDiscount', 'removeDiscount'],
            'PaymentController' => ['submit'],
            'RenewalController' => ['submit'],
            'ServiceAddonController' => ['submitTraffic', 'submitTime'],
            'ServiceController' => ['refresh'],
            'SupportTicketController' => ['reply', 'close'],
            'UserServiceActionController' => ['authorizeService'],
        ];

        $readOnly = [
            'OrderController' => ['show'],
            'PaymentController' => ['show'],
            'RenewalController' => ['show'],
            'ServiceController' => ['show'],
            'ServiceAddonController' => ['showTraffic', 'showTime'],
            'SupportTicketController' => ['show'],
            'NowPaymentsController' => ['show'],
        ];

        foreach ($mutating as $controller => $methods) {
            foreach ($methods as $method) {
                $this->assertSame(
                    'updateOwned',
                    $this->abilityUsedIn($controller, $method),
                    "{$controller}::{$method}() changes state and must use the mutation ability",
                );
            }
        }

        foreach ($readOnly as $controller => $methods) {
            foreach ($methods as $method) {
                $this->assertSame(
                    'viewOwned',
                    $this->abilityUsedIn($controller, $method),
                    "{$controller}::{$method}() only reads and must use the read ability",
                );
            }
        }
    }

    /** The ability named by the first authorize() call inside a method. */
    private function abilityUsedIn(string $controller, string $method): ?string
    {
        $reflection = new \ReflectionMethod('App\\Http\\Controllers\\'.$controller, $method);
        $source = implode('', array_slice(
            file($reflection->getFileName()),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        return preg_match("/authorize\\(\'(\\w+)\'/", $source, $m) === 1 ? $m[1] : null;
    }

    // ── Canonical owner identity ───────────────────────────────────────────

    /** @return array<string,array{0:mixed}> */
    public static function malformedOwnerIds(): array
    {
        return [
            'trailing letters' => ['3abc'],
            'leading space' => [' 3'],
            'trailing space' => ['3 '],
            'leading zero' => ['03'],
            'decimal string' => ['3.5'],
            'float' => [3.5],
            'plus sign' => ['+3'],
            'exponent' => ['3e0'],
            'hex' => ['0x3'],
            'boolean true' => [true],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'zero' => [0],
            'zero string' => ['0'],
            'negative' => [-3],
            'negative string' => ['-3'],
            'null' => [null],
            'array' => [[3]],
            'object' => [(object) ['id' => 3]],
            'overflow beyond PHP_INT_MAX' => ['9223372036854775808'],
            'huge digits' => [str_repeat('3', 40)],
            'unicode digit' => ["\u{0663}"],
            'newline padded' => ["3\n"],
            'tab padded' => ["\t3"],
        ];
    }

    /**
     * `(int)` is lossy. Measured: it maps '3abc', ' 3', '03', '3.5', '+3',
     * '3e0' and '3 ' all to 3, so every one of them would have authorized as
     * owner 3 under the previous cast-and-compare.
     */
    #[DataProvider('malformedOwnerIds')]
    public function test_a_malformed_owner_id_never_authorizes(mixed $ownerId): void
    {
        $service = $this->service();
        $service->setAttribute('user_id', $ownerId);

        $this->assertFalse(
            (new UserServicePolicy)->viewOwned($this->owner, $service),
            'a malformed owner id must never be normalised into authorization',
        );
    }

    /** @return array<string,array{0:mixed}> */
    public static function validOwnerIds(): array
    {
        return [
            'integer' => ['int'],
            'canonical decimal string' => ['string'],
        ];
    }

    /**
     * Both drivers in use must keep working: PostgreSQL and SQLite can hand an
     * integer column back as an int or as a string depending on driver and cast
     * configuration, and a strict `===` without normalisation would lock the
     * real owner out.
     */
    #[DataProvider('validOwnerIds')]
    public function test_a_valid_owner_id_still_authorizes(string $shape): void
    {
        $service = $this->service();
        $service->setAttribute(
            'user_id',
            $shape === 'int' ? (int) $this->owner->id : (string) $this->owner->id,
        );

        $this->assertTrue((new UserServicePolicy)->viewOwned($this->owner, $service));
        $this->assertFalse((new UserServicePolicy)->viewOwned($this->stranger, $service));
    }

    public function test_an_ownerless_record_belongs_to_nobody(): void
    {
        $systemNotification = Notification::create([
            'user_id' => null,
            'type' => 'system',
            'title' => 'admin only',
            'message' => 'body',
        ]);

        $policy = new NotificationPolicy;

        $this->assertFalse($policy->viewOwned($this->owner, $systemNotification));
        $this->assertFalse($policy->viewOwned($this->stranger, $systemNotification));
    }

    public function test_a_service_cannot_be_ownerless_at_the_database_level(): void
    {
        $this->expectException(QueryException::class);

        UserService::create([
            'service_number' => 'authz-orphan',
            'user_id' => null,
            'plan_name' => 'p',
            'status' => UserService::STATUS_ACTIVE,
            'traffic_total_gb' => 10,
            'duration_days' => 30,
        ]);
    }

    /**
     * `PaymentTransaction` has NO customer route, so route-level coverage is
     * not applicable. Asserting that explicitly is honest; inventing a fake
     * route to claim coverage would not be.
     */
    public function test_payment_transaction_has_no_customer_route_and_is_covered_directly(): void
    {
        $bound = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($this->boundModelsFor($route) as $class) {
                if ($class === PaymentTransaction::class) {
                    $bound[] = $route->getName() ?? $route->uri();
                }
            }
        }

        $this->assertSame([], $bound, 'if a customer route ever binds PaymentTransaction, it needs route coverage here');

        // Covered directly instead: owner, stranger and administrator.
        $admin = User::factory()->create(['is_admin' => true]);
        $policy = new PaymentTransactionPolicy;
        $tx = $this->paymentTransaction();

        $this->assertTrue($policy->viewOwned($this->owner, $tx));
        $this->assertFalse($policy->viewOwned($this->stranger, $tx));
        $this->assertFalse($policy->viewOwned($admin, $tx));
        $this->assertTrue($policy->view($admin, $tx));
        $this->assertFalse($policy->view($this->stranger, $tx));
        $this->assertInstanceOf(PaymentTransactionPolicy::class, Gate::getPolicyFor(PaymentTransaction::class));
    }

    public function test_the_policies_are_discovered_by_the_gate(): void
    {
        $this->assertInstanceOf(OrderPolicy::class, Gate::getPolicyFor(Order::class));
        $this->assertInstanceOf(UserServicePolicy::class, Gate::getPolicyFor(UserService::class));
        $this->assertInstanceOf(SupportTicketPolicy::class, Gate::getPolicyFor(SupportTicket::class));
        $this->assertInstanceOf(NotificationPolicy::class, Gate::getPolicyFor(Notification::class));
        $this->assertInstanceOf(PaymentTransactionPolicy::class, Gate::getPolicyFor(PaymentTransaction::class));
    }

    // ── Route-table coverage ───────────────────────────────────────────────

    /**
     * The load-bearing test: EVERY route carrying an owned-model parameter is
     * discovered from the route table and probed as a stranger.
     */
    public function test_every_owned_route_refuses_a_stranger(): void
    {
        $probed = 0;

        foreach ($this->ownedRoutes() as [$method, $uri, $name]) {
            $response = $this->actingAs($this->stranger)->call($method, $uri);

            $this->assertContains(
                $response->getStatusCode(),
                [403, 404],
                "{$name} ({$method} {$uri}) let a stranger through with HTTP {$response->getStatusCode()}",
            );

            $probed++;
        }

        // Guard against the enumeration silently matching nothing — an empty
        // loop would make every assertion above vacuous.
        $this->assertGreaterThanOrEqual(
            20,
            $probed,
            'the owned-route enumeration matched too few routes to be trustworthy',
        );
    }

    /** The same routes must still work for the person they belong to. */
    public function test_the_owner_is_not_locked_out_of_their_own_records(): void
    {
        $reachable = 0;

        foreach ($this->ownedRoutes() as [$method, $uri, $name]) {
            if ($method !== 'GET') {
                continue; // POST/DELETE have their own validation and side effects
            }

            $response = $this->actingAs($this->owner)->call($method, $uri);

            $this->assertNotContains(
                $response->getStatusCode(),
                [403, 404],
                "{$name} ({$uri}) refused the record's OWNER with HTTP {$response->getStatusCode()}",
            );

            $reachable++;
        }

        $this->assertGreaterThan(0, $reachable, 'no owner-reachable GET route was exercised');
    }

    /**
     * Every owned route as an unauthenticated visitor. A stranger being denied
     * is worth little if the route was reachable without logging in at all.
     */
    public function test_every_owned_route_refuses_an_anonymous_visitor(): void
    {
        foreach ($this->ownedRoutes() as [$method, $uri, $name]) {
            $response = $this->call($method, $uri);

            $this->assertContains(
                $response->getStatusCode(),
                [302, 401, 403, 404],
                "{$name} ({$method} {$uri}) was reachable anonymously with HTTP {$response->getStatusCode()}",
            );

            if ($response->getStatusCode() === 302) {
                $this->assertStringContainsString(
                    'login',
                    (string) $response->headers->get('Location'),
                    "{$name} redirected somewhere other than login",
                );
            }
        }
    }

    /**
     * Discovery must key on the bound MODEL CLASS, not the parameter name.
     *
     * The first version enumerated the literal names order/service/ticket/
     * notification. A future route binding the same model under any other name
     * — `{invoice}`, `{subscription}` — would have gone unprobed, which is
     * exactly the gap this whole test exists to close. Registering such a route
     * here proves discovery follows the controller signature.
     */
    public function test_discovery_finds_an_owned_model_bound_under_a_different_name(): void
    {
        Route::middleware('web')->get(
            '/dashboard/authz-probe/{invoice}',
            [AuthorizationProbeController::class, 'show'],
        )->name('authz.probe');

        $names = array_column($this->ownedRoutes(), 2);

        $this->assertContains(
            'authz.probe',
            $names,
            'a route binding Order as {invoice} must still be discovered',
        );
    }

    /**
     * …and an unprotected owned route is actually caught. This is the property
     * the whole class exists for: a new route added without authorization must
     * fail without anybody remembering to extend a list.
     */
    public function test_an_unprotected_owned_route_is_detected(): void
    {
        Route::middleware('web')->get(
            '/dashboard/authz-unprotected/{invoice}',
            [AuthorizationProbeController::class, 'unprotected'],
        )->name('authz.unprotected');

        $response = $this->actingAs($this->stranger)->get('/dashboard/authz-unprotected/'.$this->order()->getRouteKey());

        $this->assertSame(200, $response->getStatusCode(), 'the probe route is deliberately unguarded');

        // The coverage test must therefore fail. Capture that rather than
        // letting it pass silently.
        $failed = false;
        try {
            $this->test_every_owned_route_refuses_a_stranger();
        } catch (AssertionFailedError) {
            $failed = true;
        }

        $this->assertTrue($failed, 'an unprotected owned route must make the coverage test fail');
    }

    /**
     * No owned route may be silently dropped from the probe set.
     *
     * The previous discovery required EVERY route parameter to be a bound
     * model, so an owned route carrying any extra scalar vanished from coverage
     * without a word — the quietest possible way for this whole class to stop
     * protecting something. Unresolvable owned routes are now a hard failure
     * that names them.
     */
    public function test_no_owned_route_is_silently_skipped(): void
    {
        $discovered = $this->discoverOwnedRoutes();

        $this->assertSame(
            [],
            $discovered['unresolvable'],
            "these owned routes could not be probed and would have been skipped silently:\n  "
            .implode("\n  ", $discovered['unresolvable']),
        );

        // Counting the owned routes INDEPENDENTLY of resolvability is what
        // makes this guard survive its own removal: emptying the unresolvable
        // list would satisfy the assertion above while quietly shrinking
        // coverage, so every owned route must also appear in the probe set.
        $probed = array_unique(array_column($discovered['probeable'], 2));
        $missing = array_values(array_diff(array_unique($discovered['owned']), $probed));

        $this->assertSame(
            [],
            $missing,
            "these owned routes bind an owned model but never reached the probe set:\n  "
            .implode("\n  ", $missing),
        );
    }

    /**
     * Discovery must survive an owned model sitting alongside a scalar.
     *
     * Registering the route is what makes this real: under the previous
     * implementation this route is absent from the probe set entirely, so an
     * unguarded version of it would never have been caught.
     */
    public function test_an_owned_route_with_an_extra_scalar_parameter_is_discovered(): void
    {
        Route::middleware('web')->get(
            '/dashboard/authz-probe/{invoice}/export/{format}',
            [AuthorizationProbeController::class, 'export'],
        )->name('authz.probe.export');

        $names = array_column($this->ownedRoutes(), 2);

        $this->assertContains(
            'authz.probe.export',
            $names,
            'an owned model plus a scalar parameter must still be probed',
        );

        // …and it is genuinely exercised: a stranger is denied.
        $this->actingAs($this->stranger)
            ->get('/dashboard/authz-probe/'.$this->order()->getRouteKey().'/export/json')
            ->assertForbidden();
    }

    public function test_an_unprotected_owned_route_with_a_scalar_is_detected(): void
    {
        Route::middleware('web')->get(
            '/dashboard/authz-unprotected/{invoice}/export/{format}',
            [AuthorizationProbeController::class, 'unprotectedExport'],
        )->name('authz.unprotected.export');

        $failed = false;
        try {
            $this->test_every_owned_route_refuses_a_stranger();
        } catch (AssertionFailedError) {
            $failed = true;
        }

        $this->assertTrue($failed, 'an unprotected owned route with a scalar must fail the coverage test');
    }

    /**
     * Owner coverage for MUTATING routes, without performing the mutation.
     *
     * The stranger probes prove denial; they do not prove the owner still gets
     * through, and a mutation ability applied to the wrong action would fail
     * closed for the legitimate user. Probing these for real would contact VPN
     * panels, call payment gateways and change service state, so the assertion
     * is that authorization is NOT what stops the request: anything except 403
     * means the policy let the owner past and some later layer (validation,
     * state checks, redirects) took over.
     *
     * External effects are prevented outright — no HTTP request can escape.
     */
    public function test_the_owner_passes_authorization_on_mutating_routes(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
        Queue::fake();
        Mail::fake();
        NotificationFacade::fake();

        $probed = 0;

        foreach ($this->ownedRoutes() as [$method, $uri, $name]) {
            if ($method === 'GET') {
                continue;
            }

            $response = $this->actingAs($this->owner)->call($method, $uri);

            $this->assertNotSame(
                403,
                $response->getStatusCode(),
                "{$name} ({$method} {$uri}) refused its OWNER — a mutation ability on the wrong action",
            );

            $probed++;
        }

        $this->assertGreaterThanOrEqual(
            10,
            $probed,
            'too few mutating routes exercised for this to be meaningful',
        );
    }

    // ── Discovery ──────────────────────────────────────────────────────────

    /**
     * Every registered route that binds at least one owned model.
     *
     * Two failure modes are designed out here.
     *
     * **Name-based discovery.** The first version enumerated the literal names
     * order/service/ticket/notification, so a route binding the same model as
     * `{invoice}` went unprobed. Discovery now resolves the bound model CLASS
     * from the controller signature.
     *
     * **Silent skipping.** The second version required EVERY parameter to be a
     * bound model, so an owned route carrying any extra scalar — a format, a
     * token, an action — was dropped from the set without a word. A route can
     * now be skipped only by being unresolvable, and every unresolvable owned
     * route is reported as a hard failure by
     * {@see test_no_owned_route_is_silently_skipped()}.
     *
     * @return list<array{0:string,1:string,2:string}> [method, uri, name]
     */
    private function ownedRoutes(): array
    {
        return $this->discoverOwnedRoutes()['probeable'];
    }

    /**
     * @return array{probeable: list<array{0:string,1:string,2:string}>, unresolvable: list<string>, owned: list<string>}
     */
    private function discoverOwnedRoutes(): array
    {
        $models = $this->fixtureRouteKeys();
        $probeable = [];
        $unresolvable = [];
        $owned = [];

        foreach (Route::getRoutes() as $route) {
            $parameters = $route->parameterNames();
            if ($parameters === []) {
                continue;
            }

            $bound = $this->boundModelsFor($route);

            $ownsOne = false;
            foreach ($bound as $class) {
                if ($class !== null && in_array($class, self::OWNED_MODELS, true)) {
                    $ownsOne = true;
                    break;
                }
            }
            if (! $ownsOne) {
                continue;
            }

            $label = $route->getName() ?? ($route->methods()[0].' '.$route->uri());
            $owned[] = $label;
            $resolved = $route->uri();
            $failed = null;

            foreach ($parameters as $parameter) {
                $class = $bound[$parameter] ?? null;

                if ($class !== null && isset($models[$class])) {
                    $value = (string) $models[$class];
                } else {
                    $value = $this->scalarRouteValue($route, $parameter);
                }

                if ($value === null) {
                    $failed = $parameter;
                    break;
                }

                $resolved = str_replace(
                    ['{'.$parameter.'}', '{'.$parameter.'?}'],
                    $value,
                    $resolved,
                );
            }

            if ($failed !== null) {
                $unresolvable[] = $label.' (cannot resolve {'.$failed.'})';

                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $probeable[] = [$method, '/'.ltrim($resolved, '/'), $label];
            }
        }

        return ['probeable' => $probeable, 'unresolvable' => $unresolvable, 'owned' => $owned];
    }

    /**
     * A safe stand-in for a NON-model route parameter.
     *
     * Prefers the route's own regex constraint or a maintained fixture; returns
     * null when neither applies, which surfaces as an explicit failure rather
     * than a silent skip.
     */
    private function scalarRouteValue(RoutingRoute $route, string $parameter): ?string
    {
        $known = [
            'format' => 'json',
            'action' => 'view',
            'token' => 'authz-probe-token',
            'type' => 'default',
            'page' => '1',
            'tab' => 'overview',
        ];

        if (isset($known[$parameter])) {
            return $known[$parameter];
        }

        // A route constraint like ->where('slug', '[a-z]+') names its own
        // alphabet; a simple alphanumeric probe satisfies the common cases.
        $pattern = $route->wheres[$parameter] ?? null;
        if (is_string($pattern) && preg_match('/^'.$pattern.'$/', 'authzprobe') === 1) {
            return 'authzprobe';
        }
        if (is_string($pattern) && preg_match('/^'.$pattern.'$/', '1') === 1) {
            return '1';
        }

        // Optional parameters can simply be omitted.
        if (str_contains($route->uri(), '{'.$parameter.'?}')) {
            return '';
        }

        return null;
    }

    /**
     * Map each route parameter to the model class the controller type-hints.
     *
     * @return array<string,string|null>
     */
    private function boundModelsFor(RoutingRoute $route): array
    {
        $action = $route->getAction('uses');
        if (! is_string($action) || ! str_contains($action, '@')) {
            return [];
        }

        [$controller, $method] = explode('@', $action, 2);
        if (! class_exists($controller) || ! method_exists($controller, $method)) {
            return [];
        }

        $models = [];
        foreach ((new \ReflectionMethod($controller, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $class = $type->getName();
            if (is_subclass_of($class, Model::class)) {
                $models[$parameter->getName()] = $class;
            }
        }

        return $models;
    }

    /** @return array<class-string,int|string> */
    private function fixtureRouteKeys(): array
    {
        return [
            Order::class => $this->order()->getRouteKey(),
            UserService::class => $this->service()->getRouteKey(),
            SupportTicket::class => $this->ticket()->getRouteKey(),
            Notification::class => $this->notification()->getRouteKey(),
            PaymentTransaction::class => $this->paymentTransaction()->getRouteKey(),
        ];
    }

    // ── Fixtures, all owned by $this->owner ────────────────────────────────

    private ?Order $orderFixture = null;

    private ?UserService $serviceFixture = null;

    private ?SupportTicket $ticketFixture = null;

    private ?Notification $notificationFixture = null;

    private ?PaymentTransaction $paymentTransactionFixture = null;

    private function paymentTransaction(): PaymentTransaction
    {
        if ($this->paymentTransactionFixture === null) {
            $method = PaymentMethod::create([
                'title' => 'authz gateway',
                'slug' => 'authz-gateway',
                'type' => PaymentMethod::TYPE_CENTRALPAY,
                'is_active' => true,
                'sort_order' => 9,
                'api_key' => 'authz-key',
                'config' => ['base_url' => 'https://example.test'],
            ]);

            $this->paymentTransactionFixture = PaymentTransaction::create([
                'order_id' => $this->order()->id,
                'user_id' => $this->owner->id,
                'payment_method_id' => $method->id,
                'provider' => 'centralpay',
                'method' => 'centralpay',
                'payment_purpose' => 'order_payment',
                'status' => PaymentTransaction::STATUS_WAITING,
                'amount_toman' => 200000,
                'gateway_amount' => 200000,
                'gateway_currency' => 'TOMAN',
                'gateway_status' => 'created',
            ]);
        }

        return $this->paymentTransactionFixture;
    }

    private function order(): Order
    {
        if ($this->orderFixture === null) {
            $plan = Plan::factory()->create(['price_toman' => 200000, 'is_active' => true]);
            $this->orderFixture = Order::create([
                'user_id' => $this->owner->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'price_toman' => 200000,
                'final_price_toman' => 200000,
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'payment_status' => Order::PAYMENT_UNPAID,
            ]);
        }

        return $this->orderFixture;
    }

    private function service(): UserService
    {
        if ($this->serviceFixture === null) {
            $this->serviceFixture = UserService::create([
                'service_number' => 'authz-svc',
                'user_id' => $this->owner->id,
                'plan_name' => 'Authz Plan',
                'status' => UserService::STATUS_ACTIVE,
                'traffic_total_gb' => 20,
                'duration_days' => 30,
                'expires_at' => now()->addDays(30),
            ]);
        }

        return $this->serviceFixture;
    }

    private function ticket(): SupportTicket
    {
        if ($this->ticketFixture === null) {
            $this->ticketFixture = SupportTicket::create([
                'user_id' => $this->owner->id,
                'subject' => 'authz ticket',
                'status' => SupportTicket::STATUS_OPEN,
            ]);
        }

        return $this->ticketFixture;
    }

    private function notification(): Notification
    {
        if ($this->notificationFixture === null) {
            $this->notificationFixture = Notification::create([
                'user_id' => $this->owner->id,
                'type' => 'system',
                'title' => 'authz notification',
                'message' => 'body',
            ]);
        }

        return $this->notificationFixture;
    }
}
