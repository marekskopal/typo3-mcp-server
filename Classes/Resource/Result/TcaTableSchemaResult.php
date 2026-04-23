<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class TcaTableSchemaResult
{
    /** @param list<array<string, mixed>> $fields */
    public function __construct(public string $table, public array $fields)
    {
    }
}
