<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;

/**
 * Resolves the list tools' `selectFields` parameter against the table's readable fields.
 *
 * An empty or wholly unrecognised selection falls back to the configured list fields rather than
 * erroring: the caller gets a useful default instead of an empty projection. `uid` and `pid` are
 * always included, since every consumer needs them to address the record afterwards.
 *
 * @internal
 */
final class SelectedFields
{
    /** @return list<string> */
    public static function resolve(string $selectFields, TableToolConfig $config): array
    {
        if ($selectFields === '') {
            return $config->listFields;
        }

        $requested = array_map('trim', explode(',', $selectFields));
        $allowed = array_merge(['uid', 'pid'], $config->readFields);
        $valid = array_values(array_intersect($requested, $allowed));

        if ($valid === []) {
            return $config->listFields;
        }

        return array_values(array_unique(array_merge(['uid', 'pid'], $valid)));
    }
}
