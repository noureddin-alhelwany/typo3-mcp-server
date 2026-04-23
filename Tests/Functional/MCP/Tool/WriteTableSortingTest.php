<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * PR-6 Bug 2: three consecutive create tt_content calls on the same (pid,
 * colPos) without an explicit position landed with REVERSED sorting — newest
 * on top instead of bottom. Root cause: an explicit sorting value competes
 * with DataHandler's own getSortNumber() on positive pids. Fix: use the
 * native "insert after the last record" convention (pid=-lastUid) and scope
 * the last-record lookup to the target colPos.
 */
class WriteTableSortingTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeTool = new WriteTableTool();
        $this->createAndSwitchToWorkspace('PR6 Sorting Workspace');
    }

    public function testThreeConsecutiveCreatesAppendInCreationOrder(): void
    {
        $uids = [];
        foreach (['First', 'Second', 'Third'] as $label) {
            $result = $this->writeTool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => 1,
                'data' => ['CType' => 'text', 'header' => $label, 'bodytext' => '<p>x</p>'],
            ]);
            $this->assertSuccessfulToolResult($result);
            $uids[] = (int)$this->extractJsonFromResult($result)['uid'];
        }

        // Fetch all three rows from the workspace and verify sorting values
        // are strictly ascending in creation order.
        $sortings = [];
        foreach ($uids as $uid) {
            $sortings[] = $this->fetchSorting('tt_content', $uid);
        }

        $this->assertSame(
            $sortings,
            array_values(array_unique($sortings)),
            'sortings must be distinct'
        );
        $sorted = $sortings;
        sort($sorted);
        $this->assertSame(
            $sorted,
            $sortings,
            'creation order must match ascending sorting (First < Second < Third)'
        );
    }

    public function testBottomPositionIsScopedToColPos(): void
    {
        // Seed colPos=2 with a single record — it carries the "globally" largest
        // sorting by virtue of being the only one. Without colPos scoping, the
        // subsequent create on colPos=0 would anchor to THIS record and land
        // on the wrong column.
        $seed = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Seed col2', 'colPos' => 2, 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($seed);
        $seedUid = (int)$this->extractJsonFromResult($seed)['uid'];

        $target = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Target col0', 'colPos' => 0, 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($target);
        $targetUid = (int)$this->extractJsonFromResult($target)['uid'];

        // Target must be on colPos=0 regardless of the colPos=2 seed's sorting.
        $targetRow = $this->fetchRow('tt_content', $targetUid);
        $this->assertSame(0, (int)$targetRow['colPos'], 'new record must land on the requested colPos');
        // Seed stayed put.
        $seedRow = $this->fetchRow('tt_content', $seedUid);
        $this->assertSame(2, (int)$seedRow['colPos']);
    }

    public function testCreateOnEmptyColumnWorks(): void
    {
        // An empty page/column: no last-record to anchor to — the tool must
        // still succeed, falling back to DataHandler's first-record logic.
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'On empty', 'colPos' => 3, 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($result);
        $uid = (int)$this->extractJsonFromResult($result)['uid'];
        $row = $this->fetchRow('tt_content', $uid);
        $this->assertSame(3, (int)$row['colPos']);
    }

    private function fetchSorting(string $table, int $liveUid): int
    {
        $row = $this->fetchRowAnyVersion($table, $liveUid);
        $this->assertNotEmpty($row, 'row must exist');
        return (int)$row['sorting'];
    }

    private function fetchRow(string $table, int $liveUid): array
    {
        return $this->fetchRowAnyVersion($table, $liveUid);
    }

    private function fetchRowAnyVersion(string $table, int $liveUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->or(
                    $qb->expr()->eq('uid', $qb->createNamedParameter($liveUid, ParameterType::INTEGER)),
                    $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter($liveUid, ParameterType::INTEGER))
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: [];
    }
}
