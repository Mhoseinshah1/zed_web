<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Exception as PhpUnitException;
use Throwable;

/**
 * Capture an expected exception WITHOUT ever swallowing PHPUnit's own.
 *
 * ── Why this exists ───────────────────────────────────────────────────────
 *
 * `PHPUnit\Framework\AssertionFailedError` extends `PHPUnit\Framework\Exception`,
 * which extends `\RuntimeException`. Verified on PHPUnit 12.5.30:
 *
 *     AssertionFailedError → PHPUnit\Framework\Exception → RuntimeException
 *
 * So the very common shape
 *
 *     try {
 *         $subject->doThing();
 *         $this->fail('should have thrown');
 *     } catch (\RuntimeException $e) {
 *         $this->assertStringContainsString('rejected', $e->getMessage());
 *     }
 *
 * is a TRAP. When `doThing()` does not throw, `fail()` raises
 * `AssertionFailedError`, the `catch (\RuntimeException)` swallows it, and the
 * assertion then runs against the test's OWN failure message. If that message
 * happens to contain the expected substring — which it very often does, because
 * both describe the same thing — the test passes while the production code is
 * completely broken.
 *
 * That is not theoretical. It hid a real defect in this repository:
 * `SmsIrProvider` was never given body-level validation at all, and the test
 * that existed to prove SMS.ir rejections were caught passed anyway, because
 * its own case label contained the word "rejected".
 *
 * ── The rule ──────────────────────────────────────────────────────────────
 *
 * PHPUnit exceptions are re-thrown untouched, so a missing throw always fails
 * the test. Assertions on the captured exception belong AFTER the call, never
 * inside a catch block.
 */
trait CapturesThrowables
{
    /**
     * Run $body and return the Throwable it threw.
     *
     * Fails the test if nothing was thrown. Never captures a PHPUnit exception
     * — those propagate, so an assertion failure inside $body stays a failure.
     */
    protected function captureThrowable(callable $body, string $because = ''): Throwable
    {
        try {
            $body();
        } catch (PhpUnitException $phpunit) {
            throw $phpunit; // our own failure — must never be mistaken for the subject's
        } catch (Throwable $thrown) {
            return $thrown;
        }

        Assert::fail(
            'expected a Throwable, but the call returned normally'
            .($because !== '' ? ' — '.$because : ''),
        );
    }

    /**
     * Assert that $body throws $expected, and return it for further assertions.
     *
     * @template T of Throwable
     *
     * @param  class-string<T>  $expected
     * @return T
     */
    protected function captureException(string $expected, callable $body, string $because = ''): Throwable
    {
        $thrown = $this->captureThrowable($body, $because);

        Assert::assertInstanceOf(
            $expected,
            $thrown,
            ($because !== '' ? $because.' — ' : '').'wrong exception type thrown',
        );

        return $thrown;
    }

    /** Assert that $body does NOT throw, surfacing the real error when it does. */
    protected function assertDoesNotThrow(callable $body, string $because = ''): mixed
    {
        try {
            return $body();
        } catch (PhpUnitException $phpunit) {
            throw $phpunit;
        } catch (Throwable $thrown) {
            Assert::fail(
                ($because !== '' ? $because.' — ' : '')
                .'unexpected '.$thrown::class.': '.$thrown->getMessage(),
            );
        }
    }
}
