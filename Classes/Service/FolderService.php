<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InvalidPathException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Folder-level FAL operations (listing + creation) used by the ListFolders and
 * CreateFolder tools. Works strictly through TYPO3 Core storage APIs — no
 * direct filesystem access — so BE-user filemounts, permission checks, and
 * path-traversal protection come from the core.
 *
 * Operates on live storage. Folder management falls under the same FAL
 * exception as UploadFile (see Documentation/Architecture/FAL.md): the storage
 * infrastructure is not workspace-capable.
 */
class FolderService
{
    /**
     * Ceiling on recursion depth and path segment count — guards against
     * symlink loops, runaway client requests, and general abuse.
     */
    public const MAX_DEPTH = 10;

    public function __construct(
        private readonly StorageRepository $storageRepository,
    ) {
    }

    /**
     * List subfolders of $folderIdentifier in $storageUid.
     *
     * @return array{parentFolder: string, folders: list<array{identifier: string, name: string, fileCount: int, subfolderCount: int}>}
     */
    public function listFolders(
        ?int $storageUid = null,
        ?string $folderIdentifier = null,
        bool $recursive = false,
    ): array {
        $this->assertNoTraversal($folderIdentifier);

        $storage = $this->resolveStorage($storageUid);
        $parent = $this->resolveFolder($storage, $folderIdentifier);

        try {
            $subfolders = $parent->getSubfolders(
                0,
                0,
                Folder::FILTER_MODE_USE_OWN_AND_STORAGE_FILTERS,
                $recursive
            );
        } catch (InvalidPathException $e) {
            throw new \InvalidArgumentException('Invalid folder path: ' . $e->getMessage(), 1735100001, $e);
        } catch (InsufficientFolderAccessPermissionsException $e) {
            throw new \RuntimeException(
                'No read permission on folder ' . $parent->getIdentifier() . ': ' . $e->getMessage(),
                1735100002,
                $e
            );
        }

        $baseDepth = $this->depthOfIdentifier($parent->getIdentifier());

        $result = [];
        foreach ($subfolders as $sub) {
            // Depth cap: relative to the requested parent, a child may not be
            // deeper than MAX_DEPTH levels. Applies only to recursive listings;
            // a non-recursive listing is depth 1 by definition.
            $depth = $this->depthOfIdentifier($sub->getIdentifier()) - $baseDepth;
            if ($depth > self::MAX_DEPTH) {
                continue;
            }

            $result[] = [
                'identifier' => $sub->getIdentifier(),
                'name' => $sub->getName(),
                'fileCount' => (int)$sub->getFileCount(),
                'subfolderCount' => count($sub->getSubfolders()),
            ];
        }

        return [
            'parentFolder' => $parent->getIdentifier(),
            'folders' => $result,
        ];
    }

    /**
     * Create $path in $storageUid, idempotent. Returns the canonical identifier
     * and the list of folders that were actually created (empty if everything
     * already existed).
     *
     * @return array{identifier: string, created: list<string>}
     */
    public function createFolder(
        string $path,
        ?int $storageUid = null,
        bool $recursive = true,
    ): array {
        $this->assertNoTraversal($path);

        $segments = $this->normalisePathSegments($path);
        if ($segments === []) {
            throw new \InvalidArgumentException(
                'Path must be non-empty and below storage root.',
                1735100010
            );
        }
        if (count($segments) > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(sprintf(
                'Path has %d segments — exceeds the %d-segment limit.',
                count($segments),
                self::MAX_DEPTH
            ), 1735100011);
        }

        $storage = $this->resolveStorage($storageUid);

        $parent = $storage->getRootLevelFolder();
        $created = [];
        $currentIdentifier = $parent->getIdentifier();

        foreach ($segments as $index => $segment) {
            $sanitised = $storage->sanitizeFileName($segment, $parent);
            if ($sanitised !== $segment) {
                throw new \InvalidArgumentException(sprintf(
                    'Folder name "%s" is not accepted by the storage (would be renamed to "%s"). Pick a valid name.',
                    $segment,
                    $sanitised
                ), 1735100012);
            }

            $childIdentifier = rtrim($currentIdentifier, '/') . '/' . $segment . '/';

            if ($storage->hasFolder($childIdentifier)) {
                $parent = $storage->getFolder($childIdentifier);
                $currentIdentifier = $childIdentifier;
                continue;
            }

            // Folder missing. Without recursive, only the leaf may be missing —
            // any earlier miss is a parent miss, which is the error we want to
            // surface clearly.
            if (!$recursive && $index !== count($segments) - 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Parent folder "%s" does not exist. Use recursive=true to create it.',
                    $childIdentifier
                ), 1735100013);
            }

