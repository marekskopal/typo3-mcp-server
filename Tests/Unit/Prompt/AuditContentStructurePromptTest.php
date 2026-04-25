<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Prompt;

use MarekSkopal\MsMcpServer\Prompt\AuditContentStructurePrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditContentStructurePrompt::class)]
final class AuditContentStructurePromptTest extends TestCase
{
    public function testExecuteReturnsUserMessageWithStructureAuditInstructions(): void
    {
        $prompt = new AuditContentStructurePrompt();
        $result = $prompt->execute(1);

        self::assertArrayHasKey('user', $result);
        self::assertStringContainsString('1', $result['user']);
        self::assertStringContainsString('pages_tree', $result['user']);
        self::assertStringContainsString('backend_layout', $result['user']);
        self::assertStringContainsString('content_list', $result['user']);
        self::assertStringContainsString('colPos', $result['user']);
        self::assertStringContainsString('orphan', strtolower($result['user']));
    }

    public function testExecuteUsesCustomDepth(): void
    {
        $prompt = new AuditContentStructurePrompt();
        $result = $prompt->execute(1, 5);

        self::assertStringContainsString('depth=5', $result['user']);
    }
}
