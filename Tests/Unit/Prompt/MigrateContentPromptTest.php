<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Prompt;

use MarekSkopal\MsMcpServer\Prompt\MigrateContentPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrateContentPrompt::class)]
final class MigrateContentPromptTest extends TestCase
{
    public function testExecuteReturnsUserMessageWithMigrationInstructions(): void
    {
        $prompt = new MigrateContentPrompt();
        $result = $prompt->execute(10, 20);

        self::assertArrayHasKey('user', $result);
        self::assertStringContainsString('10', $result['user']);
        self::assertStringContainsString('20', $result['user']);
        self::assertStringContainsString('pages_get', $result['user']);
        self::assertStringContainsString('content_list', $result['user']);
        self::assertStringContainsString('record_move_batch', $result['user']);
        self::assertStringContainsString('cache_clear', $result['user']);
        self::assertStringContainsString('backend_layout', $result['user']);
    }
}
