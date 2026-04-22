<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Features;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Providers\MiniMaxProvider;

/**
 * Activate a multi-agent "team" mode on the target provider.
 *
 * MiniMax M2.7 has native Agent Teams baked in — the model self-organises
 * into specialist sub-agents with role boundaries, adversarial reasoning
 * and protocol adherence when the prompt carries explicit team scaffolding.
 * Other providers can approximate this by injecting the same scaffolding
 * as an explicit system-prompt overlay; the quality gap is large (that's
 * why M2.7's trained-in capability is the acceptance target) but the
 * shape stays uniform so callers don't branch on provider.
 *
 * Spec fields:
 *   roles       array of role descriptors, each `{name, description, tools?}`
 *   objective   high-level shared goal passed to every agent in the team
 *   protocol    optional coordination protocol hint (default: "consensus")
 *
 * Example:
 *   'features' => [
 *     'agent_teams' => [
 *       'roles' => [
 *         ['name' => 'researcher', 'description' => 'Gather source material'],
 *         ['name' => 'writer',     'description' => 'Draft the final report'],
 *         ['name' => 'critic',     'description' => 'Challenge every claim'],
 *       ],
 *       'objective' => 'Produce a 10-page market report on TOPIC',
 *     ],
 *   ]
 */
class AgentTeamsAdapter extends FeatureAdapter
{
    public const FEATURE_NAME = 'agent_teams';

    public static function apply(LLMProvider $provider, array $spec, array &$body): void
    {
        if (self::isDisabled($spec)) {
            return;
        }

        $roles = is_array($spec['roles'] ?? null) ? $spec['roles'] : [];
        $objective = (string) ($spec['objective'] ?? '');
        $protocol = (string) ($spec['protocol'] ?? 'consensus');

        if ($roles === [] && $objective === '') {
            if (self::isRequired($spec)) {
                self::fail($provider);
            }
            return;
        }

        $scaffold = self::renderScaffold($roles, $objective, $protocol);

        if ($provider instanceof MiniMaxProvider) {
            // MiniMax M2.7's Agent Teams primitive is trained into the model;
            // the scaffold is consumed by the model natively when it arrives
            // in the system message. No extra body fields are needed — the
            // same injection the universal path uses is already optimal.
            self::prependSystemMessage($body, $scaffold);
            return;
        }

        if (self::isRequired($spec)) {
            // Non-MiniMax provider with `required: true` — we still inject
            // the scaffold so the call can proceed, but the caller should
            // expect degraded coordination quality. We do NOT fail here:
            // the scaffold *is* the universal path, and degradation is an
            // acceptable outcome per the design's graceful-degrade rule.
        }

        self::prependSystemMessage($body, $scaffold);
    }

    /**
     * @param array<int, array{name?: string, description?: string, tools?: array<int, string>}> $roles
     */
    private static function renderScaffold(array $roles, string $objective, string $protocol): string
    {
        $lines = ['## Agent Team'];
        if ($objective !== '') {
            $lines[] = '**Shared objective:** ' . $objective;
        }
        if ($roles !== []) {
            $lines[] = '';
            $lines[] = '**Roles:**';
            foreach ($roles as $role) {
                $name = trim((string) ($role['name'] ?? ''));
                $desc = trim((string) ($role['description'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $tools = '';
                if (! empty($role['tools']) && is_array($role['tools'])) {
                    $tools = ' (tools: ' . implode(', ', array_map('strval', $role['tools'])) . ')';
                }
                $lines[] = "- **{$name}**" . ($desc !== '' ? " — {$desc}" : '') . $tools;
            }
        }
        $lines[] = '';
        $lines[] = "**Coordination protocol:** {$protocol}. Respect role boundaries, "
            . 'challenge each other where appropriate, and converge on the shared objective.';
        return implode("\n", $lines);
    }

    /**
     * Prepend the scaffold to the chat-completions-style `messages` list as
     * a new system message. Idempotent on repeated apply (detects the header).
     *
     * @param array<string, mixed> $body
     */
    private static function prependSystemMessage(array &$body, string $scaffold): void
    {
        if (! isset($body['messages']) || ! is_array($body['messages'])) {
            // Not a chat-completions shape — e.g. Qwen's {input: {messages}}.
            // Caller-specific adapters take care of those; this adapter is a
            // no-op rather than corrupting an unknown body.
            return;
        }

        // Idempotence: skip if the team header is already present.
        foreach ($body['messages'] as $msg) {
            $content = $msg['content'] ?? null;
            if (is_string($content) && str_contains($content, '## Agent Team')) {
                return;
            }
        }

        // Merge with existing system message when one is at the top, otherwise
        // prepend a new system message.
        if (! empty($body['messages']) && ($body['messages'][0]['role'] ?? null) === 'system') {
            $existing = (string) ($body['messages'][0]['content'] ?? '');
            $body['messages'][0]['content'] = trim($existing . "\n\n" . $scaffold);
            return;
        }

        array_unshift($body['messages'], [
            'role' => 'system',
            'content' => $scaffold,
        ]);
    }
}
