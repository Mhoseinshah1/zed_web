<?php

namespace Tests\Feature;

use App\Logging\SensitiveDataProcessor;
use Monolog\DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class LogSanitizationTest extends TestCase
{
    private function record(string $message, array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(true),
            channel: 'testing',
            level: Level::Info,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    public function test_sensitive_context_keys_are_masked(): void
    {
        $processor = new SensitiveDataProcessor;

        $out = $processor($this->record('user login', [
            'user_id' => 42,
            'password' => 'ZP_CANARY_ADMIN_SECRET_abc123',
            'api_token' => 'ZP_CANARY_TOKEN_xyz789',
            'db_password' => 'ZP_CANARY_DB_SECRET_pw456',
            'status' => 'ok',
        ]));

        $this->assertSame(42, $out->context['user_id']);
        $this->assertSame('ok', $out->context['status']);
        $this->assertSame('[REDACTED]', $out->context['password']);
        $this->assertSame('[REDACTED]', $out->context['api_token']);
        $this->assertSame('[REDACTED]', $out->context['db_password']);

        $encoded = json_encode($out->context);
        $this->assertStringNotContainsString('ZP_CANARY_ADMIN_SECRET_abc123', $encoded);
        $this->assertStringNotContainsString('ZP_CANARY_TOKEN_xyz789', $encoded);
        $this->assertStringNotContainsString('ZP_CANARY_DB_SECRET_pw456', $encoded);
    }

    public function test_nested_context_keys_are_masked(): void
    {
        $processor = new SensitiveDataProcessor;

        $out = $processor($this->record('nested', [
            'headers' => [
                'authorization' => 'Bearer ZP_CANARY_TOKEN_nested',
                'x-request-id' => 'req-1',
            ],
        ]));

        $this->assertSame('[REDACTED]', $out->context['headers']['authorization']);
        $this->assertSame('req-1', $out->context['headers']['x-request-id']);
        $this->assertStringNotContainsString('ZP_CANARY_TOKEN_nested', json_encode($out->context));
    }

    public function test_message_patterns_are_scrubbed(): void
    {
        $processor = new SensitiveDataProcessor;

        $cases = [
            'APP_KEY=base64:ZP_CANARY_ADMIN_SECRET_appkey',
            'DB_PASSWORD=ZP_CANARY_DB_SECRET_dbpw',
            'Authorization: Bearer ZP_CANARY_TOKEN_bearer',
            'connecting to postgres://user:ZP_CANARY_DB_SECRET_url@localhost:5432/db',
            'token ghp_ZP0CANARY0TOKEN0githubaaaaaaaaaa',
        ];

        foreach ($cases as $message) {
            $out = $processor($this->record($message));
            $this->assertStringContainsString('[REDACTED]', $out->message, "Not redacted: {$message}");
            $this->assertStringNotContainsString('ZP_CANARY_DB_SECRET_dbpw', $out->message);
            $this->assertStringNotContainsString('ZP_CANARY_DB_SECRET_url', $out->message);
            $this->assertStringNotContainsString('ZP_CANARY_TOKEN_bearer', $out->message);
            $this->assertStringNotContainsString('ZP_CANARY_ADMIN_SECRET_appkey', $out->message);
        }
    }

    public function test_non_sensitive_message_is_untouched(): void
    {
        $processor = new SensitiveDataProcessor;

        $message = 'Order 1234 marked paid for user 42 in 35ms';
        $out = $processor($this->record($message));

        $this->assertSame($message, $out->message);
    }

    public function test_json_credential_fields_in_message_are_scrubbed(): void
    {
        $processor = new SensitiveDataProcessor;

        $message = '{"user":"admin","password":"ZP_CANARY_ADMIN_SECRET_json","note":"keep"}';
        $out = $processor($this->record($message));

        $this->assertStringNotContainsString('ZP_CANARY_ADMIN_SECRET_json', $out->message);
        $this->assertStringContainsString('keep', $out->message);
    }
}
