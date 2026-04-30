<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BackendUserSummaryResult
{
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
    ) {
    }
}
