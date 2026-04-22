<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * Shared test plumbing for provider / tool tests that need to drive a
 * real provider instance with mocked HTTP responses.
 *
 * Before this helper existed, every test file hand-rolled ~25 lines of
 * MockHandler + HandlerStack + Middleware::history + ReflectionObject
 * to swap the provider's Guzzle client. The boilerplate was correct but
 * error-prone — in particular PHP's reference semantics tripped several
 * tests during Phases 4 / 7 (the `&$history` in a returned array tuple
 * silently broke the binding). This helper encapsulates the right
 * pattern: `$history` passed **by reference** into the setup call, so
 * the Guzzle middleware writes to the caller's variable directly.
 *
 * Usage:
 *   $history = [];
 *   $provider = new KimiProvider(['api_key' => 'k']);
 *   ProviderMockHelper::injectMockClient($provider, [
 *       new Response(200, [], json_encode([...])),
 *   ], $history, 'https://api.moonshot.ai/');
 *
 *   // exercise the provider / tool — `$history` fills in with
 *   // `['request' => RequestInterface, 'response' => ResponseInterface, ...]`
 */
final class ProviderMockHelper
{
    /**
     * Swap `$provider`'s internal Guzzle `$client` for one backed by the
     * given mock responses, recording every outgoing request into
     * `$history` (by reference).
     *
     * @param object                           $provider   Any provider with a `client` property
     *                                                     (walks up the inheritance chain).
     * @param array<int, \Psr\Http\Message\ResponseInterface|\Throwable> $responses
     * @param array<int, array<string, mixed>>&$history    Captured by reference.
     * @param string                           $baseUri    Must match the real provider's base URI
     *                                                     so relative request paths resolve identically.
     */
    public static function injectMockClient(
        object $provider,
        array $responses,
        array &$history,
        string $baseUri,
    ): Client {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $stack,
            'base_uri' => $baseUri,
        ]);

        $ref = new \ReflectionObject($provider);
        while ($ref && ! $ref->hasProperty('client')) {
            $ref = $ref->getParentClass();
        }
        if (! $ref) {
            throw new \LogicException(
                get_class($provider) . ' has no $client property to mock',
            );
        }
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($provider, $client);

        return $client;
    }
}
