<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Prompt;

use MarekSkopal\MsMcpServer\Prompt\SummarizePagePrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SummarizePagePrompt::class)]
final class SummarizePagePromptTest extends TestCase
{
    public function testExecuteReturnsUserMessageWithSummaryInstructions(): void
    {
        $prompt = new SummarizePagePrompt();
        $result = $prompt->execute(5);

        self::assertArrayHasKey('user', $result);
        self::assertStringContainsString('5', $result['user']);
        self::assertStringContainsString('pages_get', $result['user']);
        self::assertStringContainsString('content_list', $result['user']);
        self::assertStringContainsString('site_languages', $result['user']);
        self::assertStringContainsString('Translation status', $result['user']);
    }
}
