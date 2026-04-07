<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Providers\RetryMiddleware;

/**
 * Simple exception that carries HTTP headers for Retry-After testing.
 */
class HttpExceptionStub extends RuntimeException
{
    private array $headers;

    public function __construct(string $message, int $code, array $headers = [])
    {
        parent::__construct($message, $code);
        $this->headers = $headers;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}

/**
 * Exception stub that exposes getRetryAfter() directly.
 */
class RetryAfterExceptionStub extends RuntimeException
{
    public function __construct(string $message, int $code, private readonly float $retryAfter)
    {
        parent::__construct($message, $code);
    }

    public function getRetryAfter(): float
    {
        return $this->retryAfter;
    }
}

/**
 * Simulates a connection exception (class name contains "ConnectionException").
 */
class FakeConnectionException extends RuntimeException {}

/**
 * Simulates a timeout exception (class name contains "TimeoutException").
 */
class FakeTimeoutException extends RuntimeException {}

/**
 * Non-sleeping subclass of RetryMiddleware for fast tests.
 */
class TestableRetryMiddleware extends RetryMiddleware
{
    public array $sleepLog = [];

    protected function sleep(float $seconds): void
    {
        $this->sleepLog[] = $seconds;
        // No actual sleeping
    }
}

class RetryMiddlewareTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function middleware(int $maxRetries = 3, float $baseDelay = 1.0, float $maxDelay = 30.0): TestableRetryMiddleware
    {
        return new TestableRetryMiddleware($maxRetries, $baseDelay, $maxDelay);
    }

    // ------------------------------------------------------------------
    // execute() — success path
    // ------------------------------------------------------------------

    public function test_successful_call_returns_immediately(): void
    {
        $mw = $this->middleware();
        $result = $mw->execute(fn () => 'ok');

        $this->assertSame('ok', $result);
        $this->assertCount(0, $mw->getRetryLog());
        $this->assertCount(0, $mw->sleepLog);
    }

    public function test_successful_call_returns_complex_value(): void
    {
        $mw = $this->middleware();
        $data = ['choices' => [['message' => 'hello']]];
        $result = $mw->execute(fn () => $data);

        $this->assertSame($data, $result);
    }

    // ------------------------------------------------------------------
    // execute() — retry on transient / rate-limit errors
    // ------------------------------------------------------------------

    public function test_retries_on_429_then_succeeds(): void
    {
        $mw = $this->middleware();
        $calls = 0;
        $result = $mw->execute(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new RuntimeException('Rate limited', 429);
            }
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(3, $calls);
        $this->assertCount(2, $mw->getRetryLog());
    }

