<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class PagePermissionResult
{
    public function __construct(
        public int $pageId,
        public bool $canShow,
        public bool $canEdit,
        public bool $canDelete,
        public bool $canCreateSubpages,
        public bool $canEditContent,
        public int $permissionBitmask,
    ) {
    }
}
