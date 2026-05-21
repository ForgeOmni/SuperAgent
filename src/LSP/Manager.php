<?php

declare(strict_types=1);

namespace SuperAgent\LSP;

/**
 * Per-worktree LSP session manager. Lazily spawns and caches one Client per
 * (server-id, root-dir) pair so multi-file edits don't re-initialize the
 * server on every call.
 *
 * Lifecycle is owned by the caller — typically tied to the agent run:
 * construct one Manager per session, call {@see diagnostics()}/{@see hover()}
 * during the run, then {@see shutdownAll()} on completion.
 *
 * The Manager is stateless about your editor; it expects to read file contents
 * directly from disk before pushing to the LSP server. If you're maintaining
 * an in-memory mirror (e.g. uncommitted Edit-tool changes), pass the latest
 * content explicitly via {@see touchFile()}.
 */
final class Manager
{
    /** @var array<string, Client> "$serverId@$rootDir" → Client */
    private array $clients = [];

    /** @var array<string, true> "$path@$serverId" → true (already opened) */
    private array $opened = [];

    public function __construct(
        private readonly string $worktree,
    ) {
    }

    /**
     * Touch a file in the LSP server: if not yet open, send `didOpen`;
     * otherwise send `didChange` with the latest content. Use this immediately
     * after the agent edits a file so diagnostics reflect the new state.
     */
    public function touchFile(string $path, ?string $content = null): void
    {
        $languageId = LanguageExtensions::forPath($path);
        if ($languageId === null) {
            return;
        }
        $content ??= @file_get_contents($path) ?: '';

        foreach ($this->resolveClients($path) as $client) {
            $key = $path . '@' . spl_object_hash($client);
            if (! isset($this->opened[$key])) {
                $client->didOpen($path, $languageId, $content);
                $this->opened[$key] = true;
            } else {
                $client->didChange($path, $content);
            }
        }
    }

    /**
     * Pull diagnostics from every server that handles $path. Returns a flat
     * array keyed by serverId.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function diagnostics(string $path): array
    {
        $out = [];
        foreach ($this->resolveServersWithClients($path) as $serverId => $client) {
            $out[$serverId] = $client->diagnostics($path);
        }
        return $out;
    }

    /**
     * @return array<string, ?array<string, mixed>>
     */
    public function hover(string $path, int $line, int $character): array
    {
        $out = [];
        foreach ($this->resolveServersWithClients($path) as $serverId => $client) {
            $out[$serverId] = $client->hover($path, $line, $character);
        }
        return $out;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function definition(string $path, int $line, int $character): array
    {
        $out = [];
        foreach ($this->resolveServersWithClients($path) as $serverId => $client) {
            $out[$serverId] = $client->definition($path, $line, $character);
        }
        return $out;
    }

    public function shutdownAll(): void
    {
        foreach ($this->clients as $c) {
            try {
                $c->shutdown();
            } catch (\Throwable) {
                // best-effort
            }
        }
        $this->clients = [];
        $this->opened = [];
    }

    public function __destruct()
    {
        $this->shutdownAll();
    }

    /**
     * @return array<int, Client>
     */
    private function resolveClients(string $path): array
    {
        $ext = '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $clients = [];
        foreach (ServerRegistry::forExtension($ext) as $server) {
            $client = $this->clientFor($server, $path);
            if ($client !== null) {
                $clients[] = $client;
            }
        }
        return $clients;
    }

    /**
     * @return array<string, Client>  serverId → client
     */
    private function resolveServersWithClients(string $path): array
    {
        $ext = '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $out = [];
        foreach (ServerRegistry::forExtension($ext) as $server) {
            $client = $this->clientFor($server, $path);
            if ($client !== null && ! isset($out[$server->id])) {
                $out[$server->id] = $client;
                // Ensure didOpen has fired so pull diagnostics have content to chew on.
                $this->touchFile($path);
            }
        }
        return $out;
    }

    private function clientFor(ServerInfo $server, string $path): ?Client
    {
        $root = ($server->rootFinder)($path, $this->worktree) ?? $this->worktree;
        $key = $server->id . '@' . $root;
        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }

        $cmd = ($server->spawn)($root);
        if ($cmd === false || ! is_array($cmd) || $cmd === []) {
            return null;
        }

        try {
            $client = new Client($cmd, $root, $server->initializationOptions);
            $client->initialize();
            $this->clients[$key] = $client;
            return $client;
        } catch (\Throwable $e) {
            // Server probe failed — caller will see "no diagnostics" rather than crash.
            return null;
        }
    }
}
