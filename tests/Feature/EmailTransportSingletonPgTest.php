<?php

namespace Tests\Feature;

use App\Models\EmailTransportSetting;
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
                    // the very first save, with its own candidate host.
                    $row = EmailTransportSetting::instanceOrNew();
                    $row->fill(['host' => "child-{$i}.example"]);
                    $row->save();
                    $result = 'saved';
                } catch (QueryException) {
                    // The DATABASE refused a second logical row — expected for
                    // race losers whose read predated the winner's commit.
                    $result = 'refused';
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

        $this->assertNotContains('crash', $results, 'no child may crash ('.implode(',', $results).')');
        $this->assertNotContains('missing', $results, 'every child must report ('.implode(',', $results).')');
        $this->assertGreaterThanOrEqual(1, count(array_filter($results, fn (string $r) => $r === 'saved')));

        // THE invariant: exactly one logical row, one authoritative config.
        $this->assertSame(1, EmailTransportSetting::query()->count(), 'exactly one singleton row may exist');
        $final = EmailTransportSetting::instance();
        $this->assertSame(EmailTransportSetting::SINGLETON_KEY, $final->singleton_key);
        $this->assertMatchesRegularExpression('/^child-[1-4]\.example$/', (string) $final->host);
    }
}
