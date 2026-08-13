<?php

declare(strict_types=1);

namespace SuperAgent\Spill;

/**
 * Backend-abstracted store for oversized tool output.
 *
 * Implementations own locator resolution: a locator produced by save() is
 * only meaningful to the store that issued it, and read() MUST refuse any
 * locator that would escape the store's private storage area.
 */
interface SpillStoreInterface
{
    /**
     * Persist $content. Best-effort: returns null on any failure so the
     * caller keeps the inline result instead of losing data.
     */
    public function save(string $toolName, string $toolUseId, string $content): ?SpillRef;

    /**
     * Read a bounded chunk of a spilled output.
     *
     * @return array{content: string, offset: int, length: int, total: int}
     *
     * @throws \InvalidArgumentException on malformed/foreign locators
     * @throws \RuntimeException when the spill file is missing
     */
    public function read(string $locator, int $offset = 0, int $limit = 20000): array;

    public function exists(string $locator): bool;
}
