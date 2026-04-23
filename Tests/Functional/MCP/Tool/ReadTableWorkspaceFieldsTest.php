<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * PR-7 Commit A: diagnostic flag on ReadTable that un-strips workspace and
 * localization metadata columns. Normally hidden for transparency; we expose
 * them on request so bugs around workspace state can be debugged without an
 * external SQL session.
 */
class ReadTableWorkspaceFieldsTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private ReadTableTool $readTool;
    private WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readTool = new ReadTableTool();
        $this->writeTool = new WriteTableTool();
        $this->createAndSwitchToWorkspace('PR7 ReadTable diag');
    }

    public function testDefaultResponseKeepsStrippingWorkspaceMetadata(): void
    {
        $create = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Default read', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($create);
        $uid = (int)$this->extractJsonFromResult($create)['uid'];

        $read = $this->readTool->execute(['table' => 'tt_content', 'uid' => $uid]);
        $this->assertSuccessfulToolResult($read);
        $record = $this->extractJsonFromResult($read)['records'][0];

        foreach (['t3ver_state', 't3ver_oid', 't3ver_wsid', 't3ver_stage'] as $stripped) {
            $this->assertArrayNotHasKey($stripped, $record, "default response must not include {$stripped}");
        }
    }

    public function testIncludeWorkspaceFieldsExposesT3VerMetadata(): void
    {
        $create = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Diag read', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($create);
        $uid = (int)$this->extractJsonFromResult($create)['uid'];

        $read = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => $uid,
            'includeWorkspaceFields' => true,
        ]);
        $this->assertSuccessfulToolResult($read);
        $record = $this->extractJsonFromResult($read)['records'][0];

        // Workspace-new records have t3ver_state=1, t3ver_oid=0, t3ver_wsid>0.
        $this->assertArrayHasKey('t3ver_state', $record);
        $this->assertArrayHasKey('t3ver_oid', $record);
        $this->assertArrayHasKey('t3ver_wsid', $record);
        $this->assertArrayHasKey('t3ver_stage', $record);
        $this->assertSame(1, (int)$record['t3ver_state'], 'new workspace record should carry NEW_PLACEHOLDER state');
        $this->assertSame(0, (int)$record['t3ver_oid'], 'NEW_PLACEHOLDER has no live counterpart');
        $this->assertGreaterThan(0, (int)$record['t3ver_wsid'], 'must live inside the active workspace');
    }

    public function testIncludeWorkspaceFieldsStillStripsOrigMarkers(): void
    {
        // The _ORIG_* markers are a core-internal overlay artifact with no
        // meaning outside BackendUtility::workspaceOL; the flag must not bring
        // them back.
        $create = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'No orig', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($create);
        $uid = (int)$this->extractJsonFromResult($create)['uid'];

        $read = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => $uid,
            'includeWorkspaceFields' => true,
        ]);
        $this->assertSuccessfulToolResult($read);
        $record = $this->extractJsonFromResult($read)['records'][0];

        $this->assertArrayNotHasKey('_ORIG_uid', $record);
        $this->assertArrayNotHasKey('_ORIG_pid', $record);
        $this->assertArrayNotHasKey('_ORIG_t3ver_oid', $record);
    }
}
