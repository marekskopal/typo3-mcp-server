<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class SystemInfoResult
{
    public function __construct(
        public string $typo3Version,
        public string $phpVersion,
        public string $applicationContext,
        public string $os,
        public string $projectPath,
    ) {
    }
}
