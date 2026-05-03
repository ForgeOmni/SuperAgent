<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Browser\FirefoxBridge;
use SuperAgent\Tools\Browser\NativeMessagingTransport;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * `browser` tool — drives a Firefox (or Chromium-based) instance through
 * the Forgeomni Native Messaging bridge. Borrowed in spirit from jcode's
 * Firefox Agent Bridge.
 *
 * **Setup.** See `FirefoxBridge` class docblock for the manifest layout
 * and WebExtension scaffold. Once the launcher is on disk and the env
 * var `SUPERAGENT_BROWSER_BRIDGE_PATH` points at it, the tool is
 * immediately usable. Without setup, every action returns an explanatory
 * error so the agent learns to ask for setup help instead of looping.
 *
 * **Single-bridge per tool instance.** The first action starts the
 * launcher; subsequent actions reuse the same process. Call
 * `action: 'close'` to cleanly tear it down (the destructor also stops
 * the process if the tool goes out of scope).
 *
 * **Capability surface intentionally tight.** Just navigate / screenshot
 * / click / type / eval / wait. No tab management, cookies, or extension
 * APIs — those expand the abuse surface meaningfully and aren't needed
 * for the typical "use the page like a human would" workload. Hosts
 * that need more wire it directly via `FirefoxBridge::evalJs()`.
 */
final class FirefoxBridgeTool extends Tool
{
    private ?FirefoxBridge $bridge = null;

    public function __construct(
        /** @var list<string>|null  override the launcher argv (otherwise pulled from env) */
        private readonly ?array $launcherArgv = null,
    ) {}

    public function name(): string
    {
        return 'browser';
    }

    public function description(): string
    {
        return 'Drive a Firefox / Chromium browser via the Forgeomni Native Messaging bridge. Actions: navigate, screenshot, click, type, eval, wait, close. Use for tasks that require a real DOM (interactive web apps, JS-rendered pages, captcha-aware logins).';
    }

    public function category(): string
    {
        return 'browser';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['navigate', 'screenshot', 'click', 'type', 'eval', 'wait', 'close'],
                    'description' => 'Operation to perform on the active tab.',
                ],
                'url'        => ['type' => 'string', 'description' => 'For action=navigate.'],
                'selector'   => ['type' => 'string', 'description' => 'For action=click | type | wait — CSS selector.'],
                'text'       => ['type' => 'string', 'description' => 'For action=type — text to type.'],
                'code'       => ['type' => 'string', 'description' => 'For action=eval — JavaScript expression to evaluate in the page.'],
                'state'      => ['type' => 'string', 'enum' => ['visible', 'hidden', 'present'], 'description' => 'For action=wait. Default visible.'],
                'tab_id'     => ['type' => 'integer', 'description' => 'Optional explicit tab id; default is active tab.'],
                'timeout_ms' => ['type' => 'integer', 'description' => 'Override the per-action timeout (ms).'],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? '');
        if ($action === '') return ToolResult::error('action is required.');

        if ($action === 'close') {
            $this->bridge?->stop();
            $this->bridge = null;
            return ToolResult::success(['closed' => true]);
        }

        $bridge = $this->ensureBridge();
        if ($bridge === null) {
            return ToolResult::error(
                'Browser bridge is not configured. Set SUPERAGENT_BROWSER_BRIDGE_PATH '
                . 'to the absolute path of the Native Messaging launcher (or pass '
                . '`launcherArgv` to FirefoxBridgeTool::__construct). See the '
                . 'FirefoxBridge class docblock for the manifest + WebExtension setup.'
            );
        }
        if (!$bridge->start()) {
            return ToolResult::error('Failed to start the browser bridge launcher process.');
        }

        $tabId = isset($input['tab_id']) ? (int) $input['tab_id'] : null;
        $timeoutMs = isset($input['timeout_ms']) ? max(100, (int) $input['timeout_ms']) : 15_000;

        switch ($action) {
            case 'navigate':
                $url = (string) ($input['url'] ?? '');
                if ($url === '') return ToolResult::error('url is required for action=navigate.');
                $newUrl = $bridge->navigate($url, $tabId, $timeoutMs);
                if ($newUrl === null) return ToolResult::error('Navigate failed.');
                return ToolResult::success(['url' => $newUrl]);

            case 'screenshot':
                $data = $bridge->screenshot($tabId, null, $timeoutMs);
                if ($data === null) return ToolResult::error('Screenshot failed.');
                return ToolResult::success(['format' => 'png', 'base64' => $data, 'bytes' => (int) (strlen($data) * 3 / 4)]);

            case 'click':
                $sel = (string) ($input['selector'] ?? '');
                if ($sel === '') return ToolResult::error('selector is required for action=click.');
                return $bridge->click($sel, $tabId, $timeoutMs)
                    ? ToolResult::success(['clicked' => true])
                    : ToolResult::error('Click failed (selector not found or not clickable).');

            case 'type':
                $text = (string) ($input['text'] ?? '');
                $sel  = isset($input['selector']) ? (string) $input['selector'] : null;
                return $bridge->type($text, $sel, $tabId, $timeoutMs)
                    ? ToolResult::success(['typed' => true])
                    : ToolResult::error('Type failed.');

            case 'eval':
                $code = (string) ($input['code'] ?? '');
                if ($code === '') return ToolResult::error('code is required for action=eval.');
                $value = $bridge->evalJs($code, $tabId, $timeoutMs);
                return ToolResult::success(['value' => $value]);

            case 'wait':
                $sel = (string) ($input['selector'] ?? '');
                $state = (string) ($input['state'] ?? 'visible');
                if ($sel === '') return ToolResult::error('selector is required for action=wait.');
                return $bridge->wait($sel, $state, $timeoutMs)
                    ? ToolResult::success(['matched' => true])
                    : ToolResult::error('Wait timed out.');

            default:
                return ToolResult::error("Unknown action: {$action}");
        }
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function __destruct()
    {
        $this->bridge?->stop();
    }

    private function ensureBridge(): ?FirefoxBridge
    {
        if ($this->bridge !== null) return $this->bridge;
        $argv = $this->launcherArgv ?? $this->discoverLauncherArgv();
        if ($argv === null || $argv === []) return null;
        $this->bridge = new FirefoxBridge(new NativeMessagingTransport($argv));
        return $this->bridge;
    }

    /** @return list<string>|null */
    private function discoverLauncherArgv(): ?array
    {
        $env = getenv('SUPERAGENT_BROWSER_BRIDGE_PATH');
        if (!is_string($env) || $env === '') return null;
        // Allow the env var to carry extra args separated by spaces.
        $parts = preg_split('/\s+/', trim($env)) ?: [];
        if ($parts === []) return null;
        if (!is_file($parts[0]) && !is_executable($parts[0])) return null;
        return array_values($parts);
    }
}
