<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes uploaded bytes into a FAL storage and creates the matching
 * sys_file row. FAL is the one MCP exception to the workspace-only rule:
 * sys_file is not workspace-capable, so uploads go live. See FAL.md.
 */
class FileUploadService
{
    /**
     * Hard default: 20 MB. Keeps base64 payloads through MCP transports
     * within workable HTTP/stdio limits.
     */
    public const DEFAULT_MAX_SIZE_BYTES = 20 * 1024 * 1024;

    /**
     * Default MIME whitelist — common images + PDF.
     * @var list<string>
     */
    public const DEFAULT_MIME_WHITELIST = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
    ];

    public function __construct(
        private readonly StorageRepository $storageRepository,
    ) {
    }

    /**
     * @param string $binaryContent Raw (already base64-decoded) file bytes.
     * @param string $filename Desired filename (sanitisation happens inside the storage).
     * @param int|null $storageUid If null, uses the default storage.
     * @param string|null $folderIdentifier If null, uses the storage's default folder.
     * @param list<string>|null $mimeWhitelist If null, uses DEFAULT_MIME_WHITELIST.
     * @param int|null $maxSizeBytes If null, uses DEFAULT_MAX_SIZE_BYTES.
     * @return File The indexed sys_file record wrapped in a File object.
     */
    public function upload(
        string $binaryContent,
        string $filename,
        ?int $storageUid = null,
        ?string $folderIdentifier = null,
        ?array $mimeWhitelist = null,
        ?int $maxSizeBytes = null,
    ): File {
        if ($filename === '') {
            throw new \InvalidArgumentException('Filename must not be empty.', 1735000001);
        }
        $mimeWhitelist ??= self::DEFAULT_MIME_WHITELIST;
        $maxSizeBytes ??= self::DEFAULT_MAX_SIZE_BYTES;

        $size = strlen($binaryContent);
        if ($size === 0) {
            throw new \InvalidArgumentException('File content is empty.', 1735000002);
        }
        if ($size > $maxSizeBytes) {
            throw new \InvalidArgumentException(sprintf(
                'File exceeds size limit: %d bytes > %d bytes.',
                $size,
                $maxSizeBytes
            ), 1735000003);
        }

        $storage = $this->resolveStorage($storageUid);
        $folder = $this->resolveFolder($storage, $folderIdentifier);

        $tempPath = GeneralUtility::tempnam('mcp_upload_');
        if ($tempPath === '' || file_put_contents($tempPath, $binaryContent) === false) {
            if ($tempPath !== '' && file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw new \RuntimeException('Could not write temporary upload file.', 1735000004);
        }

        try {
            $detectedMime = $this->detectMimeType($tempPath);
            if (!in_array($detectedMime, $mimeWhitelist, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'MIME type "%s" is not allowed. Allowed: %s',
                    $detectedMime,
                    implode(', ', $mimeWhitelist)
                ), 1735000005);
            }

            try {
                $file = $folder->addFile($tempPath, $filename, DuplicationBehavior::RENAME);
            } catch (InsufficientFolderWritePermissionsException $e) {
                throw new \RuntimeException(
                    'No write permission on folder ' . $folder->getIdentifier() . ': ' . $e->getMessage(),
                    1735000006,
                    $e
                );
            }
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        return $file;
    }

    /**
     * Update sys_file_metadata for a given File. No-op when the array is empty
     * or contains only empty values.
     *
     * @param array<string, mixed> $metadata Keys like alternative, title, description.
     */
    public function updateMetadata(File $file, array $metadata): void
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $clean[$key] = $value;
        }
        if ($clean === []) {
            return;
        }
        $file->getMetaData()->add($clean)->save();
    }

    private function resolveStorage(?int $storageUid): ResourceStorage
    {
        if ($storageUid !== null) {
            $storage = $this->storageRepository->findByUid($storageUid);
            if ($storage === null) {
                throw new \InvalidArgumentException('Storage not found: ' . $storageUid, 1735000007);
            }
            return $storage;
        }
        $storage = $this->storageRepository->getDefaultStorage();
        if ($storage === null) {
            throw new \RuntimeException('No default storage configured.', 1735000008);
        }
        return $storage;
    }

    private function resolveFolder(ResourceStorage $storage, ?string $folderIdentifier): Folder
    {
        if ($folderIdentifier === null || $folderIdentifier === '') {
            return $storage->getDefaultFolder();
        }
        if (!$storage->hasFolder($folderIdentifier)) {
            throw new \InvalidArgumentException(sprintf(
                'Folder "%s" does not exist in storage %d.',
                $folderIdentifier,
                $storage->getUid()
            ), 1735000009);
        }
        return $storage->getFolder($folderIdentifier);
    }

    private function detectMimeType(string $localPath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($localPath);
        if ($detected === false || $detected === '') {
            throw new \RuntimeException('Could not detect MIME type of uploaded file.', 1735000010);
        }
        return $detected;
    }
}
