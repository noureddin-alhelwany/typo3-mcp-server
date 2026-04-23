<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\FrontendRenderAssertionsTrait;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PR-7 Commit D: end-to-end regression guard for "MCP create + preview must
 * render the content without a BE round-trip". Builds on Commits A (the
 * frontend render helper) and C (PageTSconfig defaults) and cashes them out
 * as actual rendered HTML assertions.
 *
 * The invariant:
 *
 *   create page P
 *   create tt_content C on P
 *   render P in workspace-preview mode
 *   → HTML contains C's header
 *
 * No synthetic BE-save in between. If this ever breaks, something closed
 * the gap only to re-open it — the test must fail LOUDLY.
 */
class WorkspacePreviewRenderTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;
    use FrontendRenderAssertionsTrait;

    private WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->createSiteConfiguration();
        $this->seedMinimalTypoScript();

        $this->writeTool = new WriteTableTool();
        $this->createAndSwitchToWorkspace('PR7 Render Regression');
    }

    public function testPlainTextCERendersInPreviewWithoutBeSave(): void
    {
        $workspaceId = (int)$GLOBALS['BE_USER']->workspace;

        $pageResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => ['title' => 'Text page', 'slug' => '/text-page', 'doktype' => 1],
        ]);
        $this->assertSuccessfulToolResult($pageResult);
        $pageUid = (int)$this->extractJsonFromResult($pageResult)['uid'];

        $ceResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid,
            'data' => ['CType' => 'text', 'header' => 'PR7-TEXT-MARKER', 'bodytext' => '<p>body</p>'],
        ]);
        $this->assertSuccessfulToolResult($ceResult);

        $html = $this->renderPagePreview($pageUid, $workspaceId);
        $this->assertFrontendContains($html, 'PR7-TEXT-MARKER');
    }

    public function testTextmediaCEWithFalShortcutRendersInPreview(): void
    {
        // This is the user's original reproduction case: textmedia + FAL shortcut.
        $workspaceId = (int)$GLOBALS['BE_USER']->workspace;

        $pageResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => ['title' => 'Media page', 'slug' => '/media-page', 'doktype' => 1],
        ]);
        $this->assertSuccessfulToolResult($pageResult);
        $pageUid = (int)$this->extractJsonFromResult($pageResult)['uid'];

        $ceResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'PR7-MEDIA-MARKER',
                'bodytext' => '<p>body</p>',
                'assets' => [['file' => 1, 'alternative' => 'alt']],
            ],
        ]);
        $this->assertSuccessfulToolResult($ceResult);

        $html = $this->renderPagePreview($pageUid, $workspaceId);
        $this->assertFrontendContains($html, 'PR7-MEDIA-MARKER');
    }

    public function testMultipleCEsRenderInCreationOrder(): void
    {
        $workspaceId = (int)$GLOBALS['BE_USER']->workspace;

        $pageResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => ['title' => 'Multi', 'slug' => '/multi', 'doktype' => 1],
        ]);
        $this->assertSuccessfulToolResult($pageResult);
        $pageUid = (int)$this->extractJsonFromResult($pageResult)['uid'];

        foreach (['PR7-A', 'PR7-B', 'PR7-C'] as $marker) {
            $result = $this->writeTool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => $pageUid,
                'data' => ['CType' => 'text', 'header' => $marker, 'bodytext' => '<p>b</p>'],
            ]);
            $this->assertSuccessfulToolResult($result);
        }

        $html = $this->renderPagePreview($pageUid, $workspaceId);
        // All three markers must be present.
        $this->assertFrontendContains($html, 'PR7-A');
        $this->assertFrontendContains($html, 'PR7-B');
        $this->assertFrontendContains($html, 'PR7-C');

        // And in creation order (covered by PR-6d sort fix; re-pinned here
        // because the render path is the only way to observe it end-to-end).
        $posA = strpos($html, 'PR7-A');
        $posB = strpos($html, 'PR7-B');
        $posC = strpos($html, 'PR7-C');
        $this->assertLessThan($posB, $posA, 'A must come before B in the rendered output');
        $this->assertLessThan($posC, $posB, 'B must come before C in the rendered output');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function seedMinimalTypoScript(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_template');
        $connection->insert('sys_template', [
            'pid' => 1,
            'root' => 1,
            'clear' => 3,
            'title' => 'PR7 regression root',
            'config' => 'page = PAGE' . "\n"
                . 'page.typeNum = 0' . "\n"
                . 'page.10 = CONTENT' . "\n"
                . 'page.10 {' . "\n"
                . '  table = tt_content' . "\n"
                . '  select {' . "\n"
                . '    orderBy = sorting' . "\n"
                . '    where = {#colPos}=0' . "\n"
                . '  }' . "\n"
                . '}' . "\n"
                // Minimal CType-agnostic renderer that exposes the header so
                // the regression asserts a visible marker without pulling in
                // fluid_styled_content.
                . 'tt_content = COA' . "\n"
                . 'tt_content.10 = TEXT' . "\n"
                . 'tt_content.10.field = header' . "\n"
                . 'tt_content.10.wrap = <div class="ce">|</div>' . "\n",
            'deleted' => 0,
            'hidden' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
    }

    private function createSiteConfiguration(): void
    {
        $config = [
            'rootPageId' => 1,
            'base' => 'http://localhost/',
            'websiteTitle' => 'PR7 Regression',
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
        $path = $this->instancePath . '/typo3conf/sites/pr7-regression';
        GeneralUtility::mkdir_deep($path);
        GeneralUtility::writeFile($path . '/config.yaml', Yaml::dump($config, 99, 2), true);
    }
}
