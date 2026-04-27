<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class PermissionSummaryResult
{
    /**
     * @param list<string> $tablesSelect
     * @param list<string> $tablesModify
     * @param list<int> $allowedLanguages
     * @param array<string, bool> $filePermissions
     * @param list<int> $webmounts
     * @param list<int> $filemounts
     */
    public function __construct(
        public bool $isAdmin,
        public array $tablesSelect,
        public array $tablesModify,
        public array $allowedLanguages,
        public array $filePermissions,
        public array $webmounts,
        public array $filemounts,
    ) {
    }
}
