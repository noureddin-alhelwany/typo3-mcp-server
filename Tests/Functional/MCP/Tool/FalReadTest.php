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

    /**
     * Regression: ReadTable table=sys_file uid=X failed with "Failed to count record"
     * when the caller was inside a workspace. sys_file is not workspace-capable, so
     * the OR-branch that references t3ver_oid hit an unknown column. The fix gates
     * that branch on workspace capability.
     */
    public function testReadSysFileByUidInWorkspaceContext(): void
    {
        $this->createAndSwitchToWorkspace('FAL sys_file UID regression');

        $result = $this->readTool->execute([
            'table' => 'sys_file',
            'uid' => 1,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $this->assertCount(1, $data['records']);
        $this->assertSame(1, $data['records'][0]['uid']);
    }

    public function testReadSysFileWithNonExistentUidReturnsEmpty(): void
    {
        $this->createAndSwitchToWorkspace('FAL sys_file missing UID');

        $result = $this->readTool->execute([
            'table' => 'sys_file',
            'uid' => 999999,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $this->assertSame([], $data['records']);
        $this->assertSame(0, $data['total']);
    }

    /**
     * sys_file_storage is also non-workspace-capable and on the allowed-root list;
     * same bug, same fix. Storage 1 is auto-created by StorageRepository on first
     * access, so a direct UID lookup must work in workspace context.
     */
    public function testReadSysFileStorageByUidInWorkspaceContext(): void
    {
        // Touch the StorageRepository to trigger the auto-create of storage 1.
        \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Resource\StorageRepository::class
        )->getDefaultStorage();

        $this->createAndSwitchToWorkspace('FAL sys_file_storage UID');

        $result = $this->readTool->execute([
            'table' => 'sys_file_storage',
            'uid' => 1,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $this->assertCount(1, $data['records']);
        $this->assertSame(1, $data['records'][0]['uid']);
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
     * Bug 2 regression: `fields=[uid, image]` (FAL field without CType) must still
     * expand the references. Previously CType was silently dropped from the record
     * before `includeRelations` ran, so the relation serializer couldn't tell the
     * field applied and fell back to the raw DB counter.
     */
    public function testFalExpansionWithFieldsFilterMissingType(): void
    {
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 120,
            'fields' => ['uid', 'image'],
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        $this->assertArrayNotHasKey('CType', $record, 'CType must not leak into the response when not requested');
        $this->assertArrayHasKey('image', $record);
        $this->assertIsArray($record['image'], 'image must be expanded to a reference array, not a counter');
        $this->assertCount(1, $record['image']);
        $this->assertSame(500, (int)$record['image'][0]['uid']);
        $this->assertArrayHasKey('file', $record['image'][0]);
        $this->assertSame(1, (int)$record['image'][0]['file']['uid']);
    }

    public function testFalExpansionWithExplicitTypeFieldIsUnchanged(): void
    {
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 120,
            'fields' => ['uid', 'CType', 'image'],
        ]);

        $this->assertSuccessfulToolResult($result);
        $record = $this->extractJsonFromResult($result)['records'][0];
        $this->assertSame('image', $record['CType']);
        $this->assertIsArray($record['image']);
        $this->assertCount(1, $record['image']);
    }

    /**
     * Workspace implementation details (`_ORIG_uid`, `t3ver_*`) must not leak to the
     * client. CLAUDE.md: "workspaces must be invisible to the MCP client".
     * Create a workspace modification on a live reference so workspaceOL actually
     * does something, then verify the response is clean.
     */
    public function testWorkspaceFieldsAreNotExposedOnFalChildren(): void
    {
        $workspaceId = $this->createAndSwitchToWorkspace('FAL Overlay Test');

        // Forge a workspace modification on sys_file_reference 500 with a new title.
        // Written directly (not via DataHandler) so the assertion is purely about
        // the read-side strip, not about the write pipeline.
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert(
            'sys_file_reference',
            [
                'pid' => 1,
                'tstamp' => 1734875900,
                'crdate' => 1734875000,
                'sys_language_uid' => 0,
                'uid_local' => 1,
                'uid_foreign' => 120,
                'tablenames' => 'tt_content',
                'fieldname' => 'image',
                'sorting_foreign' => 1,
                'title' => 'Hero image title overlaid',
                'alternative' => 'Hero alt text overlaid',
                'description' => 'Hero description',
                'link' => '',
                'crop' => '{}',
                't3ver_oid' => 500,
                't3ver_wsid' => $workspaceId,
                't3ver_state' => 0,
                't3ver_stage' => 0,
            ]
        );

        $result = $this->readTool->execute(['table' => 'tt_content', 'uid' => 120]);
        $this->assertSuccessfulToolResult($result);
        $record = $this->extractJsonFromResult($result)['records'][0];

        // Workspace modification must be visible (overlay worked).
        $this->assertSame('Hero alt text overlaid', $record['image'][0]['alternative']);
        // Live UID must be exposed, not the workspace placeholder UID.
        $this->assertSame(500, (int)$record['image'][0]['uid']);

        // No workspace internals on the child record or its embedded sys_file.
        $forbidden = ['_ORIG_uid', '_ORIG_pid', 't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage'];
        foreach ($forbidden as $key) {
            $this->assertArrayNotHasKey($key, $record['image'][0], "field '{$key}' must be stripped from FAL children");
            if (isset($record['image'][0]['file'])) {
                $this->assertArrayNotHasKey($key, $record['image'][0]['file'], "field '{$key}' must be stripped from embedded sys_file");
            }
            $this->assertArrayNotHasKey($key, $record, "field '{$key}' must be stripped from the parent record");
        }
    }

    public function testNonRelationFieldsDoNotAutoAddTypeField(): void
    {
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => 120,
            'fields' => ['uid', 'header'],
        ]);

        $this->assertSuccessfulToolResult($result);
        $record = $this->extractJsonFromResult($result)['records'][0];
        $this->assertArrayNotHasKey('CType', $record, 'CType must not be auto-added when no type-scoped relation is requested');
        $this->assertArrayNotHasKey('image', $record);
        $this->assertArrayHasKey('header', $record);
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
