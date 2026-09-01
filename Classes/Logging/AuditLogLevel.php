<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Logging;

/**
 * How much of the MCP tool traffic reaches `sys_log`.
 *
 * Every invocation used to be written, successful reads included. An agent session doing a few
 * thousand `pages_list` / `record_search` / `content_get` calls wrote a few thousand rows, each an
 * INSERT in the hot path of the tool call — into a table shared with core's own logging that
 * administrators already struggle to keep small, and that `mcp:cleanup` does not prune.
 */
enum AuditLogLevel: string
{
    /** Every invocation, successful reads included. The behaviour before this setting existed. */
    case All = 'all';

    /**
     * Writes, plus every failure. The default: reads are the unbounded part, and a failed read is
     * both rare and worth keeping — the volume problem is successful ones.
     */
    case Mutations = 'mutations';

    /** Failures only. */
    case Errors = 'errors';

    /** Nothing. The MCP endpoint keeps no audit trail of its own. */
    case Off = 'off';

    /** An unset or unrecognised setting falls back to the default rather than failing a tool call. */
    public static function fromConfig(mixed $value): self
    {
        return is_string($value) ? self::tryFrom(strtolower(trim($value))) ?? self::Mutations : self::Mutations;
    }

    public function shouldLog(bool $isMutation, bool $isFailure): bool
    {
        return match ($this) {
            self::All => true,
            self::Mutations => $isMutation || $isFailure,
            self::Errors => $isFailure,
            self::Off => false,
        };
    }
}
