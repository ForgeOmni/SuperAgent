<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\Capabilities\SupportsSwarm;
use SuperAgent\Providers\JobHandle;
use SuperAgent\Providers\JobStatus;
use SuperAgent\Providers\KimiProvider;

/**
 * Structural + wire contract tests for the provisional Kimi Swarm REST
 * surface. The upstream REST spec is not publicly published yet — these
 * tests lock in the SuperAgent-side architecture so when the spec does
 * land, swapping the placeholders in `KimiProvider` is a point change.
 */
class KimiSwarmCapabilityTest extends TestCase
{
    public function test_kimi_provider_implements_supports_swarm(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-test']);
        $this->assertInstanceOf(SupportsSwarm::class, $p);
    }

    public function test_submit_poll_fetch_cancel_wire_shape(): void
    {
        $history = [];
        $p = $this->providerWithMock([
            new Response(200, [], json_encode(['id' => 'swarm_job_1'])),    // submit
            new Response(200, [], json_encode(['status' => 'running'])),    // poll -> running
            new Response(200, [], json_encode([                             // poll -> done
                'status' => 'completed',
                'result' => ['deliverable' => 'report.md'],
            ])),
            new Response(200, [], json_encode([                             // fetch
                'result' => ['deliverable' => 'report.md'],
            ])),
            new Response(200, [], ''),                                      // cancel
        ], $history);

        $handle = $p->submitSwarm('plan a trip', [
            'max_sub_agents' => 5,
            'deliverable' => 'markdown',
        ]);

        $this->assertSame('kimi', $handle->provider);
        $this->assertSame('swarm_job_1', $handle->jobId);
        $this->assertSame('swarm', $handle->kind);

        $this->assertSame(JobStatus::Running, $p->poll($handle));
        $this->assertSame(JobStatus::Done, $p->poll($handle));

        $fetched = $p->fetch($handle);
        $this->assertSame(['deliverable' => 'report.md'], $fetched);

        $this->assertTrue($p->cancel($handle));

        // Wire inspection — submit body had the requested opts.
        $submitReq = $history[0]['request'];
        $this->assertStringEndsWith('v1/swarm/jobs', $submitReq->getUri()->getPath());
        $body = json_decode((string) $submitReq->getBody(), true);
        $this->assertSame('plan a trip', $body['prompt']);
        $this->assertSame(5, $body['max_sub_agents']);
        $this->assertSame('markdown', $body['deliverable']);

        // Poll URL carries the job id.
        $this->assertStringContainsString('swarm_job_1', $history[1]['request']->getUri()->getPath());

        // Cancel hits the cancel sub-route.
        $this->assertStringEndsWith('v1/swarm/jobs/swarm_job_1/cancel', $history[4]['request']->getUri()->getPath());
    }

    public function test_submit_without_id_throws(): void
    {
        $p = $this->providerWithMock([
            new Response(200, [], json_encode(['status' => 'queued'])),  // no id
        ]);
        $this->expectException(\SuperAgent\Exceptions\ProviderException::class);
        $p->submitSwarm('x');
    }

    public function test_cancel_swallows_upstream_errors(): void
    {
        $p = $this->providerWithMock([
            new Response(500, [], 'upstream went away'),
        ]);
        $handle = new JobHandle('kimi', 'abc', 'swarm', time());
        $this->assertFalse($p->cancel($handle));
    }

    /**
     * @param array<int, Response>              $responses
     * @param array<int, array<string, mixed>> &$history
     */
    private function providerWithMock(array $responses, array &$history = null): KimiProvider
    {
        if ($history === null) {
            $sink = [];
            $history = &$sink;
        }
        $p = new KimiProvider(['api_key' => 'sk-test']);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.moonshot.ai/',
            'http_errors' => true,
        ]);
        $ref = new \ReflectionObject($p);
        while ($ref && ! $ref->hasProperty('client')) {
            $ref = $ref->getParentClass();
        }
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($p, $client);
        return $p;
    }
}
