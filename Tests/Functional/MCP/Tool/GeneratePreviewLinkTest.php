<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Workflow\GeneratePreviewLinkTool;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PR-5: GeneratePreviewLink creates a shareable workspace preview URL.
 *
 * Multi-language site is set up programmatically (analogous to GetPageLanguageTest)
 * so we can assert on language-specific URL prefixes.
 */
class GeneratePreviewLinkTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private GeneratePreviewLinkTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMultiLanguageSiteConfiguration();

        $this->tool = new GeneratePreviewLinkTool(
            GeneralUtility::makeInstance(LanguageService::class)
        );

        // Every test runs inside a workspace — preview links require one.
        $this->createAndSwitchToWorkspace('Preview Test Workspace');
    }

    public function testGeneratesPreviewLinkForPage(): void
    {
        $result = $this->tool->execute(['pageUid' => 1]);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertStringStartsWith('http', $data['url'], 'URL must be absolute');
        $this->assertStringContainsString('ADMCMD_prev=', $data['url']);
        $this->assertSame(1, $data['pageUid']);
        $this->assertGreaterThan(0, $data['workspaceUid']);
        $this->assertSame('en', $data['language'], 'default language is English per site config');
        $this->assertNotEmpty($data['expiresAt']);

        // expiresAt is ISO-8601 and sits in the future but within a generous window:
        // TYPO3 default is 48h, but sys_workspace.previewlink_lifetime or TSConfig can shift it.
        $expires = (new \DateTimeImmutable($data['expiresAt']))->getTimestamp();
        $now = time();
        $this->assertGreaterThan($now, $expires, 'expiry must be in the future');
        $this->assertLessThanOrEqual($now + 72 * 3600, $expires, 'expiry must not be absurdly far out');
        $this->assertGreaterThanOrEqual($now + 3600, $expires, 'expiry must be at least an hour out');
    }

    public function testLanguageEnglishProducesEnglishPath(): void
    {
        $result = $this->tool->execute(['pageUid' => 1, 'language' => 'en']);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertSame('en', $data['language']);
        // English base is '/', the home page slug is '/'.
        $this->assertStringNotContainsString('/de/', $data['url']);
        $this->assertStringNotContainsString('/fr/', $data['url']);
    }

    public function testLanguageGermanProducesGermanUrlPrefix(): void
    {
        $result = $this->tool->execute(['pageUid' => 1, 'language' => 'de']);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertSame('de', $data['language']);
        $this->assertStringContainsString('/de/', $data['url'], 'German site base is /de/');
    }

    public function testLanguageDefaultMatchesExplicitDefault(): void
    {
        $withDefault = $this->tool->execute(['pageUid' => 1, 'language' => 'default']);
        $withoutParam = $this->tool->execute(['pageUid' => 1]);

        $this->assertSuccessfulToolResult($withDefault);
        $this->assertSuccessfulToolResult($withoutParam);

        // Language field identical; URLs differ only in keyword.
        $this->assertSame(
            $this->extractJsonFromResult($withDefault)['language'],
            $this->extractJsonFromResult($withoutParam)['language']
        );
    }

    /**
     * TYPO3 14 caches the preview keyword per (workspace, user) in the runtime
     * cache, so consecutive calls from the same session reuse the same token.
     * That's intentional — one session, one stable preview keyword. Our tool
     * inherits this behaviour. What matters is that the keyword always resolves
     * to an actual sys_preview row.
     */
    public function testConsecutiveCallsReuseSessionKeyword(): void
    {
        $first = $this->tool->execute(['pageUid' => 1]);
        $second = $this->tool->execute(['pageUid' => 1]);
        $this->assertSuccessfulToolResult($first);
        $this->assertSuccessfulToolResult($second);

        $firstKeyword = $this->keywordOf($this->extractJsonFromResult($first)['url']);
        $secondKeyword = $this->keywordOf($this->extractJsonFromResult($second)['url']);
        $this->assertNotEmpty($firstKeyword);
        $this->assertSame($firstKeyword, $secondKeyword, 'same session must get a stable keyword');
        $this->assertNotEmpty($this->fetchSysPreview($firstKeyword));
    }

    private function countSysPreview(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_preview');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('keyword')->from('sys_preview')->executeQuery()->fetchOne();
    }

    public function testPersistsRowInSysPreviewWithWorkspaceConfig(): void
    {
        $result = $this->tool->execute(['pageUid' => 1]);
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $keyword = $this->keywordOf($data['url']);
        $this->assertNotEmpty($keyword);

        $row = $this->fetchSysPreview($keyword);
        $this->assertNotEmpty($row, 'sys_preview row must exist for the emitted keyword');

        $config = json_decode((string)$row['config'], true);
        $this->assertIsArray($config);
        $this->assertArrayHasKey('fullWorkspace', $config);
        $this->assertSame($data['workspaceUid'], (int)$config['fullWorkspace']);
    }

    public function testRejectsNonExistentPage(): void
    {
        $result = $this->tool->execute(['pageUid' => 999999]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist', $result->content[0]->text);
    }

    public function testRejectsDeletedPage(): void
    {
        // Flip page 2 (About) to deleted on the fly — no extra fixture needed.
        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            ['deleted' => 1],
            ['uid' => 2]
        );

        $result = $this->tool->execute(['pageUid' => 2]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('is deleted', $result->content[0]->text);
    }

    public function testRejectsUnknownLanguage(): void
    {
        $result = $this->tool->execute(['pageUid' => 1, 'language' => 'es']);
        $this->assertTrue($result->isError);
        // 'es' is not in LanguageService's known ISO codes (from site config),
        // so we fail at ISO-to-UID resolution with a clear message.
        $this->assertStringContainsString('es', $result->content[0]->text);
    }

    public function testRejectsMissingPageUid(): void
    {
        $result = $this->tool->execute([]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('pageUid', $result->content[0]->text);
    }

    public function testRejectsZeroPageUid(): void
    {
        $result = $this->tool->execute(['pageUid' => 0]);
        $this->assertTrue($result->isError);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function keywordOf(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY) ?: '';
        parse_str($query, $parsed);
        return (string)($parsed['ADMCMD_prev'] ?? '');
    }

    private function fetchSysPreview(string $keyword): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_preview');
        $qb->getRestrictions()->removeAll();
        $row = $qb
            ->select('*')
            ->from('sys_preview')
            ->where($qb->expr()->eq('keyword', $qb->createNamedParameter($keyword)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: [];
    }

    /**
     * Matches GetPageLanguageTest's pattern but minus French (so "fr"
     * remains available as "is known ISO, but not site-configured" would
     * be harder to reach — "es" covers "unknown ISO altogether", which is
     * the cheap and useful error case).
     */
    protected function createMultiLanguageSiteConfiguration(): void
    {
        $siteConfiguration = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'Test Site',
            'languages' => [
                0 => [
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
                ],
                1 => [
                    'title' => 'German',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'hreflang' => 'de-de',
                    'direction' => 'ltr',
                    'flag' => 'de',
                    'navigationTitle' => 'Deutsch',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ];

        $configPath = $this->instancePath . '/typo3conf/sites/preview-test-site';
        GeneralUtility::mkdir_deep($configPath);
        GeneralUtility::writeFile($configPath . '/config.yaml', Yaml::dump($siteConfiguration, 99, 2), true);
    }
}
