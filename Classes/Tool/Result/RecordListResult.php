<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

use JsonSerializable;

/**
 * A paginated list of records, as returned by {@see \MarekSkopal\MsMcpServer\Service\RecordService}
 * `findByPid()` / `search()`. Outside the live workspace those carry `hasMore` instead of `total`
 * (a SQL COUNT cannot be workspace-overlaid), so both are nullable and the null one is omitted.
 */
readonly class RecordListResult implements JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $records
     * @param list<string> $ignoredFields search keys that were dropped because the field is not readable
     */
    public function __construct(
        public array $records,
        public ?int $total = null,
        public ?bool $hasMore = null,
        public ?string $workspaceOverlay = null,
        public array $ignoredFields = [],
    ) {
    }

    /**
     * @param array{records: list<array<string, mixed>>, total?: int, hasMore?: bool, workspaceOverlay?: string} $result
     * @param list<string> $ignoredFields
     */
    public static function fromQuery(array $result, array $ignoredFields = []): self
    {
        return new self(
            $result['records'],
            $result['total'] ?? null,
            $result['hasMore'] ?? null,
            $result['workspaceOverlay'] ?? null,
            $ignoredFields,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = ['records' => $this->records];

        if ($this->total !== null) {
            $data['total'] = $this->total;
        }

        if ($this->hasMore !== null) {
            $data['hasMore'] = $this->hasMore;
        }

        if ($this->workspaceOverlay !== null) {
            $data['workspaceOverlay'] = $this->workspaceOverlay;
        }

        if ($this->ignoredFields !== []) {
            $data['ignoredFields'] = $this->ignoredFields;
        }

        return $data;
    }
}
