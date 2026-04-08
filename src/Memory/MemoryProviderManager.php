<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Memory\Contracts\MemoryProviderInterface;

/**
 * Manages builtin + at most one external memory provider.
 *
 * Inspired by hermes-agent's MemoryManager — provides:
 *   - Always-on builtin provider (MEMORY.md/USER.md)
 *   - Single optional external provider (vector, episodic, etc.)
 *   - Unified lifecycle dispatch to all providers
 *   - Safe context block generation with XML wrapping
 */
class MemoryProviderManager
{
    private ?MemoryProviderInterface $externalProvider = null;
    private LoggerInterface $logger;

    public function __construct(
        private MemoryProviderInterface $builtinProvider,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Set the external memory provider (replaces any existing one).
     */
    public function setExternalProvider(MemoryProviderInterface $provider): void
    {
        // Shut down previous external provider
        if ($this->externalProvider !== null) {
            try {
                $this->externalProvider->shutdown();
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to shutdown previous memory provider', [
                    'provider' => $this->externalProvider->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->externalProvider = $provider;
        $this->logger->info('External memory provider set', ['name' => $provider->getName()]);
    }

    /**
     * Get the current external provider (if any).
     */
    public function getExternalProvider(): ?MemoryProviderInterface
    {
        return $this->externalProvider;
    }

    /**
     * Initialize all providers.
     */
    public function initialize(array $config = []): void
    {
        $this->builtinProvider->initialize($config);

        if ($this->externalProvider !== null) {
            try {
                $this->externalProvider->initialize($config);
            } catch (\Throwable $e) {
                $this->logger->error('External memory provider initialization failed', [
                    'provider' => $this->externalProvider->getName(),
                    'error' => $e->getMessage(),
                ]);
                $this->externalProvider = null;
            }
        }
    }

    /**
     * Dispatch turn start to all providers and build combined context.
     *
     * @return string|null Combined context block, or null if no context
     */
    public function onTurnStart(string $userMessage, array $conversationHistory): ?string
    {
        $parts = [];

        $builtinContext = $this->safeCall(
            $this->builtinProvider,
            fn($p) => $p->onTurnStart($userMessage, $conversationHistory)
        );

        if ($builtinContext !== null) {
            $parts[] = $builtinContext;
        }

        if ($this->externalProvider !== null) {
            $externalContext = $this->safeCall(
                $this->externalProvider,
                fn($p) => $p->onTurnStart($userMessage, $conversationHistory)
            );

            if ($externalContext !== null) {
                $parts[] = $externalContext;
            }
        }

        if (empty($parts)) {
            return null;
        }

        return $this->wrapContextBlock(implode("\n\n", $parts));
    }

    /**
     * Dispatch turn end to all providers.
     */
    public function onTurnEnd(array $assistantResponse, array $conversationHistory): void
    {
        $this->safeCall($this->builtinProvider, fn($p) => $p->onTurnEnd($assistantResponse, $conversationHistory));

        if ($this->externalProvider !== null) {
            $this->safeCall($this->externalProvider, fn($p) => $p->onTurnEnd($assistantResponse, $conversationHistory));
        }
    }

    /**
     * Dispatch pre-compression to all providers.
     */
    public function onPreCompress(array $messagesToCompress): void
    {
        $this->safeCall($this->builtinProvider, fn($p) => $p->onPreCompress($messagesToCompress));

        if ($this->externalProvider !== null) {
            $this->safeCall($this->externalProvider, fn($p) => $p->onPreCompress($messagesToCompress));
        }
    }

    /**
     * Dispatch session end to all providers.
     */
    public function onSessionEnd(array $fullConversation): void
    {
        $this->safeCall($this->builtinProvider, fn($p) => $p->onSessionEnd($fullConversation));

        if ($this->externalProvider !== null) {
            $this->safeCall($this->externalProvider, fn($p) => $p->onSessionEnd($fullConversation));
        }
    }

    /**
     * Dispatch memory write to all providers.
     */
    public function onMemoryWrite(string $key, string $content, array $metadata = []): void
    {
        $this->safeCall($this->builtinProvider, fn($p) => $p->onMemoryWrite($key, $content, $metadata));

        if ($this->externalProvider !== null) {
            $this->safeCall($this->externalProvider, fn($p) => $p->onMemoryWrite($key, $content, $metadata));
        }
    }

    /**
     * Search across all providers.
     */
    public function search(string $query, int $maxResults = 5): array
    {
        $results = [];

        $builtinResults = $this->safeCall(
            $this->builtinProvider,
            fn($p) => $p->search($query, $maxResults)
        ) ?? [];

        foreach ($builtinResults as $r) {
            $r['provider'] = $this->builtinProvider->getName();
            $results[] = $r;
        }

        if ($this->externalProvider !== null) {
            $externalResults = $this->safeCall(
                $this->externalProvider,
                fn($p) => $p->search($query, $maxResults)
            ) ?? [];

            foreach ($externalResults as $r) {
                $r['provider'] = $this->externalProvider->getName();
                $results[] = $r;
            }
        }

        // Sort by relevance descending
        usort($results, fn($a, $b) => ($b['relevance'] ?? 0) <=> ($a['relevance'] ?? 0));

        return array_slice($results, 0, $maxResults);
    }

    /**
     * Shut down all providers.
     */
    public function shutdown(): void
    {
        $this->safeCall($this->builtinProvider, fn($p) => $p->shutdown());

        if ($this->externalProvider !== null) {
            $this->safeCall($this->externalProvider, fn($p) => $p->shutdown());
        }
    }

    /**
     * Wrap recalled memory context in XML tags to prevent it being treated as user input.
     */
    private function wrapContextBlock(string $content): string
    {
        return "<recalled-memory>\n{$content}\n</recalled-memory>";
    }

    /**
     * Safely call a provider method, catching and logging errors.
     */
    private function safeCall(MemoryProviderInterface $provider, callable $fn): mixed
    {
        try {
            return $fn($provider);
        } catch (\Throwable $e) {
            $this->logger->warning('Memory provider call failed', [
                'provider' => $provider->getName(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
