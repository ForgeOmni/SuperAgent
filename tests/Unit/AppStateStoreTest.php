<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\State\AppState;
use SuperAgent\State\AppStateStore;

class AppStateStoreTest extends TestCase
{
    // ── AppState ─────────────────────────────────────────────────

    public function testAppStateDefaultValues(): void
    {
        $state = new AppState();

        $this->assertSame('', $state->model);
        $this->assertSame('default', $state->permissionMode);
        $this->assertSame('', $state->provider);
        $this->assertSame('', $state->cwd);
        $this->assertNull($state->theme);
        $this->assertFalse($state->fastMode);
        $this->assertSame(0, $state->turnCount);
        $this->assertSame(0.0, $state->totalCostUsd);
        $this->assertSame(0, $state->tokenCount);
        $this->assertSame([], $state->activeAgents);
        $this->assertSame([], $state->mcpServers);
        $this->assertNull($state->sessionId);
    }

    public function testAppStateWithReturnsNewInstance(): void
    {
        $original = new AppState(model: 'opus', turnCount: 5);
        $updated = $original->with(['turnCount' => 6]);

        $this->assertSame(5, $original->turnCount);
        $this->assertSame(6, $updated->turnCount);
        $this->assertSame('opus', $updated->model);
        $this->assertNotSame($original, $updated);
    }

    public function testAppStateWithMultipleFields(): void
    {
        $state = new AppState();
        $next = $state->with([
            'model' => 'sonnet',
            'provider' => 'anthropic',
            'fastMode' => true,
            'totalCostUsd' => 1.23,
        ]);

        $this->assertSame('sonnet', $next->model);
        $this->assertSame('anthropic', $next->provider);
        $this->assertTrue($next->fastMode);
        $this->assertSame(1.23, $next->totalCostUsd);
    }

    public function testAppStateToArrayContainsAllProperties(): void
    {
        $state = new AppState(
            model: 'test-model',
            permissionMode: 'allowAll',
            provider: 'openai',
            cwd: '/tmp',
            theme: 'dark',
            fastMode: true,
            turnCount: 10,
            totalCostUsd: 5.50,
            tokenCount: 42000,
            activeAgents: ['agent-1'],
            mcpServers: ['srv'],
            sessionId: 'sess-abc',
        );

        $arr = $state->toArray();

        $this->assertSame('test-model', $arr['model']);
        $this->assertSame('allowAll', $arr['permissionMode']);
        $this->assertSame('openai', $arr['provider']);
        $this->assertSame('/tmp', $arr['cwd']);
        $this->assertSame('dark', $arr['theme']);
        $this->assertTrue($arr['fastMode']);
        $this->assertSame(10, $arr['turnCount']);
        $this->assertSame(5.50, $arr['totalCostUsd']);
        $this->assertSame(42000, $arr['tokenCount']);
        $this->assertSame(['agent-1'], $arr['activeAgents']);
        $this->assertSame(['srv'], $arr['mcpServers']);
        $this->assertSame('sess-abc', $arr['sessionId']);
    }

    public function testAppStateImmutability(): void
    {
        $state = new AppState(turnCount: 1);
        $state->with(['turnCount' => 99]);

        // Original should be unchanged
        $this->assertSame(1, $state->turnCount);
    }

    // ── AppStateStore ────────────────────────────────────────────

    public function testStoreDefaultState(): void
    {
        $store = new AppStateStore();
        $state = $store->get();

        $this->assertInstanceOf(AppState::class, $state);
        $this->assertSame('', $state->model);
    }

    public function testStoreCustomInitialState(): void
    {
        $initial = new AppState(model: 'haiku', turnCount: 3);
        $store = new AppStateStore($initial);

        $this->assertSame('haiku', $store->get()->model);
        $this->assertSame(3, $store->get()->turnCount);
    }

    public function testStoreSetUpdatesState(): void
    {
        $store = new AppStateStore();
        $store->set(['model' => 'opus', 'turnCount' => 1]);

        $this->assertSame('opus', $store->get()->model);
        $this->assertSame(1, $store->get()->turnCount);
    }

    public function testStoreSetPreservesUnchangedFields(): void
    {
        $store = new AppStateStore(new AppState(model: 'sonnet', provider: 'anthropic'));
        $store->set(['turnCount' => 5]);

        $this->assertSame('sonnet', $store->get()->model);
        $this->assertSame('anthropic', $store->get()->provider);
        $this->assertSame(5, $store->get()->turnCount);
    }

    public function testSubscribeReceivesNotifications(): void
    {
        $store = new AppStateStore();
        $received = [];

        $store->subscribe(function (AppState $state) use (&$received) {
            $received[] = $state;
        });

        $store->set(['model' => 'opus']);
        $store->set(['turnCount' => 42]);

        $this->assertCount(2, $received);
        $this->assertSame('opus', $received[0]->model);
        $this->assertSame(42, $received[1]->turnCount);
    }

    public function testUnsubscribeStopsNotifications(): void
    {
        $store = new AppStateStore();
        $callCount = 0;

        $unsub = $store->subscribe(function () use (&$callCount) {
            $callCount++;
        });

        $store->set(['turnCount' => 1]);
        $this->assertSame(1, $callCount);

        $unsub();
        $store->set(['turnCount' => 2]);
        $this->assertSame(1, $callCount); // No additional call
    }

    public function testGetListenerCount(): void
    {
        $store = new AppStateStore();
        $this->assertSame(0, $store->getListenerCount());

        $unsub1 = $store->subscribe(function () {});
        $this->assertSame(1, $store->getListenerCount());

        $unsub2 = $store->subscribe(function () {});
        $this->assertSame(2, $store->getListenerCount());

        $unsub1();
        $this->assertSame(1, $store->getListenerCount());

        $unsub2();
        $this->assertSame(0, $store->getListenerCount());
    }

    public function testMultipleListenersAllNotified(): void
    {
        $store = new AppStateStore();
        $a = 0;
        $b = 0;

        $store->subscribe(function () use (&$a) { $a++; });
        $store->subscribe(function () use (&$b) { $b++; });

        $store->set(['turnCount' => 1]);

        $this->assertSame(1, $a);
        $this->assertSame(1, $b);
    }

    public function testListenerReceivesLatestState(): void
    {
        $store = new AppStateStore();
        $lastState = null;

        $store->subscribe(function (AppState $state) use (&$lastState) {
            $lastState = $state;
        });

        $store->set(['model' => 'a']);
        $store->set(['model' => 'b']);
        $store->set(['model' => 'c']);

        $this->assertNotNull($lastState);
        $this->assertSame('c', $lastState->model);
    }

    public function testSetCreatesNewStateObject(): void
    {
        $store = new AppStateStore();
        $before = $store->get();

        $store->set(['turnCount' => 999]);
        $after = $store->get();

        $this->assertNotSame($before, $after);
        $this->assertSame(0, $before->turnCount);
        $this->assertSame(999, $after->turnCount);
    }
}
