<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test field access rules in TableAccessService
 *
 * FAL read support (PR 1) changed the policy for sys_file / sys_file_reference
 * and `type=file` fields: they are now accessible read-only. These tests lock
 * in the current intent so PR 2 / PR 3 follow-ups don't silently regress it.
 */
class TableAccessServiceFieldAccessTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected TableAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->service = new TableAccessService();
    }

    public function testFileFieldsAreAccessibleReadOnly(): void
    {
        $canAccess = $this->service->canAccessField('pages', 'media');
        $this->assertTrue($canAccess, 'File fields must be accessible for read/schema visibility');
    }

    public function testFileFieldsAppearInSchema(): void
    {
        $fields = $this->service->getAvailableFields('pages');
        $this->assertArrayHasKey('media', $fields, 'File field "media" must be discoverable in the schema');
    }

    public function testSysFileReferenceTableIsReadableAndWritable(): void
    {
        $this->assertTrue(
            $this->service->canReadTable('sys_file_reference'),
            'sys_file_reference must be readable (it is workspace-capable and exposes file links)'
        );

        // PR 2 unlocks the FAL linking shortcut: sys_file_reference is now writable
        // in the current workspace alongside the rest of the content graph.
        $accessInfo = $this->service->getTableAccessInfo('sys_file_reference', false);
        $this->assertFalse($accessInfo['read_only'], 'sys_file_reference must be writable with PR 2');
        $this->assertTrue($accessInfo['permissions']['write'] ?? false, 'Writes must be enabled via the linking shortcut');
    }

    public function testSysFileTableIsReadableButNotWorkspaceCapable(): void
    {
        $this->assertTrue(
            $this->service->canReadTable('sys_file'),
            'sys_file must be readable via ReadTable'
        );

        $accessInfo = $this->service->getTableAccessInfo('sys_file', false);
        $this->assertFalse($accessInfo['workspace_capable'], 'sys_file is not workspace-capable by design (FAL boundary)');
        $this->assertTrue($accessInfo['read_only'], 'sys_file writes must remain disabled');
    }

    public function testInlineRelationsToSysFileReferenceAreAccessible(): void
    {
        if (!isset($GLOBALS['TCA']['tt_content']['columns']['assets'])) {
            $this->markTestSkipped('tt_content.assets field not available in this TYPO3 version');
        }

        // TYPO3 13/14 moved this to `type=file` but both variants must be exposed.
        $this->assertTrue(
            $this->service->canAccessField('tt_content', 'assets'),
            'Inline/file relations to sys_file_reference must be accessible now'
        );
    }

    public function testRegularFieldsRemainAccessible(): void
    {
        $this->assertTrue($this->service->canAccessField('pages', 'title'));
        $this->assertTrue($this->service->canAccessField('pages', 'description'));
    }

    public function testAvailableFieldsIncludesFileFields(): void
    {
        $fields = $this->service->getAvailableFields('pages');

        $this->assertArrayHasKey('title', $fields);
        $this->assertArrayHasKey('description', $fields);
        $this->assertArrayHasKey('media', $fields, 'File fields must be in the available fields set');
    }

    public function testTtContentFieldsIncludeFileAndInlineRelations(): void
    {
        $fields = $this->service->getAvailableFields('tt_content', 'text');

        $this->assertArrayHasKey('header', $fields);
        $this->assertArrayHasKey('bodytext', $fields);

        if (isset($GLOBALS['TCA']['tt_content']['columns']['assets'])) {
            $fieldsForTextmedia = $this->service->getAvailableFields('tt_content', 'textmedia');
            $this->assertArrayHasKey('assets', $fieldsForTextmedia, 'assets must be discoverable for textmedia CType');
        }
    }
}
