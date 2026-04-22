<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PR 3: UploadFile tool — physical file + sys_file row written to live, per FAL.md.
 *
 * Relies on StorageRepository auto-creating the default fileadmin/ storage on first
 * access (see StorageRepository::initializeLocalCache), so no sys_file_storage fixture
 * is needed.
 */
class FalUploadTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    /**
     * 1x1 transparent PNG.
     */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNgYGD4DwABBAEAfbLI3wAAAABJRU5ErkJggg==';

    /**
     * Minimal valid PDF.
     */
    private const PDF_BASE64 = 'JVBERi0xLjQKJeLjz9MKMyAwIG9iaiA8PC9MZW5ndGggNDQ+PnN0cmVhbQpCVCAvRjEgMTIgVGYgMSAwIDAgMSA1MCA3NTAgVG0gKEhpKSBUaiBFVAplbmRzdHJlYW0KZW5kb2JqCgoxIDAgb2JqIDw8L1R5cGUvUGFnZS9QYXJlbnQgMiAwIFIvUmVzb3VyY2VzIDw8L0ZvbnQgPDwvRjEgNCAwIFIgPj4gPj4vTWVkaWFCb3hbMCAwIDYxMiA3OTJdL0NvbnRlbnRzIDMgMCBSPj4KZW5kb2JqCgo0IDAgb2JqIDw8L1R5cGUvRm9udC9TdWJ0eXBlL1R5cGUxL0Jhc2VGb250L0hlbHZldGljYT4+CmVuZG9iagoKMiAwIG9iaiA8PC9UeXBlL1BhZ2VzL0tpZHNbMSAwIFJdL0NvdW50IDE+PgplbmRvYmoKCjUgMCBvYmogPDwvVHlwZS9DYXRhbG9nL1BhZ2VzIDIgMCBSPj4KZW5kb2JqCgp4cmVmCjAgNgowMDAwMDAwMDAwIDY1NTM1IGYKMDAwMDAwMDExMCAwMDAwMCBuCjAwMDAwMDAyNzcgMDAwMDAgbgowMDAwMDAwMDE1IDAwMDAwIG4KMDAwMDAwMDIxMiAwMDAwMCBuCjAwMDAwMDAzMjcgMDAwMDAgbgp0cmFpbGVyPDwvUm9vdCA1IDAgUi9TaXplIDY+PgpzdGFydHhyZWYKMzcwCiUlRU9G';

    private UploadFileTool $uploadTool;
    private WriteTableTool $writeTool;
    private ReadTableTool $readTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');

        $uploadService = new FileUploadService(
            GeneralUtility::makeInstance(StorageRepository::class)
        );
        $this->uploadTool = new UploadFileTool($uploadService);
        $this->writeTool = new WriteTableTool();
        $this->readTool = new ReadTableTool();
    }

    protected function tearDown(): void
    {
        // Clean uploaded files so consecutive tests start fresh.
        $uploadRoot = Environment::getPublicPath() . '/fileadmin';
        if (is_dir($uploadRoot)) {
            $this->cleanDir($uploadRoot, keepRoot: true);
        }
        parent::tearDown();
    }

    public function testUploadValidPngCreatesSysFile(): void
    {
        $result = $this->uploadTool->execute([
            'filename' => 'pr3-upload.png',
            'content' => self::PNG_BASE64,
        ]);
        $this->assertSuccessfulToolResult($result);
        $response = $this->extractJsonFromResult($result);

        $this->assertGreaterThan(0, $response['uid']);
        $this->assertSame('pr3-upload.png', $response['name']);
        $this->assertSame('image/png', $response['mime_type']);
        $this->assertGreaterThan(0, $response['size']);
        $this->assertGreaterThan(0, $response['storage']);

        // Physical file must exist on disk.
        $storage = GeneralUtility::makeInstance(StorageRepository::class)->findByUid($response['storage']);
        $this->assertNotNull($storage);
        $this->assertTrue($storage->hasFile($response['identifier']));

        // sys_file row exists (live, not workspaced — sys_file is not workspace-capable).
        $row = $this->fetchSysFile((int)$response['uid']);
        $this->assertNotEmpty($row);
        $this->assertSame('image/png', $row['mime_type']);
    }

    public function testUploadInWorkspaceContextStillWritesLiveSysFile(): void
    {
        $this->createAndSwitchToWorkspace('Upload WS');

        $result = $this->uploadTool->execute([
            'filename' => 'in-ws.png',
            'content' => self::PNG_BASE64,
        ]);
        $this->assertSuccessfulToolResult($result);
        $uid = $this->extractJsonFromResult($result)['uid'];

        // sys_file has no versioningWS; the row must land live regardless of workspace context.
        $row = $this->fetchSysFile((int)$uid);
        $this->assertNotEmpty($row);
        // sys_file has no t3ver_* columns at all — a plain fetch suffices.
        $this->assertArrayNotHasKey('t3ver_wsid', $row, 'sys_file must not carry workspace columns');
    }

    public function testUploadRejectsDisallowedMimeType(): void
    {
        // Executable-like bytes → application/octet-stream or similar, not in whitelist.
        $result = $this->uploadTool->execute([
            'filename' => 'evil.exe',
            'content' => base64_encode("MZ\x90\x00\x03\x00\x00\x00evil binary payload for test"),
        ]);
        $this->assertTrue($result->isError, 'non-whitelisted MIME must be rejected');
        $this->assertStringContainsString('not allowed', $result->content[0]->text);
    }

    /**
     * TYPO3's resource consistency check rejects uploads where the filename
     * extension disagrees with the actual file content. That closes the
     * classic "rename evil.exe to evil.jpg to bypass extension filters" path
     * before our MIME whitelist even needs to. Our tool must surface that
     * rejection as an error, not a successful upload.
     */
    public function testUploadRejectsFilenameContentMismatch(): void
    {
        $result = $this->uploadTool->execute([
            'filename' => 'disguised.jpg',
            'content' => self::PNG_BASE64,
        ]);
        $this->assertTrue($result->isError, 'extension/content mismatch must be rejected');
    }

    public function testUploadRenamesOnDuplicateFilename(): void
    {
        $first = $this->uploadTool->execute([
            'filename' => 'dup.png',
            'content' => self::PNG_BASE64,
        ]);
        $this->assertSuccessfulToolResult($first);
        $firstName = $this->extractJsonFromResult($first)['name'];

        $second = $this->uploadTool->execute([
            'filename' => 'dup.png',
            'content' => self::PNG_BASE64,
        ]);
        $this->assertSuccessfulToolResult($second);
        $secondName = $this->extractJsonFromResult($second)['name'];

        $this->assertNotSame($firstName, $secondName, 'duplicate filename must be renamed');
        $this->assertSame('dup.png', $firstName);
    }

    public function testUploadEnforcesSizeLimit(): void
    {
        $service = new FileUploadService(GeneralUtility::makeInstance(StorageRepository::class));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds size limit/');
        $service->upload(str_repeat('A', 100), 'too-big.png', null, null, null, 10);
    }

    public function testUploadWritesMetadataWhenProvided(): void
    {
        $result = $this->uploadTool->execute([
            'filename' => 'with-meta.png',
            'content' => self::PNG_BASE64,
            'alternative' => 'Ein Beispielbild',
            'title' => 'Beispiel',
            'description' => 'Zum Testen',
        ]);
        $this->assertSuccessfulToolResult($result);
        $uid = (int)$this->extractJsonFromResult($result)['uid'];

        $meta = $this->fetchSysFileMetadata($uid);
        $this->assertNotEmpty($meta, 'sys_file_metadata row must exist');
        $this->assertSame('Ein Beispielbild', (string)$meta['alternative']);
        $this->assertSame('Beispiel', (string)$meta['title']);
        $this->assertSame('Zum Testen', (string)$meta['description']);
    }

    public function testUploadDoesNotCreateSysFileReference(): void
    {
        $beforeCount = $this->countSysFileReferences();
        $result = $this->uploadTool->execute([
            'filename' => 'no-ref.png',
            'content' => self::PNG_BASE64,
        ]);
        $this->assertSuccessfulToolResult($result);

        $this->assertSame(
            $beforeCount,
            $this->countSysFileReferences(),
            'upload must not create any sys_file_reference (that is the linking tool\'s job)'
        );
    }

    public function testUploadFollowedByLinkingRoundtrip(): void
    {
        // Upload (live).
        $uploadResult = $this->uploadTool->execute([
            'filename' => 'linked.png',
            'content' => self::PNG_BASE64,
            'alternative' => 'Roundtrip alt',
        ]);
        $this->assertSuccessfulToolResult($uploadResult);
        $fileUid = (int)$this->extractJsonFromResult($uploadResult)['uid'];

        // Link via WriteTable — must go through a workspace.
        $this->createAndSwitchToWorkspace('Roundtrip WS');

        $createResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'image',
                'header' => 'Uploaded hero',
                'image' => [
                    ['file' => $fileUid, 'alternative' => 'Uploaded alt'],
                ],
            ],
        ]);
        $this->assertSuccessfulToolResult($createResult);
        $parentUid = (int)$this->extractJsonFromResult($createResult)['uid'];

        $readResult = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => $parentUid,
        ]);
        $this->assertSuccessfulToolResult($readResult);
        $record = $this->extractJsonFromResult($readResult)['records'][0];
        $this->assertCount(1, $record['image']);
        $this->assertSame($fileUid, (int)$record['image'][0]['file']['uid']);
        $this->assertSame('linked.png', $record['image'][0]['file']['name']);
        $this->assertSame('Uploaded alt', $record['image'][0]['alternative']);
    }

    public function testUploadRejectsEmptyContent(): void
    {
        $result = $this->uploadTool->execute([
            'filename' => 'empty.png',
            'content' => '',
        ]);
        $this->assertTrue($result->isError);
    }

    public function testUploadRejectsInvalidBase64(): void
    {
        $result = $this->uploadTool->execute([
            'filename' => 'bad.png',
            'content' => '!!! not base64 !!!',
        ]);
        $this->assertTrue($result->isError);
    }

    public function testUploadRejectsMissingFilename(): void
    {
        $result = $this->uploadTool->execute([
            'filename' => '',
            'content' => self::PNG_BASE64,
        ]);
        $this->assertTrue($result->isError);
    }

    public function testUploadPdfIsAccepted(): void
    {
        $result = $this->uploadTool->execute([
            'filename' => 'doc.pdf',
            'content' => self::PDF_BASE64,
        ]);
        $this->assertSuccessfulToolResult($result);
        $this->assertSame('application/pdf', $this->extractJsonFromResult($result)['mime_type']);
    }

    public function testUploadToUnknownStorageFails(): void
    {
        $result = $this->uploadTool->execute([
            'filename' => 'whatever.png',
            'content' => self::PNG_BASE64,
            'storage' => 9999,
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Storage not found', $result->content[0]->text);
    }

    public function testUploadToUnknownFolderFails(): void
    {
        $result = $this->uploadTool->execute([
            'filename' => 'whatever.png',
            'content' => self::PNG_BASE64,
            'folder' => '/does_not_exist/',
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist', $result->content[0]->text);
    }

    private function fetchSysFile(int $uid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from('sys_file')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: [];
    }

    private function fetchSysFileMetadata(int $fileUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from('sys_file_metadata')
            ->where($qb->expr()->eq('file', $qb->createNamedParameter($fileUid, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: [];
    }

    private function countSysFileReferences(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')->from('sys_file_reference')->executeQuery()->fetchOne();
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
