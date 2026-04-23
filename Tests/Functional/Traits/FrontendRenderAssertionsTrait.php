<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Traits;

use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

/**
 * Helpers for tests that need to render a page in the frontend application
 * and assert on the resulting HTML. Wraps testing-framework's
 * `executeFrontendSubRequest` with workspace-preview semantics so callers can
 * focus on the content assertions.
 *
 * Typical use: after creating a workspace-new page + content via WriteTable,
 * call `renderPagePreview($pageUid, $workspaceUid)` and assert on the output.
 * The fundamental regression guard for "MCP-created records must render in
 * the preview without needing a BE save round-trip".
 */
trait FrontendRenderAssertionsTrait
{
    /**
     * Render a page in the frontend application with the given workspace
     * context active, mirroring what the ADMCMD_prev preview middleware does
     * in production — without needing to build and parse a real preview URL.
     * Backend user 1 (admin fixture) is attached so enable-fields behave as
     * they would for an editor opening the preview.
     *
     * @return string Response body (HTML).
     */
    protected function renderPagePreview(int $pageUid, int $workspaceUid, int $languageId = 0): string
    {
        $request = (new InternalRequest('http://localhost/'))
            ->withPageId($pageUid)
            ->withLanguageId($languageId);

        $context = (new InternalRequestContext())
            ->withWorkspaceId($workspaceUid)
            ->withBackendUserId(1);

        $response = $this->executeFrontendSubRequest($request, $context);
        return (string)$response->getBody();
    }

    /**
     * Assert the rendered HTML contains the given needle, with a message that
     * includes a bounded snippet of the response so a failure is debuggable
     * without re-running the test with var_dumps.
     */
    protected function assertFrontendContains(string $html, string $needle, string $message = ''): void
    {
        if (str_contains($html, $needle)) {
            $this->addToAssertionCount(1);
            return;
        }
        $snippet = substr($html, 0, 2000);
        $suffix = strlen($html) > 2000 ? "\n... (truncated, full length " . strlen($html) . ')' : '';
        self::fail(
            ($message === '' ? "Frontend HTML does not contain expected fragment." : $message)
            . "\n  Needle: {$needle}"
            . "\n  Response head:\n----\n{$snippet}{$suffix}\n----"
        );
    }

    /**
     * Inverse: assert the rendered HTML DOES NOT contain the given needle.
     * Used in diagnostic tests that pin the status-quo rendering gap (needle
     * absent before the fix, present after).
     */
    protected function assertFrontendNotContains(string $html, string $needle, string $message = ''): void
    {
        if (!str_contains($html, $needle)) {
            $this->addToAssertionCount(1);
            return;
        }
        $snippet = substr($html, 0, 2000);
        self::fail(
            ($message === '' ? "Frontend HTML unexpectedly contains the fragment." : $message)
            . "\n  Needle: {$needle}"
            . "\n  Response head:\n----\n{$snippet}\n----"
        );
    }
}
