<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Helper;

use Mcp\Exception\ToolCallException;

/**
 * Parses the comma-separated UID strings used by the batch tools into a list of positive
 * integers, with an upper bound so a single call can't enqueue an unbounded DataHandler pass.
 *
 * @internal
 */
final class UidListParser
{
    public const int MAX_UIDS = 500;

    /** @return list<int> */
    public static function parse(string $uids): array
    {
        $parsed = array_values(array_filter(
            array_map('intval', array_filter(explode(',', $uids), static fn(string $v): bool => $v !== '')),
            static fn(int $v): bool => $v > 0,
        ));

        if (count($parsed) > self::MAX_UIDS) {
            throw new ToolCallException(sprintf('Too many UIDs: %d provided, maximum is %d per batch.', count($parsed), self::MAX_UIDS));
        }

        return $parsed;
    }
}
