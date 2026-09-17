<?php

namespace SuperAgent\Tools;

class ToolResult
{
    /**
     * @param string|null $deferredTicket  Set when the tool cannot answer yet
     *                                     and a human (or another system) will.
     *                                     See {@see ToolResult::deferred()}.
     * @param array       $deferredMeta    Anything the host needs to find that
     *                                     human again — a queue row id, an
     *                                     approval id, a summary to show them.
     */
    public function __construct(
        public readonly string|array $content,
        public readonly bool $isError = false,
        public readonly ?string $deferredTicket = null,
        public readonly array $deferredMeta = [],
    ) {
    }

    public static function success(string|array $content): static
    {
        return new static($content);
    }

    public static function error(string $message): static
    {
        return new static($message, isError: true);
    }
    
    public static function failure(string $message): static
    {
        return self::error($message);
    }

    /**
     * The tool cannot answer yet: a human — or anything else outside this
     * process — will. The turn ends cleanly with the transcript intact, and
     * the host resumes it later with {@see \SuperAgent\Agent::resume()},
     * quoting this ticket.
     *
     * Nothing about the deferral is stored by the SDK. The ticket is the
     * host's own identifier, and the envelope returned on the AgentResult is
     * the only state that has to survive until the answer arrives — which is
     * why it serialises, and why the answer may come back in a different
     * process, after a deploy.
     *
     * @param string $ticketId  The host's handle for the pending decision.
     * @param array  $meta      Carried through to the envelope untouched.
     *
     * @since 1.2.0
     */
    public static function deferred(string $ticketId, array $meta = []): static
    {
        if (trim($ticketId) === '') {
            throw new \InvalidArgumentException('A deferred tool result needs a non-empty ticket id.');
        }

        return new static('', false, $ticketId, $meta);
    }

    /** @since 1.2.0 */
    public function isDeferred(): bool
    {
        return $this->deferredTicket !== null;
    }

    public function isSuccess(): bool
    {
        return !$this->isError;
    }

    public function __get(string $name)
    {
        if ($name === 'error' && $this->isError) {
            return $this->contentAsString();
        }
        if ($name === 'data' && !$this->isError) {
            return $this->content;
        }
        return null;
    }

    public function contentAsString(): string
    {
        if (is_string($this->content)) {
            return $this->content;
        }

        return json_encode($this->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
