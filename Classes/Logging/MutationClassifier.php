<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Logging;

/**
 * Decides whether an invocation changed anything, so {@see AuditLogLevel::Mutations} can drop the
 * successful reads without dropping the writes.
 *
 * **Fail-closed by design.** The check enumerates the *read* shapes and treats everything else as a
 * mutation. A tool added later under an unfamiliar name is therefore logged rather than silently
 * dropped from the audit trail — the opposite default would turn a naming slip into a hole in the
 * record, which is exactly what an audit trail must not have.
 *
 * Two naming conventions have to be recognised, because the two call sites report differently:
 * `ErrorHandlingProxy` reports the handler's class short name (`PagesListTool`), while
 * `RegistrarToolRunner` reports the MCP tool name (`item_list`).
 *
 * @internal
 */
final class MutationClassifier
{
    /** Class-name suffixes of read-only attribute-discovered tools. */
    private const array READ_CLASS_SUFFIXES = [
        'GetTool',
        'GetInfoTool',
        'ListTool',
        'SearchTool',
        'CountTool',
        'TreeTool',
        'SchemaTool',
    ];

    /** Read-only tools whose class name carries no verb to match on. */
    private const array READ_CLASSES = [
        'PermissionCheckPageTool',
        'PermissionCheckSummaryTool',
        'PermissionCheckTableTool',
        'SiteLanguagesTool',
    ];

    /** Tool-name suffixes of read-only registrar tools (`item_list`, `redirect_get`, …). */
    private const array READ_TOOL_SUFFIXES = ['_list', '_get', '_search', '_count'];

    public static function isMutation(string $handlerName, string $type): bool
    {
        // Resources and prompts read; only tools write.
        if ($type !== 'tool') {
            return false;
        }

        if (in_array($handlerName, self::READ_CLASSES, true)) {
            return false;
        }

        foreach ([...self::READ_CLASS_SUFFIXES, ...self::READ_TOOL_SUFFIXES] as $suffix) {
            if (str_ends_with($handlerName, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
