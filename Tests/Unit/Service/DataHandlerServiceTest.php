<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\MmFieldNormalizer;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(DataHandlerService::class)]
final class DataHandlerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['TCA'], $GLOBALS['TYPO3_REQUEST']);
    }

    public function testUpdateRecordHandsTheDatamapToDataHandler(): void
    {
        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->expects(self::once())
            ->method('start')
            ->with(['pages' => [68 => ['title' => 'Výsledky 2025/2026', 'slug' => '/o-lize/vysledky-2025/2026', 'hidden' => 0]]], []);
        $dataHandler->expects(self::once())->method('process_datamap');
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $this->createService()->updateRecord(
            'pages',
            68,
            ['title' => 'Výsledky 2025/2026', 'slug' => '/o-lize/vysledky-2025/2026', 'hidden' => 0],
        );
    }

    public function testDataHandlerErrorLogIsRelayedToTheClient(): void
    {
        // DataHandler's errorLog holds messages TYPO3 itself shows to editors ("Attempt to modify
        // record … without permission"). They are safe to relay and are the only way a client
        // learns *why* a write was refused — an "internal error" leaves it guessing.
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->errorLog = ['Attempt to modify record "Home" (pages:68) without permission.'];
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        try {
            $this->createService()->updateRecord('pages', 68, ['title' => 'x']);
            self::fail('Expected a ToolCallException');
        } catch (ToolCallException $e) {
            self::assertSame(1712000021, $e->getCode());
            self::assertStringContainsString('Attempt to modify record "Home" (pages:68) without permission.', $e->getMessage());
        }
    }

    public function testHookFailureDuringDatamapIsReportedAsPossiblyAppliedWithoutRelayingTheRawMessage(): void
    {
        // asl-brno pages_update uid 68 (title + slug + hidden): DataHandler wrote the row, then
        // EXT:redirects' slug hook threw from processDatamap_afterDatabaseOperations. The client
        // saw only "An internal error occurred" and could not tell the write had happened.
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->method('process_datamap')
            ->willThrowException(new \Error('Call to a member function getIdentifier() on null'));
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('DataHandler'), self::callback(
                static fn (array $context): bool => ($context['exception'] ?? null) instanceof \Error
                    && ($context['table'] ?? null) === 'pages'
                    && ($context['uid'] ?? null) === 68,
            ));

        try {
            $this->createService($logger)->updateRecord('pages', 68, ['slug' => '/new']);
            self::fail('Expected a ToolCallException');
        } catch (ToolCallException $e) {
            self::assertSame(1725700010, $e->getCode());
            self::assertStringContainsString('pages:68', $e->getMessage());
            self::assertStringContainsString('may already have been applied', $e->getMessage());
            self::assertStringContainsString('Error', $e->getMessage());
            // The raw message may embed SQL or paths in general; it goes to the log, not the client.
            self::assertStringNotContainsString('getIdentifier', $e->getMessage());
            self::assertInstanceOf(\Error::class, $e->getPrevious());
        }
    }

    public function testCreateRecordReportsHookFailureWithTheTableAndPid(): void
    {
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->method('process_datamap')->willThrowException(new \RuntimeException('boom', 42));
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        try {
            $this->createService()->createRecord('pages', 5, ['title' => 'x']);
            self::fail('Expected a ToolCallException');
        } catch (ToolCallException $e) {
            self::assertSame(1725700010, $e->getCode());
            self::assertStringContainsString('creating a pages record on pid 5', $e->getMessage());
            self::assertStringContainsString('RuntimeException (code 42)', $e->getMessage());
            self::assertStringNotContainsString('boom', $e->getMessage());
        }
    }

    public function testToolCallExceptionFromDataHandlerPassesThroughUnchanged(): void
    {
        $original = new ToolCallException('already client-facing', 7);
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->method('process_cmdmap')->willThrowException($original);
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        try {
            $this->createService()->deleteRecord('pages', 68);
            self::fail('Expected a ToolCallException');
        } catch (ToolCallException $e) {
            self::assertSame($original, $e);
        }
    }

    private function createService(?LoggerInterface $logger = null): DataHandlerService
    {
        return new DataHandlerService(
            $this->createStub(SiteFinder::class),
            new MmFieldNormalizer(new TcaSchemaService()),
            $logger ?? new NullLogger(),
        );
    }
}
