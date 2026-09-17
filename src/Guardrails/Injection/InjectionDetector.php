<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Injection;

use SuperAgent\Guardrails\PromptInjectionResult;

/**
 * Something that looks at untrusted text and reports what it noticed.
 *
 * A host has context this SDK does not — its own tenant's vocabulary, its own
 * classifier, its own blocklist — and a pattern list is a weak signal
 * whatever language it is written in. Implement this, hand it to
 * {@see \SuperAgent\Guardrails\PromptInjectionDetector::addDetector()}, and
 * its findings are merged with the built-in ones.
 *
 * Nothing here decides anything: a detector annotates, and the host acts.
 * The structural defence — treating tool output as data and never as
 * instructions — is what actually holds, and it holds whether or not any
 * pattern matched.
 *
 * @since 1.6.0
 */
interface InjectionDetector
{
    public function scan(string $text, string $source = 'unknown'): PromptInjectionResult;
}
