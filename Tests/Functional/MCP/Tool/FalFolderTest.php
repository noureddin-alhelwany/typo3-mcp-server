<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\CreateFolderTool;
use Hn\McpServer\MCP\Tool\File\ListFoldersTool;
use Hn\McpServer\Service\FolderService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PR-4: ListFolders + CreateFolder tools. Both operate live against the
 * default fileadmin/ storage (auto-created by StorageRepository on first
 * access, same pattern as FalUploadTest).
 */
class FalFolderTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private ListFoldersTool $listTool;
    private CreateFolderTool $createTool;

    protected function setUp(): void
    {
        parent::setUp();

        $service = new FolderService(GeneralUtility::makeInstance(StorageRepository::class));
        $this->listTool = new ListFoldersTool($service);
        $this->createTool = new CreateFolderTool($service);
    }

    protected function tearDown(): void
    {
        // Wipe anything the tests created under fileadmin/ so they don't
        // cross-contaminate. Matches FalUploadTest::tearDown.
        $uploadRoot = Environment::getPublicPath() . '/fileadmin';
        if (is_dir($uploadRoot)) {
            $this->cleanDir($uploadRoot, keepRoot: true);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // ListFolders
    // ------------------------------------------------------------------

    public function testListTopLevelFoldersOfDefaultStorage(): void
    {
        $this->seedFolder('/alpha/');
        $this->seedFolder('/beta/');

        $result = $this->listTool->execute([]);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertSame('/', $data['parentFolder']);
        $names = array_column($data['folders'], 'name');
        $this->assertContains('alpha', $names);
        $this->assertContains('beta', $names);

        // Each entry carries the expected shape.
        foreach ($data['folders'] as $folder) {
            $this->assertArrayHasKey('identifier', $folder);
            $this->assertArrayHasKey('fileCount', $folder);
            $this->assertArrayHasKey('subfolderCount', $folder);
            $this->assertIsInt($folder['fileCount']);
            $this->assertIsInt($folder['subfolderCount']);
        }
    }

    public function testListSpecificFolderShowsOnlyItsSubfolders(): void
    {
        $this->seedFolder('/alpha/inner/');
        $this->seedFolder('/beta/');

        $result = $this->listTool->execute(['folder' => '/alpha/']);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $names = array_column($data['folders'], 'name');
        $this->assertSame(['inner'], $names, 'only alpha/inner must appear; beta is a sibling');
    }

    public function testListRecursiveReturnsNestedFolders(): void
    {
        $this->seedFolder('/tree/a/b/c/');

        $result = $this->listTool->execute(['recursive' => true]);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $names = array_column($data['folders'], 'name');
        $this->assertContains('tree', $names);
        $this->assertContains('a', $names);
        $this->assertContains('b', $names);
        $this->assertContains('c', $names);
    }

    public function testListRejectsPathTraversal(): void
    {
        $result = $this->listTool->execute(['folder' => '../etc']);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('traversal', strtolower($result->content[0]->text));
    }

    public function testListRejectsUnknownStorage(): void
    {
        $result = $this->listTool->execute(['storage' => 9999]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Storage not found', $result->content[0]->text);
    }

    public function testListRejectsUnknownFolder(): void
    {
        $result = $this->listTool->execute(['folder' => '/does-not-exist/']);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist', $result->content[0]->text);
    }

    // ------------------------------------------------------------------
    // CreateFolder
    // ------------------------------------------------------------------

    public function testCreateSingleFolder(): void
    {
        $result = $this->createTool->execute(['path' => '/new-folder/']);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertSame('/new-folder/', $data['identifier']);
        $this->assertSame(['/new-folder/'], $data['created']);
        $this->assertTrue($this->defaultStorage()->hasFolder('/new-folder/'));
    }

    public function testCreateNestedRecursive(): void
    {
        $result = $this->createTool->execute(['path' => '/a/b/c/']);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertSame('/a/b/c/', $data['identifier']);
        $this->assertSame(['/a/', '/a/b/', '/a/b/c/'], $data['created']);
    }

    public function testCreatePartiallyExisting(): void
    {
        $this->seedFolder('/a/');

        $result = $this->createTool->execute(['path' => '/a/b/c/']);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertSame(['/a/b/', '/a/b/c/'], $data['created']);
    }

    public function testCreateExistingIsIdempotent(): void
    {
        $this->seedFolder('/existing/');

        $result = $this->createTool->execute(['path' => '/existing/']);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertSame('/existing/', $data['identifier']);
        $this->assertSame([], $data['created']);
    }

    public function testCreateNonRecursiveFailsWithoutParent(): void
    {
        $result = $this->createTool->execute([
            'path' => '/missing-parent/leaf/',
            'recursive' => false,
        ]);
        $this->assertTrue($result->isError);
        $text = $result->content[0]->text;
        $this->assertStringContainsString('missing-parent', $text);
        $this->assertStringContainsString('recursive=true', $text);
    }

    public function testCreateNonRecursiveSucceedsWhenParentExists(): void
    {
        $this->seedFolder('/parent/');

        $result = $this->createTool->execute([
            'path' => '/parent/leaf/',
            'recursive' => false,
        ]);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $this->assertSame(['/parent/leaf/'], $data['created']);
    }

    public function testCreateRejectsTraversal(): void
    {
        $result = $this->createTool->execute(['path' => '../etc']);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('traversal', strtolower($result->content[0]->text));
    }

    public function testCreateRejectsEmptyPath(): void
    {
        $result = $this->createTool->execute(['path' => '']);
        $this->assertTrue($result->isError);
    }

    public function testCreateRejectsRootOnlyPath(): void
    {
        $result = $this->createTool->execute(['path' => '/']);
        $this->assertTrue($result->isError);
    }

    public function testCreateRejectsDoubleSlashes(): void
    {
        $result = $this->createTool->execute(['path' => '/foo//bar/']);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('empty segments', $result->content[0]->text);
    }

    public function testCreateRejectsTooDeepPath(): void
    {
        $segments = implode('/', array_fill(0, 11, 'x'));
        $result = $this->createTool->execute(['path' => '/' . $segments . '/']);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('segment limit', $result->content[0]->text);
    }

    public function testCreateRejectsUnknownStorage(): void
    {
        $result = $this->createTool->execute([
            'path' => '/something/',
            'storage' => 9999,
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Storage not found', $result->content[0]->text);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Seed a folder (and any needed parents) directly through the storage API
     * so the seeding itself doesn't depend on the tool-under-test.
     */
    private function seedFolder(string $identifier): void
    {
        $storage = $this->defaultStorage();
        $segments = array_filter(explode('/', trim($identifier, '/')), static fn ($s) => $s !== '');
        $parent = $storage->getRootLevelFolder();
        foreach ($segments as $segment) {
            $childIdentifier = rtrim($parent->getIdentifier(), '/') . '/' . $segment . '/';
            if ($storage->hasFolder($childIdentifier)) {
                $parent = $storage->getFolder($childIdentifier);
                continue;
            }
            $parent = $storage->createFolder($segment, $parent);
        }
    }

    private function defaultStorage(): \TYPO3\CMS\Core\Resource\ResourceStorage
    {
        return GeneralUtility::makeInstance(StorageRepository::class)->getDefaultStorage();
    }

    private function cleanDir(string $dir, bool $keepRoot): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->cleanDir($path, keepRoot: false);
            } else {
                @unlink($path);
            }
        }
        if (!$keepRoot) {
            @rmdir($dir);
        }
    }
}
