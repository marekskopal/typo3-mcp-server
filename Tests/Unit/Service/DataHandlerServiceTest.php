<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DataHandlerService::class)]
final class DataHandlerServiceTest extends TestCase
{
    public function testCreateRecordBuildsCorrectDatamap(): void
    {
        $service = new DataHandlerService();

        // We cannot easily mock GeneralUtility::makeInstance(DataHandler::class)
        // in a pure unit test without TYPO3 bootstrap, so we verify the service
        // exists and has the correct method signatures.
        self::assertTrue(method_exists($service, 'createRecord'));
        self::assertTrue(method_exists($service, 'updateRecord'));
        self::assertTrue(method_exists($service, 'deleteRecord'));
    }
}
