<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * PR 7.1B: when a client creates a tt_content record with
 * `tx_container_parent` set (b13/container child) but leaves `colPos` at its
 * default, MCP must auto-assign `colPos` to the container's first grid slot.
 * Without that, the child renders as a top-level block because colPos=0 is
 * not inside any container slot — tx_container_parent alone is not enough.
 *
 * b13/container isn't installed in this test env; we install a fake
 * container configuration directly into the TCA (same pattern used by
 * WriteTableRequestAwareHookTest for its hook stub).
 */
class WriteTableContainerChildTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;
    private const CONTAINER_CTYPE = 'text';
    private const FIRST_SLOT_COLPOS = 101;
    private const SECOND_SLOT_COLPOS = 102;

    /**
     * @var list<array{string,int}>
     */
    private array $originalColPosItems = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TCA']['tt_content']['containerConfiguration'][self::CONTAINER_CTYPE] = [
            'cType' => self::CONTAINER_CTYPE,
            'grid' => [
                [
                    ['colPos' => self::FIRST_SLOT_COLPOS, 'name' => 'Slot A'],
                    ['colPos' => self::SECOND_SLOT_COLPOS, 'name' => 'Slot B'],
                ],
            ],
        ];
        // b13/container's Registry::configureContainer also pushes the grid's
        // colPos values into tt_content.colPos.items so DataHandler accepts
        // them as valid. We mirror that here — without it, validateRecordData
        // rejects the slot values with "must be one of: 0, 1, 2, 3".
        $this->originalColPosItems = $GLOBALS['TCA']['tt_content']['columns']['colPos']['config']['items'] ?? [];
        $GLOBALS['TCA']['tt_content']['columns']['colPos']['config']['items'][] = [
            'label' => 'Slot A',
            'value' => self::FIRST_SLOT_COLPOS,
        ];
        $GLOBALS['TCA']['tt_content']['columns']['colPos']['config']['items'][] = [
            'label' => 'Slot B',
            'value' => self::SECOND_SLOT_COLPOS,
        ];

        $this->writeTool = new WriteTableTool();
        $this->createAndSwitchToWorkspace('PR 7.1B Container Workspace');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tt_content']['containerConfiguration'][self::CONTAINER_CTYPE]);
        $GLOBALS['TCA']['tt_content']['columns']['colPos']['config']['items'] = $this->originalColPosItems;
        parent::tearDown();
    }

    public function testContainerChildGetsFirstSlotColPosWhenNotProvided(): void
    {
        $parentUid = $this->createParent(self::CONTAINER_CTYPE);

        $childResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Container child without explicit colPos',
                'tx_container_parent' => $parentUid,
            ],
        ]);
        $this->assertSuccessfulToolResult($childResult);
        $childUid = (int)$this->extractJsonFromResult($childResult)['uid'];

        $this->assertSame(
            self::FIRST_SLOT_COLPOS,
            $this->readColPos($childUid),
            'Container child should have colPos auto-assigned to the first grid slot.'
        );
    }

    public function testExplicitColPosOverridesAutoAssignment(): void
    {
        $parentUid = $this->createParent(self::CONTAINER_CTYPE);

        $childResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Container child with explicit colPos',
                'tx_container_parent' => $parentUid,
                'colPos' => self::SECOND_SLOT_COLPOS,
            ],
        ]);
        $this->assertSuccessfulToolResult($childResult);
        $childUid = (int)$this->extractJsonFromResult($childResult)['uid'];

        $this->assertSame(
            self::SECOND_SLOT_COLPOS,
            $this->readColPos($childUid),
            'Explicit colPos from the caller must not be overwritten.'
        );
    }

    public function testNonContainerParentSkipsAutoAssignment(): void
    {
        // `textmedia` has no containerConfiguration entry, so the gate must
        // fall through and leave colPos at its default.
        $parentUid = $this->createParent('textmedia');

        $childResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Child of a non-container parent',
                'tx_container_parent' => $parentUid,
            ],
        ]);
        $this->assertSuccessfulToolResult($childResult);
        $childUid = (int)$this->extractJsonFromResult($childResult)['uid'];

        $this->assertSame(
            0,
            $this->readColPos($childUid),
            'Without a container grid config, colPos must stay at the TCA default.'
        );
    }

    public function testRecordWithoutTxContainerParentUnchanged(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Plain top-level CE',
            ],
        ]);
        $this->assertSuccessfulToolResult($result);
        $uid = (int)$this->extractJsonFromResult($result)['uid'];

        $this->assertSame(
            0,
            $this->readColPos($uid),
            'Without tx_container_parent the auto-assignment must not fire.'
        );
    }

    private function createParent(string $cType): int
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => $cType,
                'header' => 'Parent (' . $cType . ')',
            ],
        ]);
        $this->assertSuccessfulToolResult($result);
        return (int)$this->extractJsonFromResult($result)['uid'];
    }

    /**
     * Read colPos from the workspace version of a tt_content record whose
     * client-facing UID is $uid. For brand-new records created in a
     * workspace, the record itself is the workspace version (t3ver_state=1,
     * t3ver_oid=0) and the UID the client sees is the workspace UID.
     */
    private function readColPos(int $uid): int
    {
        $row = $this->getConnectionForTable('tt_content')
            ->select(['colPos'], 'tt_content', ['uid' => $uid])
            ->fetchAssociative();
        $this->assertIsArray($row, "tt_content row $uid must exist.");
        return (int)$row['colPos'];
    }
}
