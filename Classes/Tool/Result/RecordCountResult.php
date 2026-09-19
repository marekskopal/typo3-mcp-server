<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

use JsonSerializable;

readonly class RecordCountResult implements JsonSerializable
{
    /** @param list<string> $ignoredFields */
    public function __construct(public string $table, public int $count, public bool $exact = true, public array $ignoredFields = [],)
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = ['table' => $this->table, 'count' => $this->count];

        // Only ever false in a non-live workspace on a result set too large to overlay in full,
        // where the count is a floor. Say so rather than let it read as exact.
        if (!$this->exact) {
            $data['exact'] = false;
        }

        if ($this->ignoredFields !== []) {
            $data['ignoredFields'] = $this->ignoredFields;
        }

        return $data;
    }
}
