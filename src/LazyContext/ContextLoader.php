<?php

namespace SuperAgent\LazyContext;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Loads context fragments from various sources on demand.
 *
 * Supported source types (determined by metadata['source']):
 *  - callable – a PHP callable that returns an array of messages
 *  - array    – already-materialised data stored in metadata['data']
 *  - file     – path to a JSON file containing a messages array
 */
class ContextLoader
{
    private array $config;
    private LoggerInterface $logger;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Load a context fragment given its id and metadata.
     *
     * @param string $id       Context fragment identifier
     * @param array  $metadata Registration metadata (see LazyContextManager::registerContext)
     * @return array|null      Array of message objects/arrays, or null on failure
     */
    public function load(string $id, array $metadata): ?array
    {
        $source = $metadata['source'] ?? null;

        // Inline data
        if (isset($metadata['data']) && is_array($metadata['data'])) {
            return $metadata['data'];
        }

        // Callable source
        if (is_callable($source)) {
            try {
                $data = ($source)($id, $metadata);
                return is_array($data) ? $data : null;
            } catch (\Throwable $e) {
                $this->logger->error('Context callable failed', [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        // File source
        if (is_string($source) && file_exists($source)) {
            $raw = file_get_contents($source);
            if ($raw === false) {
                $this->logger->error('Failed to read context file', ['path' => $source]);
                return null;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $this->logger->error('Invalid JSON in context file', ['path' => $source]);
                return null;
            }
            return $data;
        }

        $this->logger->warning('No loadable source for context', ['id' => $id]);
        return null;
    }
}
