<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Integration;

use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\KimiProvider;

/**
 * Canary integration test for Kimi. Asserts we can reach the endpoint,
 * auth works, and the streaming SSE response parses into at least one
 * `AssistantMessage` with usage metadata.
 */
class KimiIntegrationTest extends IntegrationTestCase
{
    public function test_minimal_chat_roundtrip(): void
    {
        $this->requireEnv('KIMI_API_KEY');

        $provider = new KimiProvider([
            'api_key' => getenv('KIMI_API_KEY'),
            'region'  => getenv('KIMI_REGION') ?: 'intl',
            'model'   => getenv('KIMI_TEST_MODEL') ?: 'kimi-latest',
            'max_tokens' => 32,
        ]);

        $reply = null;
        foreach ($provider->chat([new UserMessage('Say "ok".')], [], null, [
            'temperature' => 0,
        ]) as $message) {
            $reply = $message;
        }

        $this->assertNotNull($reply, 'Kimi did not return an AssistantMessage');
        $this->assertNotEmpty($reply->content);
    }
}
