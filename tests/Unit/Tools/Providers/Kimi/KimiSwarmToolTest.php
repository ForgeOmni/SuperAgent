<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\Kimi;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Tools\Providers\Kimi\KimiSwarmTool;

class KimiSwarmToolTest extends TestCase
{
    public function test_rejects_empty_prompt(): void
    {
        $tool = new KimiSwarmTool(new KimiProvider(['api_key' => 'k']));
        $this->assertTrue($tool->execute([])->isError);
        $this->assertTrue($tool->execute(['prompt' => '  '])->isError);
    }

    public function test_submitted_mode_skips_polling(): void
    {
        $history = [];
        $tool = $this->withMock([
            new Response(200, [], json_encode(['id' => 'swarm_1'])),
        ], $history);

        $result = $tool->execute(['prompt' => 'task', 'wait' => false]);

        $this->assertFalse($result->isError);
        $this->assertSame('swarm_1', $result->content['job_id']);
        $this->assertSame('submitted', $result->content['status']);
        $this->assertCount(1, $history);  // no poll, no fetch
    }

    public function test_wait_mode_polls_and_fetches(): void
    {
        $history = [];
        $tool = $this->withMock([
            new Response(200, [], json_encode(['id' => 'swarm_1'])),        // submit
            new Response(200, [], json_encode(['status' => 'completed'])),  // poll -> done
            new Response(200, [], json_encode(['result' => 'DONE'])),       // fetch
        ], $history);

        $result = $tool->execute(['prompt' => 'task']);

        $this->assertFalse($result->isError);
        $this->assertSame('swarm_1', $result->content['job_id']);
        $this->assertSame('completed', $result->content['status']);
        $this->assertSame('DONE', $result->content['deliverable']);
    }

    public function test_attributes_are_network_and_cost(): void
    {
        $tool = new KimiSwarmTool(new KimiProvider(['api_key' => 'k']));
        $this->assertContains('network', $tool->attributes());
        $this->assertContains('cost', $tool->attributes());
        $this->assertTrue($tool->isReadOnly());
    }

    private function withMock(array $responses, array &$history): KimiSwarmTool
    {
        $p = new KimiProvider(['api_key' => 'sk-test']);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.moonshot.ai/',
        ]);
        $ref = new \ReflectionObject($p);
        while ($ref && ! $ref->hasProperty('client')) {
            $ref = $ref->getParentClass();
        }
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($p, $client);

        return new KimiSwarmTool($p);
    }
}
