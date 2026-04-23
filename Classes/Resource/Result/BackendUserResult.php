<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class BackendUserResult
{
    public function __construct(
        public int $uid,
        public string $username,
        public string $email,
        public bool $isAdmin,
        public string $lang,
        public string $usergroups,
    ) {
    }
}
