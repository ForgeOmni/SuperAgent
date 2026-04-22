<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

/**
 * Provider supports server-side file extraction — uploading a PDF / PPT /
 * DOCX and getting back a reference that can be cited in later messages
 * (Kimi `purpose=file-extract`, Qwen-Long `fileid://` system references).
 *
 * Conceptually splits into two phases to let callers reuse a file id across
 * multiple requests without re-uploading.
 */
interface SupportsFileExtract
{
    /**
     * Upload a local file and return a provider-scoped identifier that
     * subsequent calls to `fileReferenceFragment()` can turn into a
     * message/content block.
     */
    public function uploadForExtract(string $filePath, ?string $mimeType = null): string;

    /**
     * Build the message/content fragment that cites a previously-uploaded
     * file by its id. Callers merge the returned array into the outgoing
     * request messages.
     *
     * @return array<string, mixed>
     */
    public function fileReferenceFragment(string $fileId): array;
}
