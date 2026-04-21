<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * Test reading FAL data: sys_file (read-only), sys_file_reference (read-only),
 * and the uid_local expansion that embeds sys_file inside reference objects.
 */
class FalReadTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private ReadTableTool $readTool;
    private GetTableSchemaTool $schemaTool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fal_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference.csv');

        $this->readTool = new ReadTableTool();
        $this->schemaTool = new GetTableSchemaTool();
    }

    public function testReadSysFileDirectly(): void
    {
        $result = $this->readTool->execute([
            'table' => 'sys_file',
            'uid' => 1,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertCount(1, $data['records']);
        $record = $data['records'][0];

        $this->assertSame(1, $record['uid']);
        $this->assertSame('test.jpg', $record['name']);
        $this->assertSame('/test.jpg', $record['identifier']);
        $this->assertSame('image/jpeg', $record['mime_type']);
        // `extension` is not in every sys_file sub-schema's showitem; avoid asserting on it.
    }

    public function testReadSysFileReferenceDirectly(): void
    {
        $result = $this->readTool->execute([
            'table' => 'sys_file_reference',
            'uid' => 500,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertCount(1, $data['records']);
        $record = $data['records'][0];

        $this->assertSame(500, $record['uid']);
        $this->assertSame(1, (int)$record['uid_local']);
        $this->assertSame(120, (int)$record['uid_foreign']);
        $this->assertSame('tt_content', $record['tablenames']);
        $this->assertSame('image', $record['fieldname']);
        $this->assertSame('Hero alt text', $record['alternative']);
    }

    public function testReadContentElementWithImageExpandsFileReference(): void
    {
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 120,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertCount(1, $data['records']);
        $content = $data['records'][0];

        $this->assertSame(120, $content['uid']);
        $this->assertSame('image', $content['CType']);
        $this->assertArrayHasKey('image', $content, 'tt_content should expose the image inline relation');
        $this->assertIsArray($content['image']);
        $this->assertCount(1, $content['image']);

        $reference = $content['image'][0];
        $this->assertSame(500, $reference['uid'], 'sys_file_reference UID should be present');
        $this->assertSame('Hero alt text', $reference['alternative']);
        $this->assertSame('Hero image title', $reference['title']);

        $this->assertArrayHasKey('file', $reference, 'Reference should embed the full sys_file record');
        $this->assertSame(1, $reference['file']['uid']);
        $this->assertSame('/test.jpg', $reference['file']['identifier']);
        $this->assertSame('image/jpeg', $reference['file']['mime_type']);
        $this->assertSame('test.jpg', $reference['file']['name']);
    }

    public function testReadContentElementWithMultipleImageReferences(): void
    {
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 121,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $content = $data['records'][0];
        $this->assertCount(2, $content['image']);

        // References are ordered by sorting_foreign — uid 501 (sort 1) before uid 502 (sort 2)
        $this->assertSame(501, $content['image'][0]['uid']);
        $this->assertSame(2, $content['image'][0]['file']['uid']);
        $this->assertSame('/images/hero.png', $content['image'][0]['file']['identifier']);

        $this->assertSame(502, $content['image'][1]['uid']);
        $this->assertSame(1, $content['image'][1]['file']['uid']);
        $this->assertSame('t3://page?uid=2', $content['image'][1]['link']);
    }

    public function testMissingSysFileLeavesExpansionOut(): void
    {
        // Add a sys_file_reference that points to a non-existent sys_file
        $connection = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', [
            'pid' => 1,
            'tstamp' => 1734875000,
            'crdate' => 1734875000,
            'uid_local' => 9999,
            'uid_foreign' => 120,
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
            'sorting_foreign' => 10,
            'title' => 'Orphan',
            'alternative' => 'Orphan alt',
        ]);

        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 120,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $orphanReference = null;
        foreach ($data['records'][0]['image'] as $reference) {
            if ((int)$reference['uid_local'] === 9999) {
                $orphanReference = $reference;
                break;
            }
        }

        $this->assertNotNull($orphanReference, 'Orphan reference should still appear');
        $this->assertArrayNotHasKey('file', $orphanReference, 'Missing sys_file should not produce a file block');
    }

    public function testSysFileReferenceFieldAppearsInTtContentSchema(): void
    {
        $result = $this->schemaTool->execute([
            'table' => 'tt_content',
            'type' => 'image',
        ]);

        $this->assertSuccessfulToolResult($result);
        $this->assertCount(1, $result->content);
        $schemaText = $result->content[0]->text;

        $this->assertStringContainsString('image', $schemaText);
        $this->assertStringContainsString('sys_file_reference', $schemaText);
    }

    public function testSysFileSchemaIsReadable(): void
    {
        $result = $this->schemaTool->execute([
            'table' => 'sys_file',
        ]);

        $this->assertSuccessfulToolResult($result);
        $schemaText = $result->content[0]->text;
        $this->assertStringContainsString('sys_file', $schemaText);
    }

    public function testReadTableSchemaEnumIncludesFalTables(): void
    {
        $schema = $this->readTool->getSchema();
        $tableEnum = $schema['inputSchema']['properties']['table']['enum'] ?? [];

        $this->assertContains('sys_file', $tableEnum, 'sys_file must appear in the ReadTable enum');
        $this->assertContains('sys_file_reference', $tableEnum, 'sys_file_reference must appear in the ReadTable enum');
    }

    public function testReadInWorkspaceContextStillExposesLiveReferences(): void
    {
        $workspaceId = $this->createAndSwitchToWorkspace('FAL Test Workspace');

        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 120,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $reference = $data['records'][0]['image'][0];
        $this->assertSame(500, $reference['uid'], 'Live UID must still be exposed while in workspace context');
        $this->assertSame('Hero alt text', $reference['alternative']);
        $this->assertSame(1, $reference['file']['uid']);
    }

    public function testNewWorkspaceReferenceIsVisible(): void
    {
        $workspaceId = $this->createAndSwitchToWorkspace('FAL Test Workspace');

        // A brand-new reference created inside a workspace: t3ver_oid=0, t3ver_state=NEW_PLACEHOLDER.
        // These pass the WorkspaceRestriction's t3ver_oid=0 branch and must be visible to the client.
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid' => 1,
            'tstamp' => 1734875100,
            'crdate' => 1734875100,
            'sys_language_uid' => 0,
            'uid_local' => 2,
            'uid_foreign' => 120,
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
            'sorting_foreign' => 10,
            'title' => 'Workspace addition',
            'alternative' => 'Added in workspace',
            'description' => '',
            'link' => '',
            'crop' => '{}',
            't3ver_oid' => 0,
            't3ver_wsid' => $workspaceId,
            't3ver_state' => 1,
            't3ver_stage' => 0,
        ]);

        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 120,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $references = $data['records'][0]['image'];
        $new = null;
        foreach ($references as $reference) {
            if (($reference['alternative'] ?? null) === 'Added in workspace') {
                $new = $reference;
                break;
            }
        }

        $this->assertNotNull($new, 'Workspace-new reference must be visible. Got: ' . json_encode($references));
        $this->assertSame(2, $new['file']['uid'], 'sys_file expansion must work for workspace-new references');
    }

    /**
     * Bug A: CType=image must expose only the image field, not siblings like assets/media.
     * The serializer previously iterated all FAL columns of the parent table; it now honors
     * TYPO3's per-type showitem via TcaSchemaFactory.
     */
    public function testCTypeImageDoesNotExposeAssetsOrMedia(): void
    {
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 120,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        $this->assertSame('image', $record['CType']);
        $this->assertArrayHasKey('image', $record);
        $this->assertArrayNotHasKey(
            'assets',
            $record,
            'assets is a textmedia-only field and must not leak into CType=image'
        );
        $this->assertArrayNotHasKey(
            'media',
            $record,
            'media is a pages field and must not appear on tt_content'
        );
    }

    /**
     * Bug B: References must be filtered by their `fieldname` column. A row with
     * fieldname="image" must not appear under the `assets` field on the same parent.
     *
     * tt_content CType=textmedia exposes `assets` (not `image`), so a sys_file_reference
     * with fieldname=image on a textmedia parent is "orphan DB state" that must not
     * bleed into the assets array.
     */
    public function testReferencesAreFilteredByFieldname(): void
    {
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 100,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        $this->assertSame('textmedia', $record['CType']);
        // Bug A side-effect: textmedia doesn't expose `image` — it must not appear at all.
        $this->assertArrayNotHasKey(
            'image',
            $record,
            'textmedia must not expose the image field (not in its showitem)'
        );

        // Bug B core: `assets` must contain only fieldname=assets rows, never the fieldname=image row.
        $this->assertArrayHasKey('assets', $record);
        $assetUids = array_column($record['assets'], 'uid');
        $this->assertSame(
            [601],
            $assetUids,
            'assets field must only contain fieldname=assets references (ref 600 with fieldname=image must be filtered out)'
        );
    }

    /**
     * Bug C: References must be filtered by their `tablenames` column. A row with
     * tablenames="bogus_other_table" must not leak into tt_content reads even if it
     * shares uid_foreign with a real tt_content record.
     */
    public function testReferencesAreFilteredByTablenames(): void
    {
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 120,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        $imageUids = array_column($record['image'] ?? [], 'uid');
        $this->assertNotContains(
            602,
            $imageUids,
            'Reference with tablenames=bogus_other_table must not leak into tt_content 120'
        );
        $this->assertSame([500], $imageUids, 'tt_content 120 must only see its own sys_file_references');
    }
}
