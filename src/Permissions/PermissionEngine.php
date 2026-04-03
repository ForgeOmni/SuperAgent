<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

use SuperAgent\Guardrails\Context\RuntimeContext;
use SuperAgent\Guardrails\Context\RuntimeContextCollector;
use SuperAgent\Guardrails\GuardrailsEngine;
use SuperAgent\Tools\Tool;

class PermissionEngine
{
    private BashCommandClassifier $bashClassifier;
    private PermissionDenialTracker $denialTracker;
    private ?GuardrailsEngine $guardrailsEngine = null;
    private ?RuntimeContextCollector $contextCollector = null;

    public function __construct(
        private PermissionCallbackInterface $callback,
        private PermissionContext $context,
        ?GuardrailsEngine $guardrailsEngine = null,
    ) {
        $this->bashClassifier = new BashCommandClassifier();
        $this->denialTracker = new PermissionDenialTracker();
        $this->guardrailsEngine = $guardrailsEngine;
    }
    
    public function checkPermission(
        Tool $tool,
        array $input,
    ): PermissionDecision {
        $toolName = $tool->getName();
        $content = $this->extractContentForTool($tool, $input);
        
        // Step 1: Check rule-based permissions (bypass-immune)
        $ruleDecision = $this->checkRuleBasedPermissions($toolName, $content);
        if ($ruleDecision !== null) {
            return $this->applyModeTransformations($ruleDecision);
        }

        // Step 1.5: Guardrails DSL evaluation
        if ($this->guardrailsEngine !== null) {
            $guardrailsDecision = $this->evaluateGuardrails($tool, $input, $content);
            if ($guardrailsDecision !== null) {
                return $this->applyModeTransformations($guardrailsDecision);
            }
        }

        // Step 2: Tool-specific checks
        if ($tool->getName() === 'Bash') {
            $bashDecision = $this->checkBashPermissions($input['command'] ?? '');
            if ($bashDecision !== null) {
                return $this->applyModeTransformations($bashDecision);
            }
        }
        
        // Step 3: Check if tool requires user interaction
        if ($tool->requiresUserInteraction()) {
            return $this->applyModeTransformations(
                PermissionDecision::ask(
                    'This tool requires user interaction',
                    new PermissionDecisionReason('requires-interaction'),
                ),
            );
        }
        
        // Step 4: Mode-based allowance
        if ($this->context->mode === PermissionMode::BYPASS_PERMISSIONS) {
            return PermissionDecision::allow(
                'Bypassing permissions',
                new PermissionDecisionReason('bypass-mode'),
            );
        }
        
        if ($this->context->mode === PermissionMode::ACCEPT_EDITS) {
            if ($this->isEditingTool($toolName)) {
                return PermissionDecision::allow(
                    'Auto-accepting file edits',
                    new PermissionDecisionReason('accept-edits-mode'),
                );
            }
        }
        
        // Step 5: Check allow rules
        $allowRule = $this->findMatchingRule(
            $this->context->alwaysAllowRules,
            $toolName,
            $content,
        );
        
        if ($allowRule !== null) {
            return $this->applyModeTransformations(PermissionDecision::allow(
                'Matched allow rule',
                new PermissionDecisionReason('allow-rule', null, $allowRule),
            ));
        }
        
        // Step 6: Default to ask
        return $this->applyModeTransformations(
            PermissionDecision::ask(
                'No matching permission rules',
                new PermissionDecisionReason('default-ask'),
                $this->generateSuggestions($toolName, $content),
            ),
        );
    }
    
    private function checkRuleBasedPermissions(
        string $toolName,
        ?string $content,
    ): ?PermissionDecision {
        // Check deny rules first
        $denyRule = $this->findMatchingRule(
            $this->context->alwaysDenyRules,
            $toolName,
            $content,
        );
        
        if ($denyRule !== null) {
            $this->denialTracker->recordDenial($toolName, 'deny-rule');
            
            return PermissionDecision::deny(
                'Matched deny rule',
                new PermissionDecisionReason('deny-rule', null, $denyRule),
            );
        }
        
        // Check ask rules
        $askRule = $this->findMatchingRule(
            $this->context->alwaysAskRules,
            $toolName,
            $content,
        );
        
        if ($askRule !== null) {
            return PermissionDecision::ask(
                'Matched ask rule',
                new PermissionDecisionReason('ask-rule', null, $askRule),
                $this->generateSuggestions($toolName, $content),
            );
        }
        
        // Check for dangerous paths
        if ($content !== null && $this->isDangerousPath($content)) {
            return PermissionDecision::ask(
                'Accessing sensitive directory',
                new PermissionDecisionReason('dangerous-path'),
                $this->generateSuggestions($toolName, $content),
            );
        }
        
        return null;
    }
    
    private function checkBashPermissions(string $command): ?PermissionDecision
    {
        // Skip classifier-assisted checks when the feature flag is off
        if (!\SuperAgent\Config\ExperimentalFeatures::enabled('bash_classifier')) {
            return null;
        }

        $classification = $this->bashClassifier->classify($command);
        
        if ($classification->isHighRisk()) {
            return PermissionDecision::ask(
                "High-risk command: {$classification->reason}",
                new PermissionDecisionReason('bash-high-risk', $classification->category),
                [
                    new PermissionUpdate(
                        label: "Allow '{$classification->prefix}' commands",
                        allowRule: new PermissionRule(
                            PermissionRuleSource::RUNTIME,
                            PermissionBehavior::ALLOW,
                            new PermissionRuleValue('Bash', $classification->prefix . '*'),
                        ),
                    ),
                ],
            );
        }
        
        if ($classification->requiresApproval() && $this->context->mode !== PermissionMode::BYPASS_PERMISSIONS) {
            return PermissionDecision::ask(
                'Command requires approval',
                new PermissionDecisionReason('bash-approval-required', $classification->category),
            );
        }
        
        return null;
    }
    
