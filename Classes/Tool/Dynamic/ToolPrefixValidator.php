<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Dynamic;

/**
 * Single source of truth for what a dynamic-tool prefix may look like. Used both at registration
 * time (DynamicToolRegistrar, rejecting tampered rows) and at save time in the backend module
 * (ExtensionTableController), so an admin cannot store a prefix that would later be silently
 * dropped when the tools are registered.
 *
 * @internal
 */
final class ToolPrefixValidator
{
    /** A tool prefix must be a lowercase identifier; it becomes part of generated tool names. */
    public const string PREFIX_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** Prefixes owned by the built-in static tools; a discovered table may not shadow them. */
    public const array RESERVED_PREFIXES = [
        'pages',
        'content',
        'record',
        'file',
        'table',
        'permission',
        'redirect',
        'scheduler',
        'workspace',
        'cache',
        'be_user',
        'be_group',
    ];

    public static function isValid(string $prefix): bool
    {
        return self::validate($prefix) === null;
    }

    /** Human-readable reason why the prefix is not usable, or null when it is valid. */
    public static function validate(string $prefix): ?string
    {
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            return sprintf(
                'Invalid prefix "%s": it must start with a lowercase letter and may only contain'
                . ' lowercase letters, digits and underscores (max 64 characters).',
                $prefix,
            );
        }

        if (in_array($prefix, self::RESERVED_PREFIXES, true)) {
            return sprintf('Prefix "%s" is reserved by a built-in tool group and cannot be used.', $prefix);
        }

        return null;
    }
}
