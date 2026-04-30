<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BackendUserDetailResult
{
    /** @param list<int> $usergroup */
    public function __construct(
        public int $uid,
        public string $username,
        public string $realName,
        public string $email,
        public bool $admin,
        public bool $disabled,
        public int $starttime,
        public int $endtime,
        public int $lastlogin,
        public array $usergroup,
        public string $lang,
        public string $description,
        public string $dbMountpoints,
        public string $fileMountpoints,
        public string $filePermissions,
        public int $workspacePerms,
        public int $options,
        public string $userMods,
        public string $allowedLanguages,
        public string $tsConfig,
        public string $categoryPerms,
    ) {
    }
}