    private function applyModeTransformations(PermissionDecision $decision): PermissionDecision
    {
        if ($this->context->mode === PermissionMode::DONT_ASK) {
            if ($decision->behavior === PermissionBehavior::ASK) {
                $this->denialTracker->recordDenial('*', 'dont-ask-mode');
                
                return PermissionDecision::deny(
                    'Automatically denied in dont-ask mode',
                    new PermissionDecisionReason('dont-ask-transform'),
                );
            }
        }
        
        if ($this->context->mode === PermissionMode::PLAN) {
            if ($decision->behavior === PermissionBehavior::ALLOW) {
                return PermissionDecision::ask(
                    'Plan mode requires explicit approval',
                    new PermissionDecisionReason('plan-mode'),
                );
            }
        }
        
        if ($this->context->mode === PermissionMode::AUTO) {
            if ($decision->behavior === PermissionBehavior::ASK) {
                $autoDecision = $this->callback->runAutoClassifier(
                    $decision->message ?? 'Permission required',
                );
                
                return $autoDecision
                    ? PermissionDecision::allow('Auto-approved', new PermissionDecisionReason('auto-classifier'))
                    : PermissionDecision::deny('Auto-denied', new PermissionDecisionReason('auto-classifier'));
            }
        }
        
        return $decision;
    }
    
    private function findMatchingRule(
        $rules,
        string $toolName,
        ?string $content,
    ): ?PermissionRule {
        foreach ($rules as $rule) {
            if ($rule->matches($toolName, $content)) {
                return $rule;
            }
        }
        
        return null;
    }
    
    private function extractContentForTool(Tool $tool, array $input): ?string
    {
        return match ($tool->getName()) {
            'Bash' => $input['command'] ?? null,
            'Read', 'Write', 'Edit', 'MultiEdit' => $input['file_path'] ?? null,
            'WebFetch', 'WebSearch' => $input['url'] ?? $input['query'] ?? null,
            default => null,
        };
    }
    
    private function isDangerousPath(string $path): bool
    {
        $dangerousPaths = [
            '.git/',
            '.env',
            '.ssh/',
            'credentials',
            'secrets',
            'password',
            '/etc/',
            '/System/',
            '/Windows/System32/',
        ];
        
        foreach ($dangerousPaths as $dangerous) {
            if (str_contains(strtolower($path), strtolower($dangerous))) {
                return true;
            }
        }
        
        return false;
    }
    
    private function isEditingTool(string $toolName): bool
    {
        return in_array($toolName, ['Edit', 'MultiEdit', 'Write', 'NotebookEdit'], true);
    }
    
    private function generateSuggestions(string $toolName, ?string $content): array
    {
        $suggestions = [];
        
        if ($content !== null && strlen($content) > 10) {
            $prefix = substr($content, 0, min(30, strlen($content)));
            $suggestions[] = new PermissionUpdate(
                label: "Allow this specific action",
                allowRule: new PermissionRule(
                    PermissionRuleSource::RUNTIME,
                    PermissionBehavior::ALLOW,
                    new PermissionRuleValue($toolName, $content),
                ),
            );
            
            if (str_contains($content, ' ')) {
                $parts = explode(' ', $content, 2);
                $suggestions[] = new PermissionUpdate(
                    label: "Allow '{$parts[0]}' commands",
                    allowRule: new PermissionRule(
                        PermissionRuleSource::RUNTIME,
                        PermissionBehavior::ALLOW,
                        new PermissionRuleValue($toolName, $parts[0] . '*'),
                    ),
                );
            }
        }
        
        $suggestions[] = new PermissionUpdate(
            label: "Allow all {$toolName} actions",
            allowRule: new PermissionRule(
                PermissionRuleSource::RUNTIME,
                PermissionBehavior::ALLOW,
                new PermissionRuleValue($toolName),
            ),
        );
        
        $suggestions[] = new PermissionUpdate(
            label: 'Enter bypass mode (dangerous)',
            mode: PermissionMode::BYPASS_PERMISSIONS,
        );
        
        return $suggestions;
    }
    
    public function getDenialTracker(): PermissionDenialTracker
    {
        return $this->denialTracker;
    }
    
    public function getContext(): PermissionContext
    {
        return $this->context;
    }
    
    public function setContext(PermissionContext $context): void
    {
        $this->context = $context;
    }

    public function setGuardrailsEngine(?GuardrailsEngine $engine): void
    {
        $this->guardrailsEngine = $engine;
    }

    public function setRuntimeContextCollector(?RuntimeContextCollector $collector): void
    {
        $this->contextCollector = $collector;
    }

    /**
     * Evaluate guardrails DSL rules and convert to a PermissionDecision.
     * Returns null if no guardrail rule matched or the action is non-permission.
     */
    private function evaluateGuardrails(Tool $tool, array $input, ?string $content): ?PermissionDecision
    {
        if ($this->contextCollector === null) {
            return null;
        }

        $runtimeContext = $this->contextCollector->buildContext(
            toolName: $tool->getName(),
            toolInput: $input,
            toolContent: $content,
        );

        $result = $this->guardrailsEngine->evaluate($runtimeContext);

        if (!$result->matched) {
            return null;
        }

        return $result->toPermissionDecision();
    }
}