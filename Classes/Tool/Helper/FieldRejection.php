<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Helper;

use Mcp\Exception\ToolCallException;

/**
 * The one message every writer uses when a `fields` object survives the writable-field filter with
 * nothing left. The filtering itself differs per tool (the create tools inject the language field),
 * so only the refusal is shared — but it is the part a client reads, and it used to drift.
 *
 * @internal
 */
class FieldRejection
{
    /** @param list<string> $ignoredFields */
    public static function noValidFields(string $tableName, array $ignoredFields): ToolCallException
    {
        return new ToolCallException(
            'No valid fields provided for table "' . $tableName . '".'
                . ($ignoredFields !== [] ? ' Ignored, not writable: ' . implode(', ', $ignoredFields) . '.' : '')
                . ' Call table_schema to see which fields can be written.',
        );
    }
}
