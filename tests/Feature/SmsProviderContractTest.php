<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\Sms\AbstractSmsProvider;
use App\Services\Sms\Providers\CustomSmsProvider;
use App\Services\Sms\Providers\FarazSmsProvider;
use App\Services\Sms\Providers\KavenegarSmsProvider;
use App\Services\Sms\Providers\SmsIrProvider;
use App\Services\Sms\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CapturesThrowables;
use Tests\TestCase;

/**
 * What an SMS adapter is allowed to call a successful send.
 *
 * A 2xx status is not acceptance. Iranian SMS panels routinely answer HTTP 200
 * while reporting the real outcome in the body — an invalid receptor, an
 * exhausted credit balance, a rejected sender line, an unapproved pattern.
 *
 * That is worse than an outright failure. `SmsService` returns true, the caller
 * records "code sent", the user waits for an OTP that was never dispatched, and
 * nothing in the logs suggests a problem. For a login or password-reset code
 * that is a lockout the operator cannot see.
 *
 * ── This class was rewritten after it was caught lying ────────────────────
 *
 * The first version used `try { …; $this->fail(…); } catch (\RuntimeException)`.
 * `AssertionFailedError` IS a `\RuntimeException` (verified on PHPUnit 12.5.30),
 * so the catch swallowed the test's own failure and then asserted against its
 * own message. Because the case labels contained the word "rejected", the
 * assertion passed.
 *
 * It hid a real defect: `SmsIrProvider` had never been given body-level
 * validation at all, and every SMS.ir test still passed. A second masking
 * factor was looping over cases inside one test — the loop aborted on the first
 * case, so later providers were never exercised.
 *
 * So: every case is now its own data-provider row, and exceptions are captured
 * through `CapturesThrowables`, which re-throws PHPUnit exceptions untouched.
 */
class SmsProviderContractTest extends TestCase
{
    use CapturesThrowables;
    use RefreshDatabase;

    private const PHONE = '+989121234567';

    private const API_KEY = 'SECRET-SMS-KEY-9f3a';

    private static function config(array $extra = []): array
    {
        return array_merge([
            'api_key' => self::API_KEY,
            'sender' => '10008663',
            'otp_ttl_minutes' => 5,
        ], $extra);
    }

    private static function provider(string $class, array $extra = []): AbstractSmsProvider
    {
        return new $class(self::config($extra));
    }

    // ── Body-level rejections, one row per case ────────────────────────────

    /** @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>}> */
    public static function rejectedMessageBodies(): array
    {
        return [
            'kavenegar invalid receptor' => [KavenegarSmsProvider::class, [], ['return' => ['status' => 411, 'message' => 'invalid receptor']]],
            'kavenegar out of credit' => [KavenegarSmsProvider::class, [], ['return' => ['status' => 418, 'message' => 'not enough credit']]],
            'kavenegar unapproved pattern' => [KavenegarSmsProvider::class, [], ['return' => ['status' => 424, 'message' => 'template not found']]],
            'sms.ir status zero' => [SmsIrProvider::class, [], ['status' => 0, 'message' => 'failed']],
            'sms.ir status false' => [SmsIrProvider::class, [], ['status' => false, 'message' => 'failed']],
            'sms.ir error code' => [SmsIrProvider::class, [], ['status' => 20, 'message' => 'insufficient credit']],
            'faraz error' => [FarazSmsProvider::class, [], ['status' => 'ERROR', 'code' => 401, 'message' => 'unauthorized']],
            'faraz lowercase error' => [FarazSmsProvider::class, [], ['status' => 'error', 'code' => 402]],
        ];
    }

    #[DataProvider('rejectedMessageBodies')]
    public function test_a_body_level_rejection_is_not_a_successful_send(string $class, array $extra, array $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);
        $provider = self::provider($class, $extra);

        $thrown = $this->captureException(
            \RuntimeException::class,
            fn () => $provider->sendMessage(self::PHONE, 'hello'),
            'an HTTP 200 carrying a rejection must not be reported as sent',
        );

