<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Helper;

use MarekSkopal\MsMcpServer\Service\RecordService;
use Mcp\Exception\ToolCallException;

/**
 * Where a create tool puts the new record, as the pid DataHandler expects: a positive pid puts it first
 * on that page, a negative one after the record with that uid.
 *
 * DataHandler's own default is "first on the page", so a page built element by element came out in
 * reverse (#38). The create tools therefore default to `bottom`, which is resolved to "after the record
 * that is currently last". `afterUid` places it after a given sibling, like the move tools do; a negative
 * `pid` still means the same as it always did.
 *
 * @internal
 */
class CreatePosition
{
    public const string TOP = 'top';

    public const string BOTTOM = 'bottom';

    public const string DESCRIPTION = ' The new record goes to the end of the page by default (position "bottom");'
        . ' pass position "top" to put it first, or afterUid to place it after that record on the same page.';

    public static function resolve(RecordService $recordService, string $table, int $pid, int $afterUid, string $position): int
    {
        if ($afterUid > 0) {
            return self::afterSibling($recordService, $table, $pid, $afterUid);
        }

        if ($pid < 0) {
            return $pid;
        }

        return match (strtolower(trim($position))) {
            self::BOTTOM => ($last = $recordService->findLastSortedUid($table, $pid)) !== null ? -$last : $pid,
            self::TOP, '' => $pid,
            default => throw new ToolCallException(
                'Unknown position "' . $position . '". Use "' . self::BOTTOM . '" (default) or "' . self::TOP . '",'
                    . ' or afterUid to place the record after a given one.',
            ),
        };
    }

    private static function afterSibling(RecordService $recordService, string $table, int $pid, int $afterUid): int
    {
        $sibling = $recordService->findByUid($table, $afterUid, ['uid', 'pid']);
        if ($sibling === null) {
            throw new ToolCallException('afterUid ' . $afterUid . ': no ' . $table . ' record with that uid.');
        }

        $siblingPid = $sibling['pid'] ?? null;
        $siblingPid = is_int($siblingPid) || is_string($siblingPid) ? (int) $siblingPid : null;
        if ($pid >= 0 && $siblingPid !== null && $siblingPid !== $pid) {
            throw new ToolCallException(
                sprintf(
                    'afterUid %d is on page %d, not on pid %d. The new record is placed on the page of the record it'
                        . ' follows; pass pid %d with it.',
                    $afterUid,
                    $siblingPid,
                    $pid,
                    $siblingPid,
                ),
            );
        }

        return -$afterUid;
    }
}
