<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * PR-6 Bug 4: extensions like b13/container and content_defender register
 * DataHandler hooks that require $GLOBALS['TYPO3_REQUEST'] to be set
 * (FormDataCompiler throws "The current ServerRequestInterface must be
 * provided in key 'request'"). Without an installed such extension we
 * simulate the same requirement via a stub hook that reads the request
 * from $GLOBALS during DataHandler processing.
 */
class WriteTableRequestAwareHookTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeTool = new WriteTableTool();
        $this->createAndSwitchToWorkspace('PR6 Hook Workspace');

        // Register a DataHandler hook that mirrors what a request-aware
        // extension hook would do: read $GLOBALS['TYPO3_REQUEST'] during the
        // datamap pass and throw if it's absent or lacks the expected attribute.
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['pr6-test-hook'] =
            RequestRequiringHookStub::class;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['pr6-test-hook']);
        parent::tearDown();
    }

    public function testCreateSucceedsWhenHookRequiresRequest(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Hook test', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($result);
        $this->assertGreaterThan(0, $this->extractJsonFromResult($result)['uid']);
    }

    public function testUpdateSucceedsWhenHookRequiresRequest(): void
    {
        $create = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'To update', 'bodytext' => '<p>x</p>'],
        ]);
        $this->assertSuccessfulToolResult($create);
        $uid = (int)$this->extractJsonFromResult($create)['uid'];

        $update = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['header' => 'Updated under hook'],
        ]);
        $this->assertSuccessfulToolResult($update);
    }
}

/**
 * Stub hook that emulates b13/container + content_defender's requirement:
 * a ServerRequest with applicationType=REQUESTTYPE_BE in $GLOBALS.
 *
 * If this hook throws, the DataHandler call fails and the WriteTableTool
 * response flips to isError — which is exactly what Bug 4 was producing.
 * When withSyntheticRequest is working, the hook finds what it needs and
 * stays quiet.
 */
class RequestRequiringHookStub
{
    public function processDatamap_preProcessFieldArray(
        array &$fieldArray,
        string $table,
        $id,
        \TYPO3\CMS\Core\DataHandling\DataHandler $pObj
    ): void {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof \Psr\Http\Message\ServerRequestInterface) {
            throw new \RuntimeException(
                'Stub hook emulating b13/container: request missing in $GLOBALS.',
                1735300000
            );
        }
        $appType = $request->getAttribute('applicationType');
        if ($appType !== \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_BE) {
            throw new \RuntimeException(
                'Stub hook: request present but applicationType is not BE.',
                1735300001
            );
        }
    }
}