        $this->assertStringContainsString('rejected the message', $thrown->getMessage());
    }

    /** @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>}> */
    public static function rejectedOtpBodies(): array
    {
        return [
            'kavenegar lookup' => [KavenegarSmsProvider::class, ['pattern_code' => 'verify'], ['return' => ['status' => 424, 'message' => 'template not found']]],
            'sms.ir verify' => [SmsIrProvider::class, ['pattern_code' => '100200'], ['status' => 0, 'message' => 'template invalid']],
            'faraz pattern' => [FarazSmsProvider::class, ['pattern_code' => 'abc123'], ['status' => 'ERROR', 'code' => 422]],
        ];
    }

    /**
     * The OTP paths use different endpoints (`verify/lookup`, `send/verify`,
     * `sms/pattern/normal/send`) than `sendMessage()`. A fix applied only to the
     * plain-message path would leave the path that actually delivers login codes
     * unguarded — and in the first version of this class, that path had no real
     * coverage at all.
     */
    #[DataProvider('rejectedOtpBodies')]
    public function test_a_body_level_rejection_fails_an_otp_send_too(string $class, array $extra, array $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);
        $provider = self::provider($class, $extra);

        $thrown = $this->captureException(
            \RuntimeException::class,
            fn () => $provider->sendOtp(self::PHONE, '123456'),
            'a rejected OTP send must not be reported as delivered',
        );

        $this->assertStringContainsString('rejected the message', $thrown->getMessage());
    }

    // ── Genuine acceptance still works ─────────────────────────────────────

    /** @return array<string,array{0:string,1:array<string,mixed>}> */
    public static function acceptedBodies(): array
    {
        return [
            'kavenegar 200' => [KavenegarSmsProvider::class, ['return' => ['status' => 200, 'message' => 'ok'], 'entries' => [['messageid' => 1]]]],
            'sms.ir status 1' => [SmsIrProvider::class, ['status' => 1, 'message' => 'ok', 'data' => ['messageId' => 7]]],
            'sms.ir status true' => [SmsIrProvider::class, ['status' => true, 'message' => 'ok']],
            'faraz OK' => [FarazSmsProvider::class, ['status' => 'OK', 'code' => 200, 'data' => ['message_id' => 5]]],
            'faraz ok lowercase' => [FarazSmsProvider::class, ['status' => 'ok', 'code' => 200]],
        ];
    }

    #[DataProvider('acceptedBodies')]
    public function test_a_genuine_acceptance_is_still_a_successful_send(string $class, array $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);
        $provider = self::provider($class);

        $this->assertTrue($this->assertDoesNotThrow(
            fn () => $provider->sendMessage(self::PHONE, 'hello'),
            'a documented acceptance envelope must still send',
        ));
    }

    /** @return array<string,array{0:string,1:array<string,mixed>}> */
    public static function unverifiableBodies(): array
    {
        return [
            'kavenegar without the return envelope' => [KavenegarSmsProvider::class, ['unexpected' => 'shape']],
            'kavenegar empty body' => [KavenegarSmsProvider::class, []],
            'kavenegar status of wrong type' => [KavenegarSmsProvider::class, ['return' => ['status' => ['200']]]],
            'sms.ir without a status field' => [SmsIrProvider::class, ['messageId' => 12345]],
            'sms.ir status of wrong type' => [SmsIrProvider::class, ['status' => ['1']]],
            'faraz with a non-string status' => [FarazSmsProvider::class, ['status' => 200]],
            'faraz empty body' => [FarazSmsProvider::class, []],
        ];
    }

    /**
     * Built-in adapters FAIL CLOSED.
     *
     * The previous version accepted an unrecognised body, on the reasoning that
     * failing closed on an unverified schema risked breaking a working
     * integration. That trade is wrong for these three: their envelopes ARE
     * documented, so a body without one is not "a shape we have not verified",
     * it is a response that cannot prove the message was accepted. Claiming
     * "OTP sent" on that basis is the exact failure this class exists to stop.
     */
    #[DataProvider('unverifiableBodies')]
    public function test_an_unverifiable_body_is_not_a_successful_send(string $class, array $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);
        $provider = self::provider($class);

        $thrown = $this->captureException(
            \RuntimeException::class,
            fn () => $provider->sendMessage(self::PHONE, 'hello'),
            'a response with no acceptance proof must not be reported as sent',
        );

        $this->assertStringContainsString('unverified response', $thrown->getMessage());
    }

    /**
     * Values a lossy `(int)` cast would have read as acceptance.
     *
     * Measured against the previous predicates: `1.5`, `'1.9'`, `'01'`, `'+1'`
     * and `'1e0'` all passed as SMS.ir success, and `200.9`, `'200abc'`,
     * `'200.4'` and `'2e2'` all passed as Kavenegar success.
     *
     * @return array<string,array{0:string,1:array<string,mixed>}>
     */
    public static function lossySuccessLookalikes(): array
    {
        return [
            'sms.ir 1.5' => [SmsIrProvider::class, ['status' => 1.5]],
            'sms.ir "1.9"' => [SmsIrProvider::class, ['status' => '1.9']],
            'sms.ir "01"' => [SmsIrProvider::class, ['status' => '01']],
            'sms.ir "+1"' => [SmsIrProvider::class, ['status' => '+1']],
            'sms.ir "1e0"' => [SmsIrProvider::class, ['status' => '1e0']],
            'sms.ir " 1"' => [SmsIrProvider::class, ['status' => ' 1']],
            'sms.ir "1abc"' => [SmsIrProvider::class, ['status' => '1abc']],
            'kavenegar 200.9' => [KavenegarSmsProvider::class, ['return' => ['status' => 200.9]]],
            'kavenegar "200abc"' => [KavenegarSmsProvider::class, ['return' => ['status' => '200abc']]],
            'kavenegar "200.4"' => [KavenegarSmsProvider::class, ['return' => ['status' => '200.4']]],
            'kavenegar "2e2"' => [KavenegarSmsProvider::class, ['return' => ['status' => '2e2']]],
            'kavenegar " 200"' => [KavenegarSmsProvider::class, ['return' => ['status' => ' 200']]],
            'kavenegar "+200"' => [KavenegarSmsProvider::class, ['return' => ['status' => '+200']]],
            'kavenegar true' => [KavenegarSmsProvider::class, ['return' => ['status' => true]]],
            'faraz "OKAY"' => [FarazSmsProvider::class, ['status' => 'OKAY']],
            'faraz " OK"' => [FarazSmsProvider::class, ['status' => ' OK']],
            'faraz true' => [FarazSmsProvider::class, ['status' => true]],
        ];
    }

    #[DataProvider('lossySuccessLookalikes')]
    public function test_a_success_lookalike_is_never_accepted(string $class, array $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);
        $provider = self::provider($class);

        $this->captureException(
            \RuntimeException::class,
            fn () => $provider->sendMessage(self::PHONE, 'hello'),
            'only an exact documented success status may be accepted',
        );
    }

    public function test_a_malformed_or_empty_body_is_not_a_successful_send(): void
    {
        foreach ([KavenegarSmsProvider::class, SmsIrProvider::class, FarazSmsProvider::class] as $class) {
            Http::fake(['*' => Http::response('<html>gateway down</html>', 200, ['Content-Type' => 'text/html'])]);
            $this->captureException(
                \RuntimeException::class,
                fn () => self::provider($class)->sendMessage(self::PHONE, 'hello'),
                $class.' must not treat HTML under HTTP 200 as a send',
            );

            Http::fake(['*' => Http::response('', 200)]);
            $this->captureException(
                \RuntimeException::class,
                fn () => self::provider($class)->sendMessage(self::PHONE, 'hello'),
                $class.' must not treat an empty body as a send',
            );
        }
    }

    // ── Transport-level failures ───────────────────────────────────────────

    /** @return array<string,array{0:string}> */
    public static function allProviders(): array
    {
        return [
            'kavenegar' => [KavenegarSmsProvider::class],
            'sms.ir' => [SmsIrProvider::class],
            'faraz' => [FarazSmsProvider::class],
        ];
    }

    #[DataProvider('allProviders')]
    public function test_a_server_error_status_fails_the_send(string $class): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);
        $provider = self::provider($class);

        $thrown = $this->captureException(
            \RuntimeException::class,
            fn () => $provider->sendMessage(self::PHONE, 'hello'),
        );

        $this->assertStringContainsString('HTTP 500', $thrown->getMessage());
    }

    #[DataProvider('allProviders')]
    public function test_a_client_error_status_fails_the_send(string $class): void
    {
        Http::fake(['*' => Http::response(['error' => 'nope'], 422)]);
        $provider = self::provider($class);

        $thrown = $this->captureException(
            \RuntimeException::class,
            fn () => $provider->sendMessage(self::PHONE, 'hello'),
        );

        $this->assertStringContainsString('HTTP 422', $thrown->getMessage());
    }

    #[DataProvider('allProviders')]
    public function test_a_connection_failure_propagates_as_a_throwable(string $class): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $provider = self::provider($class);

        $thrown = $this->captureThrowable(
            fn () => $provider->sendMessage(self::PHONE, 'hello'),
            'a transport failure must never look like a delivered message',
        );

        $this->assertInstanceOf(ConnectionException::class, $thrown);
        $this->assertStringNotContainsString(self::API_KEY, $thrown->getMessage());
    }

    private function customProvider(): CustomSmsProvider
    {
        return new CustomSmsProvider(self::config([
            'custom_url' => 'https://panel.example.test/send',
            'custom_body_template' => '{"to":"{phone}","text":"{message}"}',
        ]));
    }

    public function test_the_custom_provider_fails_on_an_http_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $thrown = $this->captureException(
            \RuntimeException::class,
            fn () => $this->customProvider()->sendMessage(self::PHONE, 'hi'),
        );

        $this->assertStringContainsString('HTTP 500', $thrown->getMessage());
    }

    public function test_the_custom_provider_accepts_any_body_on_http_success(): void
    {
        // No envelope exists by construction — the admin points this at an
        // arbitrary panel — so an HTTP 200 is accepted whatever the body says.
        // Applying another provider's schema here would be a guess about
        // somebody else's API.
        Http::fake(['*' => Http::response(['status' => 'ERROR'], 200)]);

        $this->assertTrue($this->assertDoesNotThrow(
            fn () => $this->customProvider()->sendMessage(self::PHONE, 'hi'),
        ));
    }

    // ── Rejections must not leak ───────────────────────────────────────────

    public function test_a_rejection_never_carries_the_panel_message_or_the_api_key(): void
    {
        Http::fake(['*' => Http::response([
            'return' => [
                'status' => 411,
                'message' => 'invalid receptor; api_key='.self::API_KEY
                    .'; url=https://api.kavenegar.com/v1/'.self::API_KEY.'/sms/send.json',
            ],
        ], 200)]);

        $thrown = $this->captureException(
            \RuntimeException::class,
            fn () => self::provider(KavenegarSmsProvider::class)->sendMessage(self::PHONE, 'hello'),
        );

        $message = $thrown->getMessage();

        $this->assertStringNotContainsString(self::API_KEY, $message);
        $this->assertStringNotContainsString('api.kavenegar.com', $message);
        $this->assertStringNotContainsString('invalid receptor', $message);
        $this->assertStringNotContainsString(self::PHONE, $message);
        $this->assertStringContainsString('411', $message, 'the status is what makes it diagnosable');
    }

    public function test_a_hostile_status_token_is_bounded_and_stripped(): void
    {
        Http::fake(['*' => Http::response([
            'return' => ['status' => "4\n11 production.ERROR: ".str_repeat('x', 500)],
        ], 200)]);

        $thrown = $this->captureException(
            \RuntimeException::class,
            fn () => self::provider(KavenegarSmsProvider::class)->sendMessage(self::PHONE, 'hello'),
        );

        $message = $thrown->getMessage();

        $this->assertLessThan(120, strlen($message), 'a provider must not choose the length of our log line');
        $this->assertStringNotContainsString("\n", $message, 'no forged log lines');
        $this->assertDoesNotMatchRegularExpression('/[^\PC]/u', $message, 'no control characters');
    }

    // ── Endpoints and payloads ─────────────────────────────────────────────

    /**
     * Every built-in endpoint, normal and OTP, asserted independently.
     *
     * The OTP paths use different endpoints from the plain-message paths, so a
     * fix or an endpoint change on one says nothing about the other.
     *
     * One URL-AWARE fake, not a per-iteration `Http::fake()`: stubs MERGE and
     * are not cleared by `Http::clearResolvedInstances()`, so a `'*'` stub
     * registered on the first pass answers every later one. An earlier version
     * of this test did exactly that and therefore only ever exercised
     * Kavenegar.
     */
    public function test_each_provider_calls_its_documented_endpoints(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'api.kavenegar.com') => Http::response(['return' => ['status' => 200]], 200),
                str_contains($url, 'api.sms.ir') => Http::response(['status' => 1], 200),
                str_contains($url, 'ippanel.com') => Http::response(['status' => 'OK'], 200),
                default => Http::response([], 404),
            };
        });

        self::provider(KavenegarSmsProvider::class)->sendMessage(self::PHONE, 'hello');
        self::provider(KavenegarSmsProvider::class, ['pattern_code' => 'verify'])->sendOtp(self::PHONE, '123456');
        self::provider(SmsIrProvider::class)->sendMessage(self::PHONE, 'hello');
        self::provider(SmsIrProvider::class, ['pattern_code' => '100200'])->sendOtp(self::PHONE, '123456');
        self::provider(FarazSmsProvider::class)->sendMessage(self::PHONE, 'hello');
        self::provider(FarazSmsProvider::class, ['pattern_code' => 'abc123'])->sendOtp(self::PHONE, '123456');

        foreach ([
            '/sms/send.json',
            '/verify/lookup.json',
            'api.sms.ir/v1/send/bulk',
            'api.sms.ir/v1/send/verify',
            '/sms/send/webservice/single',
            '/sms/pattern/normal/send',
        ] as $endpoint) {
            Http::assertSent(
                fn ($r) => str_contains($r->url(), $endpoint),
            );
        }

        // Exactly six calls: no endpoint silently answered another's request.
        Http::assertSentCount(6);
    }

    public function test_the_custom_provider_uses_its_configured_method_url_and_body(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new CustomSmsProvider(self::config([
            'custom_url' => 'https://panel.example.test/send',
            'custom_method' => 'POST',
            'custom_headers' => '{"X-Probe":"authz"}',
            'custom_body_template' => '{"to":"{phone}","text":"{message}"}',
        ])))->sendMessage(self::PHONE, 'hello');

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === 'https://panel.example.test/send'
                && $request->hasHeader('X-Probe', 'authz')
                && ($body['to'] ?? null) === '09121234567'
                && ($body['text'] ?? null) === 'hello';
        });
    }

    public function test_no_request_escapes_the_fake(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['return' => ['status' => 200]], 200)]);

        self::provider(KavenegarSmsProvider::class)->sendMessage(self::PHONE, 'hello');

        Http::assertSentCount(1);
    }

    // ── Through the service ────────────────────────────────────────────────

    private function configureKavenegar(): void
    {
        SiteSetting::set('sms_enabled', 'true');
        SiteSetting::set('sms_provider', 'kavenegar');
        SmsService::storeApiKey(self::API_KEY);
    }

    public function test_the_service_reports_a_rejected_send_as_failed(): void
    {
        $this->configureKavenegar();
        Http::fake(['*' => Http::response(['return' => ['status' => 418, 'message' => 'no credit']], 200)]);

        // The end-to-end property: a rejection must reach the CALLER as false,
        // so "we sent you a code" is never claimed for a refused message.
        $this->assertFalse(app(SmsService::class)->sendOtp(self::PHONE, '123456'));
    }

    public function test_the_service_still_reports_a_genuine_send_as_successful(): void
    {
        $this->configureKavenegar();
        Http::fake(['*' => Http::response(['return' => ['status' => 200, 'message' => 'ok']], 200)]);

        $this->assertTrue(app(SmsService::class)->sendOtp(self::PHONE, '123456'));
    }

    public function test_the_service_reports_an_smsir_rejection_as_failed(): void
    {
        // SmsIrProvider was the adapter that had NO body validation while its
        // tests passed. This is the end-to-end guard for that specific gap.
        SiteSetting::set('sms_enabled', 'true');
        SiteSetting::set('sms_provider', 'sms_ir');
        SmsService::storeApiKey(self::API_KEY);

        Http::fake(['*' => Http::response(['status' => 0, 'message' => 'failed'], 200)]);

        $this->assertFalse(app(SmsService::class)->sendOtp(self::PHONE, '123456'));
    }
}
