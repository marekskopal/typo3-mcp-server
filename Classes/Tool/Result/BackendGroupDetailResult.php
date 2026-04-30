<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BackendGroupDetailResult
{
    /** @param list<int> $subgroup */
    public function __construct(
        public int $uid,
        public string $title,
        public string $description,
        public bool $hidden,
        public array $subgroup,
        public string $dbMountpoints,
        public string $fileMountpoints,
        public string $filePermissions,
        public int $workspacePerms,
        public string $pagetypesSelect,
        public string $tablesModify,
        public string $tablesSelect,
        public string $nonExcludeFields,
        public string $explicitAllowdeny,
        public string $allowedLanguages,
        public string $customOptions,
        public string $groupMods,
        public string $mfaProviders,
        public string $tsConfig,
        public string $tsconfigIncludes,
    ) {
    }
}
