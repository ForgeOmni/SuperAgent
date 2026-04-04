<?php

namespace SuperAgent\IncrementalContext;

use SuperAgent\Messages\Message;

/**
 * Computes differences between two context arrays.
 */
class ContextDiffer
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Diff two message arrays and return a ContextDelta.
     *
     * Strategy:
     *  - Messages that exist in $old but not $new → removed (by index)
     *  - Messages at the same index whose serialised form changed → modified
     *  - Messages appended beyond $old's length → added
     */
    public function diff(array $old, array $new): ContextDelta
    {
        $added = [];
        $modified = [];
        $removed = [];

        $oldCount = count($old);
        $newCount = count($new);

        // Detect modifications in the overlapping range
        for ($i = 0; $i < min($oldCount, $newCount); $i++) {
            if ($this->serialize($old[$i]) !== $this->serialize($new[$i])) {
                $modified[$i] = $new[$i];
            }
        }

        // Detect removed messages (old had more messages)
        for ($i = $newCount; $i < $oldCount; $i++) {
            $removed[] = $i;
        }

        // Detect added messages (new has more messages)
        for ($i = $oldCount; $i < $newCount; $i++) {
            $added[] = $new[$i];
        }

        return new ContextDelta($added, $modified, $removed);
    }

    private function serialize($message): string
    {
        if ($message instanceof Message) {
            return serialize($message);
        }
        return json_encode($message, JSON_THROW_ON_ERROR);
    }
}
