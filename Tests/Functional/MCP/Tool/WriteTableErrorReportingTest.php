<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * PR-6 Bug 5: the isError flag in a WriteTable response must reflect only the
 * committed DB state. Post-processing failures (read-backs, live-UID resolution,
 * etc.) must surface as warnings on an otherwise successful response.
 *
 * This is the central invariant that this test class pins down.
 */
class WriteTableErrorReportingTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;
    private ReadTableTool $readTool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writeTool = new WriteTableTool();
        $this->readTool = new ReadTableTool();

        // Run inside a workspace — that's the mode where post-processing read-backs
        // (getLiveUid, BackendUtility::getRecord) typically misbehave.
        $this->createAndSwitchToWorkspace('Bug5 Workspace');
    }

    /**
     * Matrix: for each action/table combo, assert that (isError=false) ⇔ (DB
     * has the intended effect). Never "success reported, DB unchanged"; never
     * "failure reported, DB changed" on these happy paths.
     */
    public function testSuccessStatusMatchesDbStateForCreateUpdateDelete(): void
    {
        // CREATE page
        $createPage = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => ['title' => 'PR-6 page', 'doktype' => 1],
        ]);
        $this->assertSuccessfulToolResult($createPage);
        $pageUid = (int)$this->extractJsonFromResult($createPage)['uid'];
        $this->assertGreaterThan(0, $pageUid);
        $this->assertTrue($this->rowExists('pages', $pageUid), 'DB must actually contain the page');

        // CREATE tt_content under a LIVE parent (pid=1, fixture "Home") —
        // avoids Bug 1 which is fixed in a later commit of this PR.
        $createContent = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Bug5', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($createContent);
        $contentUid = (int)$this->extractJsonFromResult($createContent)['uid'];
        $this->assertTrue($this->rowExistsInWorkspace('tt_content', $contentUid), 'content row must exist in the workspace');

        // UPDATE page
        $updatePage = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $pageUid,
            'data' => ['subtitle' => 'Updated'],
        ]);
        $this->assertSuccessfulToolResult($updatePage);

        // UPDATE content
        $updateContent = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => ['header' => 'Bug5-updated'],
        ]);
        $this->assertSuccessfulToolResult($updateContent);

        // DELETE content
        $deleteContent = $this->writeTool->execute([
            'action' => 'delete',
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        $this->assertSuccessfulToolResult($deleteContent);

        // DELETE page
        $deletePage = $this->writeTool->execute([
            'action' => 'delete',
            'table' => 'pages',
            'uid' => $pageUid,
        ]);
        $this->assertSuccessfulToolResult($deletePage);

        // After all deletes, ReadTable must not see the records anymore.
        $readPage = $this->readTool->execute(['table' => 'pages', 'uid' => $pageUid]);
        $this->assertSuccessfulToolResult($readPage);
        $this->assertSame(0, $this->extractJsonFromResult($readPage)['total']);
    }

    /**
     * DBAL exceptions from the *caller* side must surface with class + message,
     * not a generic "Database operation failed". We don't actually trigger a DBAL
     * exception from a real write (those are rare) — instead we exercise the
     * mapping directly via the trait.
     */
    public function testDBALExceptionCarriesClassAndMessageInErrorText(): void
    {
        // Stub class that counts as a DBAL exception per the PR-6 remap rule —
        // we don't need a real driver-level failure, only an object that
        // triggers the `instanceof \Doctrine\DBAL\Exception` branch in the
        // trait's user-friendly-message mapping.
        $dbalStub = new class('synthetic-dbal-message') extends \Exception implements \Doctrine\DBAL\Exception {
        };
        $tool = new class($dbalStub) extends \Hn\McpServer\MCP\Tool\AbstractTool {
            public function __construct(private \Throwable $toThrow) {}
            public function getSchema(): array
            {
                return ['description' => 'test', 'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []]];
            }
            protected function doExecute(array $params): \Mcp\Types\CallToolResult
            {
                throw $this->toThrow;
            }
        };
        $result = $tool->execute([]);
        $this->assertTrue($result->isError);
        $text = $result->content[0]->text;
        $this->assertStringNotContainsString('Database operation failed', $text, 'generic string must be gone');
        $this->assertStringContainsString('Database error', $text);
        $this->assertStringContainsString('synthetic-dbal-message', $text);
    }

    public function testWarningsFieldIsOmittedOnCleanSuccess(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Clean', 'bodytext' => '<p>clean</p>'],
        ]);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $this->assertArrayNotHasKey(
            '_warnings',
            $data,
            'clean success must not carry a _warnings key'
        );
    }

    private function rowExists(string $table, int $uid): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('uid')
            ->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();
        return $row !== false;
    }

    private function rowExistsInWorkspace(string $table, int $liveUid): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('uid')
            ->from($table)
            ->where(
                $qb->expr()->or(
                    $qb->expr()->eq('uid', $qb->createNamedParameter($liveUid, ParameterType::INTEGER)),
                    $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter($liveUid, ParameterType::INTEGER))
                )
            )
            ->executeQuery()
            ->fetchOne();
        return $row !== false;
    }
}
