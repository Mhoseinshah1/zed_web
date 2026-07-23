<?php

namespace Tests\Feature;

use App\Support\SecretMasker;
use Tests\TestCase;

class SecretMaskerTest extends TestCase
{
    public function test_masks_credentials_in_connection_url(): void
    {
        $masked = SecretMasker::mask('could not connect to pgsql://appuser:S3cr3tPass@db.example.com:5432/prod');

        $this->assertStringNotContainsString('S3cr3tPass', $masked);
        $this->assertStringNotContainsString('appuser:S3cr3tPass', $masked);
        $this->assertStringNotContainsString('db.example.com', $masked);
    }

    public function test_masks_redis_endpoint(): void
    {
        $masked = SecretMasker::mask('Connection refused [tcp://127.0.0.1:6379]');

        $this->assertStringNotContainsString('127.0.0.1', $masked);
        $this->assertStringNotContainsString('6379', $masked);
    }

    public function test_masks_key_value_credentials(): void
    {
        $masked = SecretMasker::mask('auth failed password=hunter2 token=abcdef123');

        $this->assertStringNotContainsString('hunter2', $masked);
        $this->assertStringNotContainsString('abcdef123', $masked);
    }

    public function test_masks_known_config_values(): void
    {
        config(['database.redis.default.password' => 'RedisTopSecret9']);

        $masked = SecretMasker::mask('redis error using RedisTopSecret9 while pinging');

        $this->assertStringNotContainsString('RedisTopSecret9', $masked);
    }

    public function test_null_and_empty_are_safe(): void
    {
        $this->assertSame('', SecretMasker::mask(null));
        $this->assertSame('', SecretMasker::mask(''));
    }
}
