<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PR-6 Bug 1: creating a tt_content whose pid points at a page that was just
 * created in the workspace (t3ver_state=1, no live counterpart) used to fail
 * with DBAL "MySQL server has gone away" — a red herring from a query that
 * DataHandler built without a ServerRequest in scope.
 *
 * Fix: withSyntheticRequest injects a best-effort backend request so
 * DataHandler's own parent-resolution has a site + applicationType.
 */
class WriteTableWorkspaceNewParentTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;
    private ReadTableTool $readTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSingleLanguageSiteConfiguration();

        $this->writeTool = new WriteTableTool();
        $this->readTool = new ReadTableTool();

        $this->createAndSwitchToWorkspace('PR6 Workspace-New Parent');
    }

    public function testCreatePageAndChildInSameWorkspaceSession(): void
    {
        // 1. Page in workspace
        $createPage = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => ['title' => 'WS-new parent', 'doktype' => 1],
        ]);
        $this->assertSuccessfulToolResult($createPage);
        $pageUid = (int)$this->extractJsonFromResult($createPage)['uid'];
        $this->assertGreaterThan(0, $pageUid);

        // 2. tt_content whose pid IS that workspace-new page — the Bug 1 repro.
        $createContent = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid,
            'data' => ['CType' => 'text', 'header' => 'On new page', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($createContent);
        $contentUid = (int)$this->extractJsonFromResult($createContent)['uid'];
        $this->assertGreaterThan(0, $contentUid);

        // DB sanity: the content row exists and its pid points at the page.
        $contentRow = $this->fetchAnyVersion('tt_content', $contentUid);
        $this->assertNotEmpty($contentRow);
        $this->assertSame($pageUid, (int)$contentRow['pid']);
    }

    public function testUpdateOnWorkspaceNewPageSucceeds(): void
    {
        $create = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => ['title' => 'WS-new for update', 'doktype' => 1],
        ]);
        $this->assertSuccessfulToolResult($create);
        $pageUid = (int)$this->extractJsonFromResult($create)['uid'];

        $update = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $pageUid,
            'data' => ['subtitle' => 'updated in same session'],
        ]);
        $this->assertSuccessfulToolResult($update);

        $read = $this->readTool->execute(['table' => 'pages', 'uid' => $pageUid]);
        $this->assertSuccessfulToolResult($read);
        $records = $this->extractJsonFromResult($read)['records'];
        $this->assertCount(1, $records);
        $this->assertSame('updated in same session', $records[0]['subtitle']);
    }

    public function testDeleteWorkspaceNewPageCascadesChildren(): void
    {
        $page = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => ['title' => 'WS-new for delete', 'doktype' => 1],
        ]);
        $this->assertSuccessfulToolResult($page);
        $pageUid = (int)$this->extractJsonFromResult($page)['uid'];

        $content = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid,
            'data' => ['CType' => 'text', 'header' => 'Will be cascaded', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($content);
        $contentUid = (int)$this->extractJsonFromResult($content)['uid'];

        $delete = $this->writeTool->execute([
            'action' => 'delete',
            'table' => 'pages',
            'uid' => $pageUid,
        ]);
        $this->assertSuccessfulToolResult($delete);

        // Page and content are both hidden from reads after the workspace delete.
        $pageRead = $this->readTool->execute(['table' => 'pages', 'uid' => $pageUid]);
        $this->assertSuccessfulToolResult($pageRead);
        $this->assertSame(0, $this->extractJsonFromResult($pageRead)['total']);

        $contentRead = $this->readTool->execute(['table' => 'tt_content', 'uid' => $contentUid]);
        $this->assertSuccessfulToolResult($contentRead);
        $this->assertSame(0, $this->extractJsonFromResult($contentRead)['total']);
    }

    private function fetchAnyVersion(string $table, int $liveUid): array
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

    private function createSingleLanguageSiteConfiguration(): void
    {
        $config = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'PR6 Test Site',
            'languages' => [[
                'title' => 'English',
                'enabled' => true,
                'languageId' => 0,
                'base' => '/',
                'locale' => 'en_US.UTF-8',
                'iso-639-1' => 'en',
                'hreflang' => 'en-us',
                'direction' => 'ltr',
                'flag' => 'us',
                'navigationTitle' => 'English',
            ]],
            'routes' => [],
            'errorHandling' => [],
        ];
        $path = $this->instancePath . '/typo3conf/sites/pr6-test';
        GeneralUtility::mkdir_deep($path);
        GeneralUtility::writeFile($path . '/config.yaml', Yaml::dump($config, 99, 2), true);
    }
}
