<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class TableSchemaResult
{
    /** @param list<array<string, mixed>> $fields */
    public function __construct(public string $table, public array $fields,)
    {
    }
}
