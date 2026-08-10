<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use PDOException;
use Tests\TestCase;

class CommitFailingSqlitePdo extends PDO
{
    public bool $failNextCommit = false;

    public function commit(): bool
    {
        if (! $this->failNextCommit) {
            return parent::commit();
        }

        $this->failNextCommit = false;
        parent::rollBack();

        throw new PDOException('deterministic commit failure');
    }
}

class SettingsCommitFailureTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.commit_failure', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('commit_failure');
        DB::connection('commit_failure')->setPdo(new CommitFailingSqlitePdo('sqlite::memory:'));
        DB::setDefaultConnection('commit_failure');
        DB::statement('create table site_settings (id integer primary key autoincrement, key varchar unique, value text null, created_at datetime null, updated_at datetime null)');
        app()->forgetInstance(SettingsRepository::class);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('commit_failure');

        parent::tearDown();
    }

    public function test_failed_pdo_commit_cannot_leave_transaction_local_settings_in_the_memo(): void
    {
        SiteSetting::set('commit_failure_probe', 'committed');
        $this->assertSame('committed', SiteSetting::get('commit_failure_probe'));

        $committedEvents = 0;
        $rolledBackEvents = 0;
        Event::listen(TransactionCommitted::class, function () use (&$committedEvents) {
            $committedEvents++;
        });
        Event::listen(TransactionRolledBack::class, function () use (&$rolledBackEvents) {
            $rolledBackEvents++;
        });

        /** @var CommitFailingSqlitePdo $pdo */
        $pdo = DB::connection()->getPdo();
        $pdo->failNextCommit = true;

        try {
            DB::transaction(function () {
                SiteSetting::set('commit_failure_probe', 'transaction local');
                $this->assertSame('transaction local', SiteSetting::get('commit_failure_probe'));
            });
            $this->fail('PDO::commit() must throw');
        } catch (PDOException $exception) {
            $this->assertSame('deterministic commit failure', $exception->getMessage());
        }

        $this->assertSame(0, $committedEvents);
        $this->assertSame(0, $rolledBackEvents);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('committed', SiteSetting::get('commit_failure_probe'));
    }
}
