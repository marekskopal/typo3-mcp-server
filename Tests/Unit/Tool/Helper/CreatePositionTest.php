<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Helper;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\CreatePosition;
use Mcp\Server\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreatePosition::class)]
final class CreatePositionTest extends TestCase
{
    public function testBottomPlacesTheRecordAfterTheLastOneOnThePage(): void
    {
        // #38: a page built element by element must not come out in reverse.
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())->method('findLastSortedUid')->with('tt_content', 10)->willReturn(57);

        self::assertSame(-57, CreatePosition::resolve($recordService, 'tt_content', 10, 0, 'bottom'));
    }

    public function testBottomOnAnEmptyPageUsesThePid(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findLastSortedUid')->willReturn(null);

        self::assertSame(10, CreatePosition::resolve($recordService, 'tt_content', 10, 0, CreatePosition::BOTTOM));
    }

    public function testTopUsesThePidWithoutALookup(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('findLastSortedUid');

        self::assertSame(10, CreatePosition::resolve($recordService, 'tt_content', 10, 0, ' TOP '));
    }

    public function testAfterUidPlacesTheRecordAfterThatSibling(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 33, 'pid' => '10']);

        self::assertSame(-33, CreatePosition::resolve($recordService, 'tt_content', 10, 33, 'bottom'));
    }

    public function testAfterUidOnAnotherPageIsRejected(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 33, 'pid' => 4]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('afterUid 33 is on page 4, not on pid 10');

        CreatePosition::resolve($recordService, 'tt_content', 10, 33, 'bottom');
    }

    public function testUnknownAfterUidIsRejected(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('afterUid 33: no tt_content record');

        CreatePosition::resolve($recordService, 'tt_content', 10, 33, 'bottom');
    }

    public function testNegativePidKeepsItsMeaning(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('findLastSortedUid');

        self::assertSame(-33, CreatePosition::resolve($recordService, 'tt_content', -33, 0, 'bottom'));
    }

    public function testUnknownPositionIsRejected(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown position "middle"');

        CreatePosition::resolve($this->createStub(RecordService::class), 'tt_content', 10, 0, 'middle');
    }
}
