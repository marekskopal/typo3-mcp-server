<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Logging;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use const JSON_THROW_ON_ERROR;

readonly class AuditLogger
{
    private const string TABLE = 'sys_log';

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /** @param list<mixed> $arguments */
    public function logSuccess(string $handlerName, string $type, array $arguments, int $executionTimeMs): void
    {
        $this->writeLog(
            handlerName: $handlerName,
            type: $type,
            executionTimeMs: $executionTimeMs,
            error: 0,
            details: sprintf('MCP %s %s: OK (%dms)', $type, $handlerName, $executionTimeMs),
        );
    }

    /** @param list<mixed> $arguments */
    public function logFailure(string $handlerName, string $type, array $arguments, int $executionTimeMs, string $errorMessage,): void
    {
        $this->writeLog(
            handlerName: $handlerName,
            type: $type,
            executionTimeMs: $executionTimeMs,
            error: 2,
            // The error message is attacker-influenceable, so keep it out of `details` (which
            // the backend log module treats as an sprintf format string) — it lives in log_data.
            details: sprintf('MCP %s %s failed (%dms)', $type, $handlerName, $executionTimeMs),
            errorMessage: $errorMessage,
        );
    }

    private function writeLog(
        string $handlerName,
        string $type,
        int $executionTimeMs,
        int $error,
        string $details,
        string $errorMessage = '',
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
                'tablename' => '',
                'recuid' => 0,
                'IP' => $this->resolveRemoteAddress(),
                'tstamp' => $GLOBALS['EXEC_TIME'] ?? time(),
                'event_pid' => -1,
                'workspace' => $backendUser->workspace,
            ]);
        } catch (\Throwable) {
            // Audit logging must never break tool execution
        }
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
