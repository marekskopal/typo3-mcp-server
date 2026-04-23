<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Prompt;

use MarekSkopal\MsMcpServer\Prompt\AuditPageSeoPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditPageSeoPrompt::class)]
final class AuditPageSeoPromptTest extends TestCase
{
    public function testExecuteReturnsUserMessageWithSeoInstructions(): void
    {
        $prompt = new AuditPageSeoPrompt();
        $result = $prompt->execute(10);

        self::assertArrayHasKey('user', $result);
        self::assertStringContainsString('10', $result['user']);
        self::assertStringContainsString('table_schema', $result['user']);
        self::assertStringContainsString('pages_get', $result['user']);
        self::assertStringContainsString('content_list', $result['user']);
        self::assertStringContainsString('og_title', $result['user']);
        self::assertStringContainsString('description', $result['user']);
    }
}
