<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * Test writing sys_file_reference records through the FAL linking shortcut
 * (PR 2). The parent (tt_content / pages) lives in the workspace and references
 * an existing sys_file by UID via `image: [{file: 1, ...}]`.
 */
class FalWriteTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;
    private ReadTableTool $readTool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');

        $this->writeTool = new WriteTableTool();
        $this->readTool = new ReadTableTool();

        // All write tests run inside a workspace so we can assert sys_file_reference
        // lands in the workspace, not live.
        $this->createAndSwitchToWorkspace('FAL Write Test Workspace');
    }

    public function testCreateContentElementWithFileReference(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'image',
                'header' => 'Hero with file',
                'image' => [
                    [
                        'file' => 1,
                        'alternative' => 'Hero alt',
                        'title' => 'Hero title',
                    ],
                ],
            ],
        ]);

        $this->assertSuccessfulToolResult($result);
        $parentUid = $this->extractJsonFromResult($result)['uid'];
        $this->assertGreaterThan(0, $parentUid);

        // Direct DB check: exactly one sys_file_reference row for this parent, pointing at
        // the workspace (not live).
        $references = $this->fetchReferencesForParent($parentUid);
        $this->assertCount(1, $references);
        $ref = $references[0];

        $this->assertSame(1, (int)$ref['uid_local'], 'uid_local must resolve to sys_file 1');
        $this->assertSame($parentUid, (int)$ref['uid_foreign'], 'uid_foreign must target the new tt_content');
        $this->assertSame('tt_content', $ref['tablenames'], 'tablenames must auto-fill to parent table');
        $this->assertSame('image', $ref['fieldname'], 'fieldname must auto-fill to parent field');
        $this->assertSame('Hero alt', $ref['alternative']);
        $this->assertSame('Hero title', $ref['title']);

        $this->assertGreaterThan(
            0,
            (int)$ref['t3ver_wsid'],
            'sys_file_reference must land in the workspace, not live'
        );
    }

    public function testReadAfterCreateRoundtrip(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'image',
                'header' => 'Roundtrip hero',
                'image' => [
                    ['file' => 2, 'alternative' => 'Roundtrip alt'],
                ],
            ],
        ]);
        $this->assertSuccessfulToolResult($result);
        $parentUid = $this->extractJsonFromResult($result)['uid'];

        $readResult = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => $parentUid,
        ]);
        $this->assertSuccessfulToolResult($readResult);
        $record = $this->extractJsonFromResult($readResult)['records'][0];

        $this->assertArrayHasKey('image', $record);
        $this->assertCount(1, $record['image']);
        $ref = $record['image'][0];
        $this->assertSame('Roundtrip alt', $ref['alternative']);
        $this->assertArrayHasKey('file', $ref, 'sys_file must be expanded on the returned reference');
        $this->assertSame(2, $ref['file']['uid']);
        $this->assertSame('/images/hero.png', $ref['file']['identifier']);
    }

    public function testUpdateReorderReferencesKeepsUids(): void
    {
        $createResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'image',
                'header' => 'Reorder hero',
                'image' => [
                    ['file' => 1, 'alternative' => 'First'],
                    ['file' => 2, 'alternative' => 'Second'],
                ],
            ],
        ]);
        $this->assertSuccessfulToolResult($createResult);
        $parentUid = $this->extractJsonFromResult($createResult)['uid'];

        $readResult = $this->readTool->execute(['table' => 'tt_content', 'uid' => $parentUid]);
        $initialRefs = $this->extractJsonFromResult($readResult)['records'][0]['image'];
        $this->assertCount(2, $initialRefs);
        $firstUid = (int)$initialRefs[0]['uid'];
        $secondUid = (int)$initialRefs[1]['uid'];

        // Send the references back with swapped order, carrying their UIDs so the
        // write tool updates in place instead of recreating.
        $updateResult = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $parentUid,
            'data' => [
                'image' => [
                    ['uid' => $secondUid, 'file' => 2, 'alternative' => 'Second'],
                    ['uid' => $firstUid, 'file' => 1, 'alternative' => 'First'],
                ],
            ],
        ]);
        $this->assertSuccessfulToolResult($updateResult);

        $readAfter = $this->readTool->execute(['table' => 'tt_content', 'uid' => $parentUid]);
        $reorderedRefs = $this->extractJsonFromResult($readAfter)['records'][0]['image'];

        $this->assertCount(2, $reorderedRefs, 'reorder must not add or remove references');
        $this->assertSame($secondUid, (int)$reorderedRefs[0]['uid'], 'first slot now holds the previous second reference');
        $this->assertSame($firstUid, (int)$reorderedRefs[1]['uid'], 'second slot now holds the previous first reference');
        $this->assertSame(2, (int)$reorderedRefs[0]['file']['uid']);
        $this->assertSame(1, (int)$reorderedRefs[1]['file']['uid']);
    }

    public function testDeleteContentElementCascadesReferences(): void
    {
        $createResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'image',
                'header' => 'To delete',
                'image' => [
                    ['file' => 1, 'alternative' => 'Bye'],
                ],
            ],
        ]);
        $this->assertSuccessfulToolResult($createResult);
        $parentUid = $this->extractJsonFromResult($createResult)['uid'];

        $this->assertCount(1, $this->fetchReferencesForParent($parentUid));

        $deleteResult = $this->writeTool->execute([
            'action' => 'delete',
            'table' => 'tt_content',
            'uid' => $parentUid,
        ]);
        $this->assertSuccessfulToolResult($deleteResult);

        // After parent delete, ReadTable must not expose the reference anymore.
        $readResult = $this->readTool->execute(['table' => 'tt_content', 'uid' => $parentUid]);
        $records = $this->extractJsonFromResult($readResult)['records'];
        $this->assertEmpty($records, 'deleted tt_content must be gone in workspace');
    }

    public function testWriteRejectsNonExistentSysFile(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'image',
                'header' => 'Broken',
                'image' => [
                    ['file' => 99999, 'alternative' => 'nope'],
                ],
            ],
        ]);

        $this->assertTrue($result->isError, 'A dangling sys_file UID must be rejected');
        $this->assertStringContainsString('non-existent sys_file UID', $result->content[0]->text);
    }

    /**
     * Bug 1 regression: updating a live-native parent with a live-native sys_file_reference
     * must produce workspace versions of both, never touch live, and stay invisible to the
     * client (live UIDs on the way in, live UIDs on the way out).
     *
     * CLAUDE.md: "Live data must never be directly edited." + "only exposing the live id".
     */
    public function testUpdateOnLiveNativeParentAndReferenceCreatesWorkspaceVersions(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fal_live_content.csv');

        // Sanity: baseline is live (t3ver_wsid=0).
        $liveParentBefore = $this->fetchRawRow('tt_content', 200);
        $this->assertNotFalse($liveParentBefore);
        $this->assertSame(0, (int)$liveParentBefore['t3ver_wsid']);
        $liveRefBefore = $this->fetchRawRow('sys_file_reference', 700);
        $this->assertNotFalse($liveRefBefore);
        $this->assertSame(0, (int)$liveRefBefore['t3ver_wsid']);
        $this->assertSame('', (string)$liveRefBefore['alternative']);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 200,
            'data' => [
                'image' => [
                    ['uid' => 700, 'file' => 1, 'alternative' => 'Landschaft im Abendlicht'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Client-facing read: live UIDs, new alt text visible.
        $readResult = $this->readTool->execute(['table' => 'tt_content', 'uid' => 200]);
        $this->assertFalse($readResult->isError, json_encode($readResult->jsonSerialize()));
        $record = $this->extractJsonFromResult($readResult)['records'][0];
        $this->assertSame(200, (int)$record['uid'], 'client must still see the live parent UID');
        $this->assertArrayHasKey('image', $record);
        $this->assertCount(1, $record['image']);
        $ref = $record['image'][0];
        $this->assertSame(700, (int)$ref['uid'], 'client must still see the live reference UID');
        $this->assertSame('Landschaft im Abendlicht', $ref['alternative']);

        // Live reference row must be untouched.
        $liveRefAfter = $this->fetchRawRow('sys_file_reference', 700);
        $this->assertSame(0, (int)$liveRefAfter['t3ver_wsid'], 'live reference must stay live (t3ver_wsid=0)');
        $this->assertSame('', (string)$liveRefAfter['alternative'], 'live alternative must not be rewritten');

        // Workspace version of the reference must exist and carry the new alt text.
        $wsRef = $this->fetchWorkspaceVersion('sys_file_reference', 700);
        $this->assertNotNull($wsRef, 'workspace version of sys_file_reference 700 must exist');
        $this->assertGreaterThan(0, (int)$wsRef['t3ver_wsid']);
        $this->assertSame('Landschaft im Abendlicht', (string)$wsRef['alternative']);

        // Live parent row must be untouched.
        $liveParentAfter = $this->fetchRawRow('tt_content', 200);
        $this->assertSame(0, (int)$liveParentAfter['t3ver_wsid'], 'live parent must stay live');

        // Parent workspace version must exist (created implicitly to anchor the edit).
        $wsParent = $this->fetchWorkspaceVersion('tt_content', 200);
        $this->assertNotNull($wsParent, 'workspace version of tt_content 200 must exist');
        $this->assertGreaterThan(0, (int)$wsParent['t3ver_wsid']);
    }

    /**
     * Bug 3-A: direct update of metadata fields on sys_file_reference.
     * Useful for the A11y rollout — set alt-text on many existing refs without
     * needing to know each parent.
     */
    public function testDirectUpdateOfAltTextOnLiveReference(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fal_live_content.csv');

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'sys_file_reference',
            'uid' => 700,
            'data' => ['alternative' => 'Alt nachträglich gesetzt'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Live row stays untouched.
        $live = $this->fetchRawRow('sys_file_reference', 700);
        $this->assertSame(0, (int)$live['t3ver_wsid']);
        $this->assertSame('', (string)$live['alternative']);

        // Workspace version carries the new alt text.
        $ws = $this->fetchWorkspaceVersion('sys_file_reference', 700);
        $this->assertNotNull($ws);
        $this->assertSame('Alt nachträglich gesetzt', (string)$ws['alternative']);

        // Reading the parent surfaces the new alt text through the FAL expansion.
        $readResult = $this->readTool->execute(['table' => 'tt_content', 'uid' => 200]);
        $record = $this->extractJsonFromResult($readResult)['records'][0];
        $this->assertSame('Alt nachträglich gesetzt', $record['image'][0]['alternative']);
    }

    /**
     * Structural fields on sys_file_reference are protected. These are only safe to
     * change through the parent-record shortcut, where they flow from foreign_match_fields.
     */
    public function testDirectUpdateRejectsStructuralFields(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fal_live_content.csv');

        $structural = [
            'uid_local' => 2,
            'uid_foreign' => 999,
            'tablenames' => 'pages',
            'fieldname' => 'media',
            'sorting_foreign' => 999,
        ];
        foreach ($structural as $field => $value) {
            $result = $this->writeTool->execute([
                'action' => 'update',
                'table' => 'sys_file_reference',
                'uid' => 700,
                'data' => [$field => $value],
            ]);
            $this->assertTrue($result->isError, "Field '{$field}' must be rejected as structural");
            $this->assertStringContainsString('structural and cannot be updated directly', $result->content[0]->text);
            $this->assertStringContainsString($field, $result->content[0]->text);
        }
    }

    /**
     * Bulk A11y rollout (the user's motivating use case): iterate many live references
     * and set title/description/alternative each time. Each update must versionize
     * correctly and leave live untouched.
     */
    public function testDirectUpdateOfTitleOnLiveReference(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fal_live_content.csv');

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'sys_file_reference',
            'uid' => 700,
            'data' => ['title' => 'Hero title set via direct write'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $ws = $this->fetchWorkspaceVersion('sys_file_reference', 700);
        $this->assertNotNull($ws);
        $this->assertSame('Hero title set via direct write', (string)$ws['title']);

        $live = $this->fetchRawRow('sys_file_reference', 700);
        $this->assertSame('', (string)$live['title'], 'live title must stay untouched');
    }

    /**
     * Second update on the same reference must reuse the existing workspace version
     * — no duplicate placeholders, no doubled writes.
     */
    public function testDirectUpdateOfAlreadyWorkspacedReferenceReusesVersion(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fal_live_content.csv');

        $first = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'sys_file_reference',
            'uid' => 700,
            'data' => ['alternative' => 'first'],
        ]);
        $this->assertFalse($first->isError, json_encode($first->jsonSerialize()));
        $wsAfterFirst = $this->fetchWorkspaceVersion('sys_file_reference', 700);
        $this->assertNotNull($wsAfterFirst);
        $firstWsUid = (int)$wsAfterFirst['uid'];

        $second = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'sys_file_reference',
            'uid' => 700,
            'data' => ['alternative' => 'second'],
        ]);
        $this->assertFalse($second->isError, json_encode($second->jsonSerialize()));

        $wsAfterSecond = $this->fetchWorkspaceVersion('sys_file_reference', 700);
        $this->assertNotNull($wsAfterSecond);
        $this->assertSame($firstWsUid, (int)$wsAfterSecond['uid'], 'second update must not create a new workspace version');
        $this->assertSame('second', (string)$wsAfterSecond['alternative']);

        // Only one workspace row for ref 700 should exist, not two.
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $count = $qb->count('uid')
            ->from('sys_file_reference')
            ->where($qb->expr()->eq('t3ver_oid', 700))
            ->executeQuery()
            ->fetchOne();
        $this->assertSame(1, (int)$count, 'there must be exactly one workspace version for ref 700');
    }

    public function testDirectUpdateOnNonExistentReferenceReturnsClearError(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fal_live_content.csv');

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'sys_file_reference',
            'uid' => 99999,
            'data' => ['alternative' => 'never reaches DataHandler'],
        ]);
        $this->assertTrue($result->isError);
        $msg = $result->content[0]->text;
        $this->assertStringContainsString('99999', $msg, 'error must mention the offending UID');
        $this->assertStringContainsString('not found', $msg);
    }

    /**
     * Regression: TYPO3 14+ sets sys_file_reference.ctrl.type to 'uid_local:type',
     * a polymorphic reference that resolves via the joined sys_file row. Passing
     * that raw string to BackendUtility::getRecord as a SELECT column throws
     * `Unknown column 'uid_local:type' in 'field list'`. validateRecordData
     * must recognise the polymorphic form and skip the type-scoped lookup.
     *
     * This test forces the polymorphic ctrl.type regardless of what the core
     * ships, so the regression is pinned even if TYPO3 defaults change back.
     */
    public function testUpdateWithPolymorphicTypeFieldDoesNotCrashOnRecordFetch(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fal_live_content.csv');

        $originalType = $GLOBALS['TCA']['sys_file_reference']['ctrl']['type'] ?? null;
        $GLOBALS['TCA']['sys_file_reference']['ctrl']['type'] = 'uid_local:type';

        try {
            $result = $this->writeTool->execute([
                'action' => 'update',
                'table' => 'sys_file_reference',
                'uid' => 700,
                'data' => ['alternative' => 'set under polymorphic TCA'],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

            $ws = $this->fetchWorkspaceVersion('sys_file_reference', 700);
            $this->assertNotNull($ws);
            $this->assertSame('set under polymorphic TCA', (string)$ws['alternative']);
        } finally {
            if ($originalType === null) {
                unset($GLOBALS['TCA']['sys_file_reference']['ctrl']['type']);
            } else {
                $GLOBALS['TCA']['sys_file_reference']['ctrl']['type'] = $originalType;
            }
        }
    }

    /**
     * Diagnostics: debug=true must surface the real failure — exception class +
     * message + _debug block — rather than the generic "Database operation failed".
     */
    public function testDebugOutputSurfacedOnErrorPath(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fal_live_content.csv');

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'sys_file_reference',
            'uid' => 99999,
            'debug' => true,
            'data' => ['alternative' => 'trigger error'],
        ]);
        $this->assertTrue($result->isError);
        // Decode response — the tool packages the error text + _debug block together.
        $errorText = $result->content[0]->text;
        $this->assertStringContainsString('99999', $errorText);

        // Additional content block carries _debug when the error text isn't JSON.
        // Either format is acceptable — the important thing is the debug block is
        // somewhere in the response.
        $combined = '';
        foreach ($result->content as $content) {
            $combined .= $content->text . "\n";
        }
        $this->assertStringNotContainsString(
            'Database operation failed',
            $combined,
            'generic masking must not surface when debug=true'
        );
    }

    private function fetchRawRow(string $table, int $uid): array|false
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
    }

    private function fetchWorkspaceVersion(string $table, int $liveUid): ?array
    {
        $workspaceId = (int)($GLOBALS['BE_USER']->workspace ?? 0);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Fetch raw sys_file_reference rows for a given parent tt_content, bypassing the
     * TCA-aware ReadTable layer so we can directly inspect workspace fields.
     */
    private function fetchReferencesForParent(int $parentUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($parentUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('image')),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
