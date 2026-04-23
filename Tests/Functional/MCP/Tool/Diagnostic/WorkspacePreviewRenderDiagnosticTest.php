<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\Diagnostic;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\FrontendRenderAssertionsTrait;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PR-7 Commit B: DIAGNOSTIC test — surfaces the exact DB-field delta between
 * "record created via MCP WriteTable" and "that same record after a no-op
 * backend save". The user reproduction said the former doesn't render in
 * the preview while the latter does; we need the delta to know which fields
 * to fix in Commit C instead of guessing.
 *
 * This test is intentionally mixed:
 *
 *  - The rendering asserts (render_before vs render_after) pin the status-quo
 *    gap. If Commit C's fix closes it, these flip from "before=empty,
 *    after=present" to "both present" — at which point this diagnostic is
 *    superseded by the positive WorkspacePreviewRenderTest in Commit D, and
 *    this whole file becomes a candidate for deletion.
 *
 *  - The DB-state diff assertion is deliberately red. Its job is to print the
 *    delta in the failure message so the next step is informed. Commit C
 *    flips it to green by making the MCP-create produce the same state the
 *    BE-save would produce.
 */
class WorkspacePreviewRenderDiagnosticTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;
    use FrontendRenderAssertionsTrait;

    private WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file.csv');
        $this->createSiteConfiguration();
        $this->seedMinimalTypoScript();

        $this->writeTool = new WriteTableTool();
        $this->createAndSwitchToWorkspace('PR7 Render Diagnostic');
    }

    /**
     * The frontend middleware rejects requests for a page that has no
     * associated TypoScript template anywhere in the rootline. In production
     * an integrator attaches a site set or a sys_template row on the root
     * page; functional tests need to do the same thing explicitly, which is
     * what this helper provides — enough TypoScript to render the CONTENT
     * objects of a page and nothing else.
     */
    private function seedMinimalTypoScript(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_template');
        $connection->insert('sys_template', [
            'pid' => 1,
            'root' => 1,
            'clear' => 3,
            'title' => 'PR7 diag root',
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
                // Generic fallback so every CType renders at least its header.
                // We only care about whether the record surfaces in the pipeline
                // at all — proper theme rendering is a production concern.
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

    public function testReproducesRenderGapAndDumpsDbDelta(): void
    {
        $workspaceId = (int)($GLOBALS['BE_USER']->workspace ?? 0);
        $this->assertGreaterThan(0, $workspaceId, 'test must run inside a workspace');

        // 1) Create a page via MCP.
        $pageResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => ['title' => 'PR7 diag page', 'slug' => '/pr7-diag', 'doktype' => 1, 'hidden' => 0],
        ]);
        $this->assertSuccessfulToolResult($pageResult);
        $pageUid = (int)$this->extractJsonFromResult($pageResult)['uid'];

        // 2) Create a textmedia CE with a FAL reference via MCP.
        $ceResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'PR7-MARKER-HEADER',
                'bodytext' => '<p>PR7 diagnostic body</p>',
                'assets' => [['file' => 1, 'alternative' => 'PR7 alt']],
            ],
        ]);
        $this->assertSuccessfulToolResult($ceResult);
        $ceUid = (int)$this->extractJsonFromResult($ceResult)['uid'];

        // 3) DB state right after MCP create.
        $ceStateAfterMcp = $this->fetchRawRow('tt_content', $ceUid);
        $refStateAfterMcp = $this->fetchFirstReference('tt_content', $ceUid, 'assets');

        // 4) Render preview BEFORE any BE-save. Whether it contains the marker
        //    is the central observable of this bug; we record but don't assert
        //    either direction so the diagnostic stays informative even as the
        //    situation improves across the PR.
        $htmlBefore = $this->renderPagePreview($pageUid, $workspaceId);
        $containsMarkerBefore = str_contains($htmlBefore, 'PR7-MARKER-HEADER');

        // 5) Synthesize a no-op BE save on the CE to imitate opening+saving
        //    in the backend without changing any user-visible field.
        $this->synthesiseBackendSave('tt_content', $ceUid);

        // 6) DB state after the synthetic BE save.
        $ceStateAfterResave = $this->fetchRawRow('tt_content', $ceUid);
        $refStateAfterResave = $this->fetchFirstReference('tt_content', $ceUid, 'assets');

        // 7) Render preview after the "save". Record presence again.
        $htmlAfter = $this->renderPagePreview($pageUid, $workspaceId);
        $containsMarkerAfter = str_contains($htmlAfter, 'PR7-MARKER-HEADER');

        // 8) Diff the two DB states. The failure message IS the diagnostic —
        //    Commit C consumes this list.
        $ceDelta = $this->diffRows($ceStateAfterMcp, $ceStateAfterResave);
        $refDelta = $this->diffRows($refStateAfterMcp ?? [], $refStateAfterResave ?? []);

        $summary = sprintf(
            "Render marker present:\n  before BE-save: %s\n  after BE-save:  %s\n\n"
            . "HTML-BEFORE (first 800 chars):\n%s\n\n"
            . "HTML-AFTER (first 800 chars):\n%s\n\n"
            . "tt_content field delta (post-MCP vs post-save):\n%s\n\n"
            . "sys_file_reference field delta (post-MCP vs post-save):\n%s\n",
            $containsMarkerBefore ? 'YES' : 'NO',
            $containsMarkerAfter ? 'YES' : 'NO',
            substr($htmlBefore, 0, 800),
            substr($htmlAfter, 0, 800),
            $ceDelta === [] ? '  (no delta)' : $this->formatDelta($ceDelta),
            $refDelta === [] ? '  (no delta)' : $this->formatDelta($refDelta)
        );

        // The deliberate red — once Commit C closes the gap both deltas
        // should be empty AND both renders should contain the marker.
        $this->assertTrue(
            $ceDelta === [] && $refDelta === [] && $containsMarkerBefore && $containsMarkerAfter,
            $summary
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Imitate opening + saving a record in the backend without changing
     * anything visible. A real BE save submits the entire form payload back
     * to DataHandler — including fields whose values were populated by form-
     * data providers (DatabaseRowDefaultValues etc.) on the OPEN. We replicate
     * that by re-submitting all current DB values verbatim; DataHandler then
     * runs fillInFieldArray + TCA validation on each one and reflects any
     * PageTSconfig TCAdefaults or TCA.default normalizations that apply only
     * during that pass.
     */
    private function synthesiseBackendSave(string $table, int $uid): void
    {
        $current = $this->fetchRawRow($table, $uid);
        $payload = [$table => [$uid => []]];
        foreach ($current as $field => $value) {
            // Skip columns DataHandler manages itself — passing them in would
            // either throw ("uid cannot be edited") or be silently ignored.
            if (in_array($field, ['uid', 'pid', 'tstamp', 'crdate', 'cruser_id'], true)) {
                continue;
            }
            if (str_starts_with($field, 't3ver_')) {
                continue;
            }
            $payload[$table][$uid][$field] = $value;
        }

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->BE_USER = $GLOBALS['BE_USER'];
        $dh->start($payload, []);
        $dh->process_datamap();
    }

    private function fetchRawRow(string $table, int $uid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchFirstReference(string $parentTable, int $parentUid, string $fieldname): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter($parentTable)),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter($fieldname)),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($parentUid, ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Return the subset of keys whose values differ, with before/after pairs.
     * Skips tstamp (always different on save) and uid (always stable).
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function diffRows(array $before, array $after): array
    {
        $delta = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($keys as $key) {
            if (in_array($key, ['tstamp', 'uid'], true)) {
                continue;
            }
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            if ((string)$b !== (string)$a) {
                $delta[$key] = ['before' => $b, 'after' => $a];
            }
        }
        return $delta;
    }

    /**
     * @param array<string, array{before: mixed, after: mixed}> $delta
     */
    private function formatDelta(array $delta): string
    {
        $lines = [];
        foreach ($delta as $field => $pair) {
            $lines[] = sprintf(
                '  %-22s %s  →  %s',
                $field,
                var_export($pair['before'], true),
                var_export($pair['after'], true)
            );
        }
        return implode("\n", $lines);
    }

    private function createSiteConfiguration(): void
    {
        $config = [
            'rootPageId' => 1,
            'base' => 'http://localhost/',
            'websiteTitle' => 'PR7 Diag',
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
        $path = $this->instancePath . '/typo3conf/sites/pr7-diag';
        GeneralUtility::mkdir_deep($path);
        GeneralUtility::writeFile($path . '/config.yaml', Yaml::dump($config, 99, 2), true);
    }
}
