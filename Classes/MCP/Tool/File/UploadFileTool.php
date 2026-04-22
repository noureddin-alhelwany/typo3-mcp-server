<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\FileUploadService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Uploads a file into a FAL storage and creates the matching sys_file row.
 *
 * This tool is the one documented FAL exception to the workspace-only rule:
 * the physical file and the sys_file record are written live, because sys_file
 * is not workspace-capable in TYPO3. All content-linking records (e.g. via
 * WriteTable `image: [{file: <uid>, ...}]`) still go through the workspace.
 * See Documentation/Architecture/FAL.md.
 */
class UploadFileTool extends AbstractTool
{
    public function __construct(
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    public function getSchema(): array
    {
        return [
            'description' => 'Upload a file into the TYPO3 file storage (fileadmin). '
                . 'The file is written to live — FAL is intentionally exempt from workspace staging '
                . 'because sys_file is not workspace-capable. Linking the uploaded file to a page or '
                . 'content element is done separately via WriteTable and goes through the workspace. '
                . 'Returns the live sys_file UID that can be used as `file` in a FAL inline shortcut.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'filename' => [
                        'type' => 'string',
                        'description' => 'Target filename including extension (e.g. "hero.jpg"). Conflicting names get a numeric suffix.',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Base64-encoded file bytes. Default max size 20 MB after decoding.',
                    ],
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Storage-relative folder identifier (e.g. "/user_upload/"). Defaults to the storage default folder.',
                    ],
                    'storage' => [
                        'type' => 'integer',
                        'description' => 'sys_file_storage UID. Defaults to the default storage.',
                    ],
                    'alternative' => [
                        'type' => 'string',
                        'description' => 'Accessibility alt text stored on sys_file_metadata.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Title stored on sys_file_metadata.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Description stored on sys_file_metadata.',
                    ],
                ],
                'required' => ['filename', 'content'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $filename = isset($params['filename']) ? trim((string)$params['filename']) : '';
        if ($filename === '') {
            throw new \InvalidArgumentException('Parameter "filename" is required.');
        }
        if (!isset($params['content']) || !is_string($params['content']) || $params['content'] === '') {
            throw new \InvalidArgumentException('Parameter "content" is required and must be a non-empty base64 string.');
        }

        $binary = base64_decode($params['content'], true);
        if ($binary === false) {
            throw new \InvalidArgumentException('Parameter "content" is not valid base64.');
        }

        $storageUid = isset($params['storage']) ? (int)$params['storage'] : null;
        $folder = isset($params['folder']) && $params['folder'] !== '' ? (string)$params['folder'] : null;

        $file = $this->fileUploadService->upload(
            $binary,
            $filename,
            $storageUid,
            $folder,
        );

        $this->fileUploadService->updateMetadata($file, [
            'alternative' => $params['alternative'] ?? null,
            'title' => $params['title'] ?? null,
            'description' => $params['description'] ?? null,
        ]);

        $response = [
            'uid' => (int)$file->getUid(),
            'identifier' => $file->getIdentifier(),
            'name' => $file->getName(),
            'mime_type' => $file->getMimeType(),
            'size' => (int)$file->getSize(),
            'storage' => (int)$file->getStorage()->getUid(),
        ];

        return new CallToolResult([
            new TextContent(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);
    }
}
