<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Workflow;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\LanguageService as McpLanguageService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;

/**
 * Generate a shareable frontend preview URL for a page in the current
 * workspace. The URL carries an ADMCMD_prev token backed by a sys_preview
 * row, so the recipient can view the draft without a backend login.
 *
 * TYPO3 core provides the full mechanism via PreviewUriBuilder — we only
 * validate the inputs, call it, and read back the expiry from sys_preview
 * so the reported expiresAt matches the database truth (the TTL follows
 * sys_workspace.previewlink_lifetime / TSConfig / 48h default, so hard-coding
 * it would lie to callers on installations that override it).
 */
class GeneratePreviewLinkTool extends AbstractRecordTool
{
    private McpLanguageService $languageService;

    public function __construct(McpLanguageService $languageService)
    {
        parent::__construct();
        $this->languageService = $languageService;
    }

    public function getSchema(): array
    {
        $properties = [
            'pageUid' => [
                'type' => 'integer',
                'description' => 'UID of the page the preview link should point to.',
            ],
        ];

        // Match GetPageTool: only expose the language parameter when the site
        // actually has multiple languages — otherwise the enum would be confusing.
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (count($availableLanguages) > 1) {
            $properties['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code (e.g. "de", "en"), or "default" for the site default language.',
                'enum' => array_values(array_unique(array_merge(['default'], $availableLanguages))),
            ];
        }

        return [
            'description' => 'Create a shareable frontend preview URL for the current workspace\'s draft of a page. '
                . 'The URL carries an ADMCMD_prev token backed by a short-lived sys_preview row, so the recipient '
                . 'can open the draft without a backend login. Use this after editing to hand a link to a reviewer '
                . 'for feedback before publishing. TTL follows the TYPO3 workspace configuration (48 hours by default).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => ['pageUid'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        if (!isset($params['pageUid']) || (int)$params['pageUid'] <= 0) {
            throw new \InvalidArgumentException("Parameter 'pageUid' is required and must be a positive integer.");
        }
        $pageUid = (int)$params['pageUid'];
        $languageInput = isset($params['language']) ? (string)$params['language'] : 'default';

        $pageRow = $this->loadPage($pageUid);
        $this->assertPageAccess($pageRow);

        $languageId = $this->resolveLanguageId($languageInput);
        $this->assertLanguageAvailableForPage($pageUid, $languageId, $languageInput);

        $workspaceUid = (int)($GLOBALS['BE_USER']->workspace ?? 0);
        if ($workspaceUid === 0) {
            throw new \InvalidArgumentException('No active workspace. Preview links require a workspace context.');
        }

        try {
            $url = GeneralUtility::makeInstance(PreviewUriBuilder::class)
                ->buildUriForPage($pageUid, $languageId);
        } catch (\InvalidArgumentException $e) {
            // PreviewUriBuilder throws this when the page UID has no matching row —
            // translate to a clearer MCP-side wording rather than leaking the raw message.
            throw new \InvalidArgumentException(
                sprintf('Could not build preview URL for page uid=%d: %s', $pageUid, $e->getMessage()),
                1735200000,
                $e
            );
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                sprintf('Could not build preview URL for page uid=%d: %s', $pageUid, $e->getMessage()),
                1735200001,
                $e
            );
        }

        $keyword = $this->extractKeywordFromUrl($url);
        $endtime = $keyword !== null ? $this->fetchEndtime($keyword) : null;

        $response = [
            'url' => $url,
            'pageUid' => $pageUid,
            'pagePath' => (string)(parse_url($url, PHP_URL_PATH) ?? ''),
            'language' => $this->describeLanguage($languageId),
            'workspaceUid' => $workspaceUid,
            'expiresAt' => $endtime !== null ? $this->formatIso8601Utc($endtime) : null,
        ];

        return new CallToolResult([
            new TextContent(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);
    }

    /**
     * Load a page row, distinguishing "does not exist" from "is deleted".
     *
     * @return array<string, mixed>
     */
    private function loadPage(int $pageUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            throw new \InvalidArgumentException(sprintf('Page with uid=%d does not exist.', $pageUid));
        }
        if (!empty($row['deleted'])) {
            throw new \InvalidArgumentException(sprintf('Page with uid=%d is deleted.', $pageUid));
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $pageRow
     */
    private function assertPageAccess(array $pageRow): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
            throw new \InvalidArgumentException('No backend user is authenticated.');
        }
        if (!$backendUser->doesUserHaveAccess($pageRow, Permission::PAGE_SHOW)) {
            throw new \InvalidArgumentException(sprintf(
                'Backend user has no read access to page uid=%d.',
                (int)$pageRow['uid']
            ));
        }
    }

    private function resolveLanguageId(string $languageInput): int
    {
        $trimmed = trim($languageInput);
        if ($trimmed === '' || strtolower($trimmed) === 'default') {
            return 0;
        }
        $id = $this->languageService->getUidFromIsoCode($trimmed);
        if ($id === null) {
            throw new \InvalidArgumentException(sprintf('Unknown language code: %s', $trimmed));
        }
        return $id;
    }

    private function assertLanguageAvailableForPage(int $pageUid, int $languageId, string $languageInput): void
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        try {
            $site = $siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException $e) {
            throw new \InvalidArgumentException(sprintf(
                'No site configuration covers page uid=%d. A site must be configured for preview links.',
                $pageUid
            ), 1735200010, $e);
        }

        try {
            $site->getLanguageById($languageId);
        } catch (\InvalidArgumentException $e) {
            $available = [];
            foreach ($site->getLanguages() as $language) {
                $iso = $language->getLocale()->getLanguageCode();
                if ($iso !== '') {
                    $available[] = $iso;
                }
            }
            $availableList = $available === [] ? '<none>' : implode(', ', $available);
            throw new \InvalidArgumentException(sprintf(
                "Language '%s' is not configured for the site containing page uid=%d. Available: %s.",
                $languageInput,
                $pageUid,
                $availableList
            ), 1735200011, $e);
        }
    }

    private function extractKeywordFromUrl(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
        $parsed = [];
        parse_str($query, $parsed);
        $keyword = $parsed['ADMCMD_prev'] ?? null;
        return is_string($keyword) && $keyword !== '' ? $keyword : null;
    }

    private function fetchEndtime(string $keyword): ?int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_preview');
        $queryBuilder->getRestrictions()->removeAll();

        $endtime = $queryBuilder
            ->select('endtime')
            ->from('sys_preview')
            ->where($queryBuilder->expr()->eq('keyword', $queryBuilder->createNamedParameter($keyword)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $endtime === false ? null : (int)$endtime;
    }

    private function describeLanguage(int $languageId): string
    {
        if ($languageId === 0) {
            return $this->languageService->getDefaultIsoCode() ?? 'default';
        }
        return $this->languageService->getIsoCodeFromUid($languageId) ?? (string)$languageId;
    }

    private function formatIso8601Utc(int $epoch): string
    {
        return (new \DateTimeImmutable('@' . $epoch))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(\DATE_ATOM);
    }
}
