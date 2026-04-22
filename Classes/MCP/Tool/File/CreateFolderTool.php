<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\FolderService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Create a folder (or nested chain of folders) in a FAL storage so that a
 * subsequent UploadFile call has a valid destination. Idempotent: calling
 * CreateFolder on an existing path returns successfully with an empty
 * `created` list.
 *
 * FAL folders are not workspace-capable, so this tool writes live. See
 * Documentation/Architecture/FAL.md.
 */
class CreateFolderTool extends AbstractTool
{
    public function __construct(
        private readonly FolderService $folderService,
    ) {
    }

    public function getSchema(): array
    {
        return [
            'description' => 'Create a folder in a FAL storage, with recursive intermediate creation by default. '
                . 'Idempotent: an already-existing target path returns success without creating anything. '
                . 'Use before UploadFile when the intended destination does not yet exist.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Storage-relative path, e.g. "/campaigns/2026/hero/". Max 10 segments deep.',
                    ],
                    'storage' => [
                        'type' => 'integer',
                        'description' => 'sys_file_storage UID. Defaults to the default storage.',
                    ],
                    'recursive' => [
                        'type' => 'boolean',
                        'description' => 'Create missing intermediate folders. Default true. If false and a parent is missing, the call fails with a hint.',
                    ],
                ],
                'required' => ['path'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $path = isset($params['path']) ? (string)$params['path'] : '';
        $storageUid = isset($params['storage']) ? (int)$params['storage'] : null;
        $recursive = !array_key_exists('recursive', $params) ? true : (bool)$params['recursive'];

        $result = $this->folderService->createFolder($path, $storageUid, $recursive);

        return new CallToolResult([
            new TextContent(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);
    }
}
