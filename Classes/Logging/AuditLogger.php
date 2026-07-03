<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Logging;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use const JSON_THROW_ON_ERROR;

readonly class AuditLogger
{
    private const string TABLE = 'sys_log';

    /** Cap on how many scalar arguments are recorded, and the max length of each string argument. */
    private const int MAX_LOGGED_ARGUMENTS = 20;

    private const int MAX_ARGUMENT_LENGTH = 100;

    public function __construct(private ConnectionPool $connectionPool, private LoggerInterface $logger)
    {
    }

    /** @param list<mixed> $arguments */
    public function logSuccess(
        string $handlerName,
        string $type,
        array $arguments,
        int $executionTimeMs,
        string $tableName = '',
        int $recordUid = 0,
    ): void {
        $this->writeLog(
            handlerName: $handlerName,
            type: $type,
            executionTimeMs: $executionTimeMs,
            error: 0,
            details: sprintf('MCP %s %s: OK (%dms)', $type, $handlerName, $executionTimeMs),
            arguments: $arguments,
            tableName: $tableName,
            recordUid: $recordUid,
        );
    }

    /** @param list<mixed> $arguments */
    public function logFailure(
        string $handlerName,
        string $type,
        array $arguments,
        int $executionTimeMs,
        string $errorMessage,
        string $tableName = '',
        int $recordUid = 0,
    ): void {
        $this->writeLog(
            handlerName: $handlerName,
            type: $type,
            executionTimeMs: $executionTimeMs,
            error: 2,
            // The error message is attacker-influenceable, so keep it out of `details` (which
            // the backend log module treats as an sprintf format string) — it lives in log_data.
            details: sprintf('MCP %s %s failed (%dms)', $type, $handlerName, $executionTimeMs),
            errorMessage: $errorMessage,
            arguments: $arguments,
            tableName: $tableName,
            recordUid: $recordUid,
        );
    }

    /** @param list<mixed> $arguments */
    private function writeLog(
        string $handlerName,
        string $type,
        int $executionTimeMs,
        int $error,
        string $details,
        string $errorMessage = '',
        array $arguments = [],
        string $tableName = '',
        int $recordUid = 0,
    ): void {
        try {
            $backendUser = $GLOBALS['BE_USER'] ?? null;
            if (!$backendUser instanceof BackendUserAuthentication) {
                return;
            }

            $data = [
                'tool' => $handlerName,
                'type' => $type,
                'executionTimeMs' => $executionTimeMs,
            ];

            $redactedArguments = $this->redactArguments($arguments);
            if ($redactedArguments !== []) {
                $data['args'] = $redactedArguments;
            }

            if ($errorMessage !== '') {
                // Strip control characters so a crafted error message can't inject newlines or
                // terminal escapes into the audit trail.
                $data['error'] = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $errorMessage);
            }

            $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
            $connection->insert(self::TABLE, [
                'userid' => $backendUser->getUserId() ?? 0,
                'type' => 4,
                'channel' => 'default',
                'level' => $error === 0 ? 'info' : 'error',
                'action' => 0,
                'error' => $error,
                'details' => $details,
                'log_data' => json_encode($data, JSON_THROW_ON_ERROR),
                // Target of the action, so the trail can answer "which record was touched" —
                // strip control chars like the error message, since table names for dynamic
                // tools originate from a DB row.
                'tablename' => mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', $tableName), 0, 255),
                'recuid' => max($recordUid, 0),
                'IP' => $this->resolveRemoteAddress(),
                'tstamp' => $GLOBALS['EXEC_TIME'] ?? time(),
                'event_pid' => -1,
                'workspace' => $backendUser->workspace,
            ]);
        } catch (\Throwable $e) {
            // Audit logging must never break tool execution, but a failed write should not vanish
            // silently either — surface it to the PSR logger so the gap in the trail is detectable.
            $this->logger->warning('MCP audit log write failed', ['exception' => $e]);
        }
    }

    /**
     * Reduce raw positional arguments to a size-capped, scalar-only list for the audit trail.
     * Scalars (uid, pid, table name, …) identify the affected target; arrays/objects — which carry
     * record field payloads — are deliberately omitted so free-text content never lands in the log.
     *
     * @param list<mixed> $arguments
     * @return list<string|int|float|bool>
     */
    private function redactArguments(array $arguments): array
    {
        $redacted = [];
        foreach ($arguments as $argument) {
            if (count($redacted) >= self::MAX_LOGGED_ARGUMENTS) {
                break;
            }

            if (is_int($argument) || is_float($argument) || is_bool($argument)) {
                $redacted[] = $argument;
            } elseif (is_string($argument)) {
                $redacted[] = mb_substr($argument, 0, self::MAX_ARGUMENT_LENGTH);
            }
        }

        return $redacted;
    }

    private function resolveRemoteAddress(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return '';
        }

        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$normalizedParams instanceof NormalizedParams) {
            return '';
        }

        return $normalizedParams->getRemoteAddress();
    }
}
