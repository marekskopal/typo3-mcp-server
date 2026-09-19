<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Helper;

/**
 * The field-merging step shared by the create tools that take their required values as explicit
 * parameters and everything else as a `fields` JSON blob (`redirect_create`, `typoscript_create`).
 *
 * Explicit parameters win over the same key in `fields`, the result is filtered to the writable
 * columns, and whatever was dropped is named in `ignoredFields`. This used to be pasted into each
 * registrar — the same drift that produced TMS-31 elsewhere — so it lives here once.
 *
 * Call it from inside the audit wrapper: the JSON decode is part of the tool call.
 *
 * @internal
 */
class ExplicitFields
{
    /**
     * @param array<string, mixed> $explicit the values the tool took as parameters
     * @param string $fields the optional `fields` JSON object, or ''
     * @param list<string> $writableFields
     * @return array{array<string, mixed>, list<string>} the data to write, and the dropped field names
     */
    public static function merge(array $explicit, string $fields, array $writableFields): array
    {
        $merged = $fields !== '' ? array_merge(JsonObjectParser::parse($fields, 'fields'), $explicit) : $explicit;

        $data = array_intersect_key($merged, array_flip($writableFields));
        $ignoredFields = array_map('strval', array_values(array_diff(array_keys($merged), array_keys($data))));

        return [$data, $ignoredFields];
    }
}
