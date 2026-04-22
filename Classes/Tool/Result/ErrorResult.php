<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

use JsonSerializable;

readonly class ErrorResult implements JsonSerializable
{
    /** @param array<string, mixed> $context */
    public function __construct(public string $error, public array $context = [],)
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['error' => $this->error, ...$this->context];
    }
}