    public function test_retries_on_500(): void
    {
        $mw = $this->middleware(maxRetries: 2);
        $calls = 0;
        $result = $mw->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('Internal server error', 500);
            }
            return 'recovered';
        });

        $this->assertSame('recovered', $result);
        $this->assertCount(1, $mw->getRetryLog());
    }

    public function test_retries_on_502(): void
    {
        $mw = $this->middleware(maxRetries: 1);
        $calls = 0;
        $result = $mw->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('Bad gateway', 502);
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
    }

    public function test_retries_on_503(): void
    {
        $mw = $this->middleware(maxRetries: 1);
        $calls = 0;
        $result = $mw->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('Service unavailable', 503);
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
    }

    public function test_retries_on_529(): void
    {
        $mw = $this->middleware(maxRetries: 1);
        $calls = 0;
        $result = $mw->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('Overloaded', 529);
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
    }

    // ------------------------------------------------------------------
    // execute() — does NOT retry auth / logic errors
    // ------------------------------------------------------------------

    public function test_does_not_retry_401(): void
    {
        $mw = $this->middleware();
        $calls = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);

        $mw->execute(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('Unauthorized', 401);
        });

        $this->assertSame(1, $calls);
    }

    public function test_does_not_retry_403(): void
    {
        $mw = $this->middleware();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);

        $mw->execute(fn () => throw new RuntimeException('Forbidden', 403));
    }

    public function test_does_not_retry_invalid_argument_exception(): void
    {
        $mw = $this->middleware();

        $this->expectException(InvalidArgumentException::class);

        $mw->execute(fn () => throw new InvalidArgumentException('Bad input'));
    }

    public function test_does_not_retry_logic_exception(): void
    {
        $mw = $this->middleware();

        $this->expectException(LogicException::class);

        $mw->execute(fn () => throw new LogicException('Logic error'));
    }

    // ------------------------------------------------------------------
    // execute() — exhausts retries
    // ------------------------------------------------------------------

    public function test_exhausts_retries_and_throws_original(): void
    {
        $mw = $this->middleware(maxRetries: 2);

        try {
            $mw->execute(fn () => throw new RuntimeException('Always fails', 500));
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame('Always fails', $e->getMessage());
            $this->assertSame(500, $e->getCode());
            $this->assertCount(2, $mw->getRetryLog());
        }
    }

    // ------------------------------------------------------------------
    // execute() — connection / timeout exceptions
    // ------------------------------------------------------------------

    public function test_retries_connection_exception(): void
    {
        $mw = $this->middleware(maxRetries: 1);
        $calls = 0;
        $result = $mw->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new FakeConnectionException('Connection reset');
            }
            return 'reconnected';
        });

        $this->assertSame('reconnected', $result);
    }

    public function test_retries_timeout_exception(): void
    {
        $mw = $this->middleware(maxRetries: 1);
        $calls = 0;
        $result = $mw->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new FakeTimeoutException('Timed out');
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
    }

    // ------------------------------------------------------------------
    // shouldRetry()
    // ------------------------------------------------------------------

    public function test_should_retry_retryable_codes(): void
    {
        $mw = $this->middleware();

        foreach ([429, 500, 502, 503, 529] as $code) {
            $this->assertTrue(
                $mw->shouldRetry(new RuntimeException('err', $code), 0),
                "Expected code $code to be retryable"
            );
        }
    }

    public function test_should_not_retry_auth_codes(): void
    {
        $mw = $this->middleware();

        foreach ([401, 403] as $code) {
            $this->assertFalse(
                $mw->shouldRetry(new RuntimeException('err', $code), 0),
                "Expected code $code to NOT be retryable"
            );
        }
    }

    public function test_should_not_retry_logic_exceptions(): void
    {
        $mw = $this->middleware();

        $this->assertFalse($mw->shouldRetry(new InvalidArgumentException('bad'), 0));
        $this->assertFalse($mw->shouldRetry(new LogicException('nope'), 0));
    }

    // ------------------------------------------------------------------
    // calculateDelay()
    // ------------------------------------------------------------------

    public function test_exponential_backoff_attempt_0(): void
    {
        $mw = $this->middleware(baseDelay: 1.0, maxDelay: 30.0);
        $delay = $mw->calculateDelay(0);

        // base * 2^0 = 1.0, plus up to 25% jitter => [1.0, 1.25]
        $this->assertGreaterThanOrEqual(1.0, $delay);
        $this->assertLessThanOrEqual(1.25, $delay);
    }

    public function test_exponential_backoff_attempt_1(): void
    {
        $mw = $this->middleware(baseDelay: 1.0, maxDelay: 30.0);
        $delay = $mw->calculateDelay(1);

        // base * 2^1 = 2.0, plus up to 25% jitter => [2.0, 2.5]
        $this->assertGreaterThanOrEqual(2.0, $delay);
        $this->assertLessThanOrEqual(2.5, $delay);
    }

    public function test_exponential_backoff_attempt_2(): void
    {
        $mw = $this->middleware(baseDelay: 1.0, maxDelay: 30.0);
        $delay = $mw->calculateDelay(2);

        // base * 2^2 = 4.0, plus up to 25% jitter => [4.0, 5.0]
        $this->assertGreaterThanOrEqual(4.0, $delay);
        $this->assertLessThanOrEqual(5.0, $delay);
    }

    public function test_exponential_backoff_attempt_3(): void
    {
        $mw = $this->middleware(baseDelay: 1.0, maxDelay: 30.0);
        $delay = $mw->calculateDelay(3);

        // base * 2^3 = 8.0, plus up to 25% jitter => [8.0, 10.0]
        $this->assertGreaterThanOrEqual(8.0, $delay);
        $this->assertLessThanOrEqual(10.0, $delay);
    }

    public function test_max_delay_cap(): void
    {
        $mw = $this->middleware(baseDelay: 1.0, maxDelay: 5.0);
        $delay = $mw->calculateDelay(10); // 2^10 = 1024, capped at 5.0

        // Capped at 5.0, plus up to 25% jitter => [5.0, 6.25]
        $this->assertGreaterThanOrEqual(5.0, $delay);
        $this->assertLessThanOrEqual(6.25, $delay);
    }

    public function test_jitter_within_025_range(): void
    {
        $mw = $this->middleware(baseDelay: 10.0, maxDelay: 100.0);

        // Run multiple times to check jitter stays in range
        for ($i = 0; $i < 50; $i++) {
            $delay = $mw->calculateDelay(0);
            $this->assertGreaterThanOrEqual(10.0, $delay);
            $this->assertLessThanOrEqual(12.5, $delay); // 10 + 25%
        }
    }

    public function test_respects_retry_after_header(): void
    {
        $mw = $this->middleware(baseDelay: 1.0, maxDelay: 30.0);
        $exception = new HttpExceptionStub('Rate limited', 429, ['Retry-After' => '7']);
        $delay = $mw->calculateDelay(0, $exception);

        $this->assertSame(7.0, $delay);
    }

    public function test_respects_retry_after_method(): void
    {
        $mw = $this->middleware(baseDelay: 1.0, maxDelay: 30.0);
        $exception = new RetryAfterExceptionStub('Rate limited', 429, 12.5);
        $delay = $mw->calculateDelay(0, $exception);

        $this->assertSame(12.5, $delay);
    }

    public function test_retry_after_capped_by_max_delay(): void
    {
        $mw = $this->middleware(baseDelay: 1.0, maxDelay: 5.0);
        $exception = new HttpExceptionStub('Rate limited', 429, ['Retry-After' => '60']);
        $delay = $mw->calculateDelay(0, $exception);

        $this->assertSame(5.0, $delay);
    }

    // ------------------------------------------------------------------
    // classifyError()
    // ------------------------------------------------------------------

    public function test_classify_auth_errors(): void
    {
        $mw = $this->middleware();

        $this->assertSame('auth', $mw->classifyError(new RuntimeException('Unauthorized', 401)));
        $this->assertSame('auth', $mw->classifyError(new RuntimeException('Forbidden', 403)));
    }

    public function test_classify_rate_limit(): void
    {
        $mw = $this->middleware();

        $this->assertSame('rate_limit', $mw->classifyError(new RuntimeException('Too many', 429)));
        $this->assertSame('rate_limit', $mw->classifyError(new RuntimeException('Overloaded', 529)));
    }

    public function test_classify_transient(): void
    {
        $mw = $this->middleware();

        $this->assertSame('transient', $mw->classifyError(new RuntimeException('ISE', 500)));
        $this->assertSame('transient', $mw->classifyError(new RuntimeException('Bad gw', 502)));
        $this->assertSame('transient', $mw->classifyError(new RuntimeException('Unavail', 503)));
    }

    public function test_classify_connection_exception_as_transient(): void
    {
        $mw = $this->middleware();
        $this->assertSame('transient', $mw->classifyError(new FakeConnectionException('reset')));
    }

    public function test_classify_timeout_exception_as_transient(): void
    {
        $mw = $this->middleware();
        $this->assertSame('transient', $mw->classifyError(new FakeTimeoutException('timed out')));
    }

    public function test_classify_unrecoverable(): void
    {
        $mw = $this->middleware();

        $this->assertSame('unrecoverable', $mw->classifyError(new InvalidArgumentException('bad')));
        $this->assertSame('unrecoverable', $mw->classifyError(new LogicException('logic')));
        $this->assertSame('unrecoverable', $mw->classifyError(new RuntimeException('unknown', 0)));
    }

    // ------------------------------------------------------------------
    // Retry log
    // ------------------------------------------------------------------

    public function test_retry_log_tracks_attempts(): void
    {
        $mw = $this->middleware(maxRetries: 3);
        $calls = 0;
        $mw->execute(function () use (&$calls) {
            $calls++;
            if ($calls <= 2) {
                throw new RuntimeException('Server error', 500);
            }
            return 'ok';
        });

        $log = $mw->getRetryLog();
        $this->assertCount(2, $log);

        $this->assertSame(1, $log[0]['attempt']);
        $this->assertSame('Server error', $log[0]['error']);
        $this->assertSame(500, $log[0]['code']);
        $this->assertSame('transient', $log[0]['classification']);
        $this->assertIsFloat($log[0]['delay']);

        $this->assertSame(2, $log[1]['attempt']);
    }

    public function test_retry_log_resets_on_each_execute(): void
    {
        $mw = $this->middleware(maxRetries: 1);

        // First call with a retry
        $calls = 0;
        $mw->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('err', 500);
            }
            return 'ok';
        });
        $this->assertCount(1, $mw->getRetryLog());

        // Second call succeeds immediately — log should reset
        $mw->execute(fn () => 'ok');
        $this->assertCount(0, $mw->getRetryLog());
    }

    // ------------------------------------------------------------------
    // wrap() factory
    // ------------------------------------------------------------------

    public function test_wrap_factory_creates_instance(): void
    {
        // Use an anonymous class implementing LLMProvider interface (or duck type)
        $provider = $this->createMock(LLMProvider::class);
        $mw = TestableRetryMiddleware::wrap($provider, [
            'maxRetries' => 5,
            'baseDelay' => 0.5,
            'maxDelay' => 10.0,
        ]);

        $this->assertInstanceOf(RetryMiddleware::class, $mw);
        $this->assertSame($provider, $mw->getProvider());
    }

    public function test_wrap_factory_uses_defaults(): void
    {
        $provider = $this->createMock(LLMProvider::class);
        $mw = RetryMiddleware::wrap($provider);

        $this->assertInstanceOf(RetryMiddleware::class, $mw);
        $this->assertSame($provider, $mw->getProvider());
    }

    public function test_execute_passes_provider_to_callable(): void
    {
        $provider = $this->createMock(LLMProvider::class);
        $mw = TestableRetryMiddleware::wrap($provider);

        $received = null;
        $mw->execute(function ($p) use (&$received) {
            $received = $p;
            return 'ok';
        });

        $this->assertSame($provider, $received);
    }

    // ------------------------------------------------------------------
    // Sleep log (verify delay is applied)
    // ------------------------------------------------------------------

    public function test_sleep_is_called_between_retries(): void
    {
        $mw = $this->middleware(maxRetries: 2, baseDelay: 1.0, maxDelay: 30.0);
        $calls = 0;

        $mw->execute(function () use (&$calls) {
            $calls++;
            if ($calls <= 2) {
                throw new RuntimeException('err', 500);
            }
            return 'ok';
        });

        $this->assertCount(2, $mw->sleepLog);
        // First sleep: ~1.0-1.25, second: ~2.0-2.5
        $this->assertGreaterThanOrEqual(1.0, $mw->sleepLog[0]);
        $this->assertGreaterThanOrEqual(2.0, $mw->sleepLog[1]);
    }
}

