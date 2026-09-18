<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class SystemInfoResult
{
    /**
     * @param string|null $applicationContext null for a non-admin, who is not told which context
     *                                        this installation runs in
     * @param string|null $projectPath null for a non-admin, who never sees the absolute filesystem
     *                                 path of the installation in the backend either
     */
    public function __construct(
        public string $typo3Version,
        public string $phpVersion,
        public ?string $applicationContext,
        public string $os,
        public ?string $projectPath,
    ) {
    }
}
