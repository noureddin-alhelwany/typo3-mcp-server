<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * PR-7 Commit C: PageTSconfig `TCAdefaults.<table>.<field>` values must reach
 * records created via MCP WriteTable, matching what the BE form's
 * DatabaseRowInitializeNew provider does. Without it, theme-conditional
 * Fluid templates (e.g. a template that renders only when `color` is set)
 * produce an empty CE until an editor manually opens-and-saves the record.
 */
class WriteTablePageTsDefaultsTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;
    private ReadTableTool $readTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeTool = new WriteTableTool();
        $this->readTool = new ReadTableTool();
        $this->createAndSwitchToWorkspace('PR7 PageTS defaults');
    }

    public function testFieldLevelTCAdefaultIsApplied(): void
    {
        $this->seedPageTsConfig(1, "TCAdefaults.tt_content.header_layout = 5\n");

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Has layout default', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($result);
        $uid = (int)$this->extractJsonFromResult($result)['uid'];

        $row = $this->fetchRawRow('tt_content', $uid);
        $this->assertSame('5', (string)$row['header_layout'], 'PageTSconfig TCAdefault must land on the new row');
    }

    public function testTypeSpecificTCAdefaultIsAppliedForMatchingType(): void
    {
        $this->seedPageTsConfig(
            1,
            "TCAdefaults.tt_content.header_layout.types.textmedia = 3\n"
            . "TCAdefaults.tt_content.header_layout.types.text = 2\n"
        );

        // Create textmedia → expects header_layout = 3.
        $resultMedia = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'textmedia', 'header' => 'Media', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($resultMedia);
        $mediaUid = (int)$this->extractJsonFromResult($resultMedia)['uid'];
        $this->assertSame('3', (string)$this->fetchRawRow('tt_content', $mediaUid)['header_layout']);

        // Create text → expects header_layout = 2.
        $resultText = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Text', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($resultText);
        $textUid = (int)$this->extractJsonFromResult($resultText)['uid'];
        $this->assertSame('2', (string)$this->fetchRawRow('tt_content', $textUid)['header_layout']);
    }

    public function testExplicitCallerValueWinsOverPageTsDefault(): void
    {
        $this->seedPageTsConfig(1, "TCAdefaults.tt_content.header_layout = 5\n");

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Has explicit',
                'bodytext' => '<p>x</p>',
                'header_layout' => 100,
            ],
        ]);
        $this->assertSuccessfulToolResult($result);
        $uid = (int)$this->extractJsonFromResult($result)['uid'];
        $this->assertSame('100', (string)$this->fetchRawRow('tt_content', $uid)['header_layout']);
    }

    public function testCreateWithoutAnyPageTsConfigStillWorks(): void
    {
        // No TSconfig seeded — must not fail even though there's nothing to apply.
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Clean', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($result);
    }

    /**
     * Drop a TSconfig blob onto the given page row. Running parent pages are
     * in the rootline already because pid=1 is the site root.
     */
    private function seedPageTsConfig(int $pageUid, string $tsconfig): void
    {
        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => $tsconfig],
            ['uid' => $pageUid]
        );
    }

    private function fetchRawRow(string $table, int $uid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        // A workspace version points at the live uid via t3ver_oid. The WRITE-side
        // synth helper just needs the actual row DataHandler produced.
        $row = $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->or(
                    $qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)),
                    $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter($uid, ParameterType::INTEGER))
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: [];
    }
}
