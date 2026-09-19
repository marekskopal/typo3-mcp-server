<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Helper;

use Mcp\Exception\ToolCallException;

/**
 * Translates the user-facing (targetPid, afterUid) pair into the integer
 * convention used by TYPO3 DataHandler's `move` / `copy` commands:
 *   - positive value => destination page id (place at top)
 *   - negative value => -(uid) of the record to place AFTER (same page/column as sibling).
 *
 * @internal
 */
class MoveTarget
{
    public static function resolve(int $targetPid, int $afterUid): int
    {
        $hasPid = $targetPid >= 0;
        $hasAfter = $afterUid > 0;

        if ($hasPid && $hasAfter) {
            throw new ToolCallException('Provide exactly one of targetPid or afterUid, not both.');
        }

        if (!$hasPid && !$hasAfter) {
            throw new ToolCallException('Provide either targetPid (>= 0) or afterUid (> 0).');
        }

        return $hasAfter ? -$afterUid : $targetPid;
    }
}
