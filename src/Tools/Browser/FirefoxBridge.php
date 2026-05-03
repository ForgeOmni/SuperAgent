<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Browser;

/**
 * High-level RPC client for the Firefox Agent Bridge WebExtension.
 *
 * jcode bundles a Rust launcher + a Firefox WebExtension that together
 * expose first-class browser automation (navigate / click / screenshot /
 * eval) over Native Messaging. This class is the PHP-side companion:
 * it owns the child process lifecycle, assigns request IDs, dispatches
 * to the transport, and parses the response shape.
 *
 * **What ships here vs. what the host installs.**
 *
 * The PHP side (this file + `NativeMessagingTransport`) is fully
 * self-contained and depends on nothing the SDK doesn't already vendor.
 * To actually drive a browser the host must install three things, all
 * documented inline so the integration is reproducible:
 *
 *   1. **Firefox / a Chromium-based browser** with WebExtension support.
 *   2. **The Forgeomni Bridge WebExtension.** Minimal `manifest.json`:
 *      ```json
 *      {
 *        "manifest_version": 2,
 *        "name": "Forgeomni Bridge",
 *        "version": "1.0",
 *        "background": { "scripts": ["background.js"] },
 *        "permissions": ["nativeMessaging", "tabs", "<all_urls>", "activeTab"],
 *        "browser_specific_settings": { "gecko": { "id": "bridge@forgeomni" } }
 *      }
 *      ```
 *      `background.js` opens a `runtime.connectNative('forgeomni_bridge')`
 *      port and dispatches incoming messages to the right `tabs.*` /
 *      `webNavigation.*` API. ~150 LoC; ports cleanly from jcode's
 *      WebExtension since the wire shape is browser-defined.
 *   3. **Native Messaging manifest.** A small JSON file at the OS-defined
 *      path (see Mozilla docs for OS-specific locations):
 *      ```json
 *      {
 *        "name": "forgeomni_bridge",
 *        "description": "Forgeomni browser bridge launcher",
 *        "path": "/abs/path/to/forgeomni-bridge-launcher",
 *        "type": "stdio",
 *        "allowed_extensions": ["bridge@forgeomni"]
 *      }
 *      ```
 *      The "launcher" can be any executable that pipes
 *      length-prefixed JSON between Firefox and this PHP class — jcode's
 *      Rust binary works as-is, or write a 50-line Node / Go shim.
 *
 * **Wire shape.** Each request from PHP carries a numeric `id`, an
 * `action` string, and an `args` object. Each response carries the same
 * `id`, an `ok` flag, and either `result` (success) or `error` (failure).
 * The WebExtension is free to add unsolicited events with no `id`; this
 * class drops them by default but exposes `pollEvents()` for callers
 * that want to listen for navigation / DOM mutation notifications.
 */
final class FirefoxBridge
{
    private NativeMessagingTransport $transport;
    private int $nextRequestId = 1;
    /** @var list<array<string, mixed>>  buffered unsolicited events (no `id`) */
    private array $eventBuffer = [];

    public function __construct(NativeMessagingTransport $transport)
    {
        $this->transport = $transport;
    }

    /** Start the launcher; returns true on success. Idempotent. */
    public function start(): bool
    {
        return $this->transport->start();
    }

    public function stop(): void
    {
        $this->transport->stop();
    }

    public function isRunning(): bool
    {
        return $this->transport->isRunning();
    }

    /** Navigate the active tab; returns the new URL or null on failure. */
    public function navigate(string $url, ?int $tabId = null, int $timeoutMs = 30_000): ?string
    {
        $r = $this->call('navigate', ['url' => $url, 'tab_id' => $tabId], $timeoutMs);
        return is_array($r) ? ($r['url'] ?? null) : null;
    }

    /**
     * Take a PNG screenshot of the active tab; returns base64-encoded
     * data on success, null on failure.
     */
    public function screenshot(?int $tabId = null, ?array $options = null, int $timeoutMs = 15_000): ?string
    {
        $r = $this->call('screenshot', ['tab_id' => $tabId, 'options' => $options ?? new \stdClass()], $timeoutMs);
        return is_array($r) ? (is_string($r['data'] ?? null) ? $r['data'] : null) : null;
    }

    /** CSS-selector click. Returns true on success. */
    public function click(string $selector, ?int $tabId = null, int $timeoutMs = 5_000): bool
    {
        $r = $this->call('click', ['selector' => $selector, 'tab_id' => $tabId], $timeoutMs);
        return is_array($r) && ($r['clicked'] ?? false);
    }

    /** Type into a focused or selector-targeted input. */
    public function type(string $text, ?string $selector = null, ?int $tabId = null, int $timeoutMs = 5_000): bool
    {
        $r = $this->call('type', ['text' => $text, 'selector' => $selector, 'tab_id' => $tabId], $timeoutMs);
        return is_array($r) && ($r['typed'] ?? false);
    }

    /**
     * Evaluate JavaScript in the page context. Be cautious — the
     * WebExtension grants this full DOM access. Returns the JSON-coerced
     * result, or null on failure / non-JSON-serialisable result.
     */
    public function evalJs(string $expression, ?int $tabId = null, int $timeoutMs = 10_000): mixed
    {
        $r = $this->call('eval', ['code' => $expression, 'tab_id' => $tabId], $timeoutMs);
        return is_array($r) ? ($r['value'] ?? null) : null;
    }

    /** Wait for a CSS selector to appear / disappear. */
    public function wait(string $selector, string $state = 'visible', int $timeoutMs = 10_000): bool
    {
        $r = $this->call('wait', ['selector' => $selector, 'state' => $state, 'timeout_ms' => $timeoutMs], $timeoutMs + 2000);
        return is_array($r) && ($r['matched'] ?? false);
    }

    /** Drain unsolicited events emitted by the WebExtension. */
    public function pollEvents(): array
    {
        $events = $this->eventBuffer;
        $this->eventBuffer = [];
        return $events;
    }

    /**
     * Low-level RPC: send a request and read until the matching response.
     * Buffers any events seen along the way.
     *
     * @return array<string, mixed>|null  result body, or null on failure
     */
    private function call(string $action, array $args, int $timeoutMs): ?array
    {
        if (!$this->isRunning() && !$this->start()) return null;

        $id = $this->nextRequestId++;
        $sent = $this->transport->send([
            'id'     => $id,
            'action' => $action,
            'args'   => $args === [] ? new \stdClass() : $args,
        ]);
        if (!$sent) return null;

        $deadline = microtime(true) + ($timeoutMs / 1000.0);
        while (microtime(true) < $deadline) {
            $remaining = max(50, (int) (($deadline - microtime(true)) * 1000));
            $msg = $this->transport->recv($remaining);
            if ($msg === null) return null;

            // Unsolicited events have no id — buffer + keep waiting.
            if (!isset($msg['id'])) {
                $this->eventBuffer[] = $msg;
                continue;
            }
            if ((int) $msg['id'] !== $id) {
                // Out-of-order reply for an older request; ignore (caller
                // for that id has already given up). Continue polling for
                // the matching id.
                continue;
            }
            if (empty($msg['ok'])) return null;
            $result = $msg['result'] ?? [];
            return is_array($result) ? $result : ['value' => $result];
        }
        return null;
    }
}
