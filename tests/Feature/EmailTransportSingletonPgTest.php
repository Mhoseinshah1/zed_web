<?php

namespace Tests\Feature;

use App\Models\EmailTransportSetting;
use App\Services\Email\EmailTransportSettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REAL concurrent first-time creation of the SMTP settings row on PostgreSQL:
 * the fixed unique `singleton_key` is the arbiter — parallel processes racing
 * the very first save must end with exactly ONE logical row and one
 * authoritative final configuration, no matter how the race schedules.
 *
 * No RefreshDatabase: forked children open FRESH connections, so fixtures
 * must be COMMITTED. Cleanup is explicit in tearDown. Children report through
 * per-child files and terminate with SIGKILL (a plain exit() would run
 * PHPUnit's shutdown handlers inside the fork).
 */
class EmailTransportSingletonPgTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real singleton races require PostgreSQL (CI pgsql job).');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to run real parallel saves.');
        }

        EmailTransportSetting::query()->delete();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            EmailTransportSetting::query()->delete();
        }

        parent::tearDown();
    }

    public function test_concurrent_first_time_saves_produce_exactly_one_singleton_row(): void
    {
        $dir = sys_get_temp_dir().'/zp-smtp-singleton-'.uniqid();
        mkdir($dir);

        $pids = [];
        foreach (range(1, 4) as $i) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');

            if ($pid === 0) {
                DB::purge();
                DB::reconnect();

                try {
                    // Each child behaves like a fresh admin request performing
                    // the very first save through the PRODUCTION persistence
                    // path (the page calls saveSingleton()): race losers must
                    // recover via the controlled adopt-the-winner update —
                    // an unhandled unique violation is a FAILURE, not an
                    // expected outcome.
                    $row = EmailTransportSetting::instanceOrNew();
                    $row->fill([
                        'enabled' => true,
                        'host' => "child-{$i}.example",
                        'port' => 587,
                        'security' => 'smtp',
                        'from_address' => "child-{$i}@example.com",
                        'timeout' => 10,
                    ]);
                    $row->saveSingleton();
                    $result = 'saved';
                } catch (QueryException) {
                    $result = 'unhandled-conflict';
                } catch (\Throwable) {
                    $result = 'crash';
                }
                file_put_contents($dir.'/c'.$i, $result);
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        DB::purge();
        DB::reconnect();
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        foreach (range(1, 4) as $i) {
            $results[] = is_file($dir.'/c'.$i) ? trim((string) file_get_contents($dir.'/c'.$i)) : 'missing';
            @unlink($dir.'/c'.$i);
        }
        @rmdir($dir);

        // EVERY child must terminate in the controlled state: race losers
        // recover through the adopt-the-winner update — no unhandled unique
        // violation, no crash, no silent disappearance.
        $this->assertSame(
            ['saved', 'saved', 'saved', 'saved'],
            $results,
            'all children complete through the controlled path ('.implode(',', $results).')'
        );

        // THE invariant: exactly one logical row, one authoritative config.
        $this->assertSame(1, EmailTransportSetting::query()->count(), 'exactly one singleton row may exist');
        $final = EmailTransportSetting::instance();
        $this->assertSame(EmailTransportSetting::SINGLETON_KEY, $final->singleton_key);
        $this->assertMatchesRegularExpression('/^child-[1-4]\.example$/', (string) $final->host);

        // The committed row is structurally valid, and runtime resolution
        // matches it — the effective configuration IS the authoritative row.
        $svc = new EmailTransportSettingsService;
        $this->assertTrue($svc->rowLooksStructurallyValid($final));
        $resolved = $svc->resolve();
        $this->assertSame(EmailTransportSettingsService::SOURCE_PANEL, $resolved['source']);
        $this->assertSame((string) $final->host, $resolved['config']['host']);
    }
}
