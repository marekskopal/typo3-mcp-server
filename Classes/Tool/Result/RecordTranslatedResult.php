<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class RecordTranslatedResult
{
    public function __construct(public int $uid, public string $table, public int $targetLanguageId,)
    {
    }
}
