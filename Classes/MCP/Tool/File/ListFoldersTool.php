<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\FolderService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * List subfolders of a FAL storage folder so the client can discover where
 * uploads should land before calling UploadFile (and, if needed, CreateFolder).
 *
 * FAL folders are not workspace-capable, so this tool reads live. See
 * Documentation/Architecture/FAL.md for the full boundary.
 */
class ListFoldersTool extends AbstractTool
{
    public function __construct(
        private readonly FolderService $folderService,
    ) {
    }

    public function getSchema(): array
    {
        return [
            'description' => 'List subfolders of a FAL storage folder. Use this before UploadFile to find an existing '
                . 'target folder, or to decide whether CreateFolder is needed. Returns each subfolder with its file '
                . 'count and direct-child subfolder count so the client can pick a reasonable destination.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'storage' => [
                        'type' => 'integer',
                        'description' => 'sys_file_storage UID. Defaults to the default storage.',
                    ],
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Storage-relative folder identifier (e.g. "/user_upload/"). Defaults to the storage root.',
                    ],
                    'recursive' => [
                        'type' => 'boolean',
                        'description' => 'Include subfolders of subfolders. Capped at 10 levels deep to protect against symlink loops.',
                    ],
                ],
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $storageUid = isset($params['storage']) ? (int)$params['storage'] : null;
        $folder = isset($params['folder']) && $params['folder'] !== '' ? (string)$params['folder'] : null;
        $recursive = !empty($params['recursive']);

        $result = $this->folderService->listFolders($storageUid, $folder, $recursive);

        return new CallToolResult([
            new TextContent(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);
    }
}
