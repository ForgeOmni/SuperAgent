<?php

declare(strict_types=1);

namespace SuperAgent\ACP;

/**
 * Constants and helpers for the Agent Client Protocol (ACP) v1 — the
 * standardised JSON-RPC over stdio protocol between an editor (Zed, Neovim,
 * VS Code via extension, ...) and a coding agent.
 *
 * Spec: https://agentclientprotocol.com/
 *
 * Wire shape: one JSON-RPC 2.0 message per line over stdio (Content-Length
 * framing is **not** used by ACP — that's the main difference from LSP). Each
 * message is `{"jsonrpc":"2.0","id":..,"method":..,"params":..}` or a
 * notification (no id).
 *
 * Method namespaces:
 *   - `initialize` / `authenticate` — server lifecycle
 *   - `session/new` / `session/load` / `session/prompt` / `session/cancel` — session lifecycle
 *   - `fs/read_text_file` / `fs/write_text_file` — client-side filesystem
 *     (server→client; agent asks editor to perform the operation in its own
 *     buffer state)
 *   - `permission/request` — agent asks user to approve a sensitive tool call
 *
 * This class only contains the constants. The actual transport lives in
 * {@see Server}, and the agent-side handling delegates to {@see Handler}.
 */
final class Protocol
{
    /** Protocol version advertised on initialize. */
    public const PROTOCOL_VERSION = 1;

    // --- Standard method names --------------------------------------------
    public const METHOD_INITIALIZE = 'initialize';
    public const METHOD_AUTHENTICATE = 'authenticate';
    public const METHOD_SESSION_NEW = 'session/new';
    public const METHOD_SESSION_LOAD = 'session/load';
    public const METHOD_SESSION_PROMPT = 'session/prompt';
    public const METHOD_SESSION_CANCEL = 'session/cancel';
    public const METHOD_SESSION_UPDATE = 'session/update'; // notification (agent→client)
    // Pi-borrowed extensions (pi.dev/docs/latest/rpc):
    public const METHOD_SESSION_STEER = 'session/steer';        // mid-turn correction without abort
    public const METHOD_SESSION_FOLLOW_UP = 'session/follow_up'; // queue prompt for after current turn
    public const METHOD_FS_READ = 'fs/read_text_file';
    public const METHOD_FS_WRITE = 'fs/write_text_file';
    public const METHOD_PERMISSION_REQUEST = 'permission/request';

    // --- JSON-RPC error codes ---------------------------------------------
    public const ERR_PARSE = -32700;
    public const ERR_INVALID_REQUEST = -32600;
    public const ERR_METHOD_NOT_FOUND = -32601;
    public const ERR_INVALID_PARAMS = -32602;
    public const ERR_INTERNAL = -32603;
    // ACP-specific
    public const ERR_AUTH_REQUIRED = -32001;
    public const ERR_SESSION_NOT_FOUND = -32002;

    public static function envelopeOk(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    public static function envelopeError(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $err = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $err['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err];
    }

    public static function notification(string $method, mixed $params): array
    {
        $env = ['jsonrpc' => '2.0', 'method' => $method];
        if ($params !== null) {
            $env['params'] = $params;
        }
        return $env;
    }
}
