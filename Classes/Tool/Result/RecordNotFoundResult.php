<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

/**
 * A read that ran fine and matched nothing. This is data, not an error: the caller asked a
 * well-formed question and the answer is "no such record". A *write* whose target is missing
 * throws {@see \Mcp\Exception\ToolCallException} instead — there the call did not do what was asked.
 */
readonly class RecordNotFoundResult
{
    public bool $found;

    public function __construct(public string $table, public int $uid, public string $message,)
    {
        $this->found = false;
    }
}