            try {
                $newFolder = $storage->createFolder($segment, $parent);
            } catch (ExistingTargetFolderException $e) {
                // Race-safe fallback: another process created it in between.
                $newFolder = $storage->getFolder($childIdentifier);
            } catch (InsufficientFolderWritePermissionsException $e) {
                throw new \RuntimeException(
                    'No write permission to create folder ' . $childIdentifier . ': ' . $e->getMessage(),
                    1735100014,
                    $e
                );
            } catch (InvalidPathException $e) {
                throw new \InvalidArgumentException(
                    'Invalid folder path: ' . $e->getMessage(),
                    1735100015,
                    $e
                );
            } catch (\InvalidArgumentException $e) {
                // Core throws a plain InvalidArgumentException e.g. when the
                // parent folder doesn't exist — surface with the offending path.
                throw new \InvalidArgumentException(
                    'Cannot create folder ' . $childIdentifier . ': ' . $e->getMessage(),
                    1735100016,
                    $e
                );
            }

            $parent = $newFolder;
            $currentIdentifier = $newFolder->getIdentifier();
            $created[] = $currentIdentifier;
        }

        return [
            'identifier' => $currentIdentifier,
            'created' => $created,
        ];
    }

    private function resolveStorage(?int $storageUid): ResourceStorage
    {
        if ($storageUid !== null) {
            $storage = $this->storageRepository->findByUid($storageUid);
            if ($storage === null) {
                throw new \InvalidArgumentException('Storage not found: ' . $storageUid, 1735100020);
            }
            return $storage;
        }
        $storage = $this->storageRepository->getDefaultStorage();
        if ($storage === null) {
            throw new \RuntimeException('No default storage configured.', 1735100021);
        }
        return $storage;
    }

    private function resolveFolder(ResourceStorage $storage, ?string $folderIdentifier): Folder
    {
        if ($folderIdentifier === null || $folderIdentifier === '' || $folderIdentifier === '/') {
            return $storage->getRootLevelFolder();
        }
        if (!$storage->hasFolder($folderIdentifier)) {
            throw new \InvalidArgumentException(sprintf(
                'Folder "%s" does not exist in storage %d.',
                $folderIdentifier,
                $storage->getUid()
            ), 1735100022);
        }
        return $storage->getFolder($folderIdentifier);
    }

    private function assertNoTraversal(?string $path): void
    {
        if ($path === null) {
            return;
        }
        // TYPO3 core eventually rejects these via InvalidPathException, but
        // catching early yields a cleaner MCP-side error.
        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException(
                'Path traversal (".." segment) is not allowed: ' . $path,
                1735100030
            );
        }
    }

    /**
     * Split "/a/b/c/" (or variants) into ["a", "b", "c"].
     * Empty segments (e.g. from "//") are an error, not silently collapsed.
     *
     * @return list<string>
     */
    private function normalisePathSegments(string $path): array
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '/') {
            return [];
        }
        // Strip leading/trailing slash only — internal slashes stay so we can
        // detect empty segments ("//foo") as errors.
        $stripped = trim($trimmed, '/');
        $raw = explode('/', $stripped);
        foreach ($raw as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException(
                    'Path contains empty segments: ' . $path,
                    1735100031
                );
            }
        }
        return $raw;
    }

    private function depthOfIdentifier(string $identifier): int
    {
        $trimmed = trim($identifier, '/');
        if ($trimmed === '') {
            return 0;
        }
        return substr_count($trimmed, '/') + 1;
    }
}
