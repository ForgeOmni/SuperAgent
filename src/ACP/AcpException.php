<?php

declare(strict_types=1);

namespace SuperAgent\ACP;

class AcpException extends \RuntimeException
{
    /**
     * @param int      $code JSON-RPC error code (use one of {@see Protocol}::ERR_*).
     * @param mixed    $data Optional structured payload attached to the error envelope.
     */
    public function __construct(string $message, int $code = Protocol::ERR_INTERNAL, public mixed $data = null)
    {
        parent::__construct($message, $code);
    }
}
