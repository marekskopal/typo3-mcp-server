<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class FileUploadedResult
{
    public function __construct(public int $uid, public string $name, public string $identifier, public int $size, public string $mimeType,)
    {
    }
}
