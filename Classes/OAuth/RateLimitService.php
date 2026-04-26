<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\OAuth;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class RateLimitService
{
    private const string TABLE = 'tx_msmcpserver_rate_limit';

    private bool $enabled;

    /** @var array<string, array{limit: int, window: int}> */
    private array $endpointLimits;

    public function __construct(private ConnectionPool $connectionPool, ExtensionConfiguration $extensionConfiguration)
    {
        $config = $extensionConfiguration->get('ms_mcp_server');
        $c = is_array($config) ? $config : [];

        $enabled = $c['rateLimitEnabled'] ?? '1';
        $this->enabled = (bool) $enabled;

        $this->endpointLimits = [
            'authorize_post' => [
                'limit' => $this->intVal($c, 'rateLimitAuthorize', 5),
                'window' => $this->intVal($c, 'rateLimitAuthorizeWindow', 300),
            ],
            'authorize_get' => [
                'limit' => $this->intVal($c, 'rateLimitAuthorizeGet', 20),
                'window' => $this->intVal($c, 'rateLimitAuthorizeGetWindow', 300),
            ],
            'token_post' => [
                'limit' => $this->intVal($c, 'rateLimitToken', 20),
                'window' => $this->intVal($c, 'rateLimitTokenWindow', 300),
            ],
            'register_post' => [
                'limit' => $this->intVal($c, 'rateLimitRegister', 10),
                'window' => $this->intVal($c, 'rateLimitRegisterWindow', 3600),
            ],
            'revoke_post' => [
                'limit' => $this->intVal($c, 'rateLimitRevoke', 20),
                'window' => $this->intVal($c, 'rateLimitRevokeWindow', 300),
            ],
        ];
    }

    /**
     * Check if the request is within the rate limit.
     * Returns null if allowed, or the number of seconds until the window resets if blocked.
     */
    public function check(string $ipAddress, string $endpoint): ?int
    {
        if (!$this->enabled || $ipAddress === '') {
            return null;
        }

        $config = $this->endpointLimits[$endpoint] ?? null;
        if ($config === null) {
            return null;
        }

        $limit = $config['limit'];
        $window = $config['window'];
        $windowStart = intdiv(time(), $window) * $window;

        $hitCount = $this->incrementAndGetCount($ipAddress, $endpoint, $windowStart);

        if ($hitCount > $limit) {
            return max(1, $windowStart + $window - time());
        }

        return null;
    }

    /** Deletes expired rate limit entries older than 2 hours. */
    public function deleteExpiredEntries(): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt(
                    'window_start',
                    $queryBuilder->createNamedParameter(time() - 7200, ParameterType::INTEGER),
                ),
            )
            ->executeStatement();
    }

    private function incrementAndGetCount(string $ipAddress, string $endpoint, int $windowStart): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        try {
            $connection->insert(self::TABLE, [
                'ip_address' => $ipAddress,
                'endpoint' => $endpoint,
                'hit_count' => 1,
                'window_start' => $windowStart,
            ]);

            return 1;
        } catch (UniqueConstraintViolationException) {
            // Row already exists — increment
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->update(self::TABLE)
            ->set('hit_count', $queryBuilder->expr()->literal('hit_count + 1'), false)
            ->where(
                $queryBuilder->expr()->eq('ip_address', $queryBuilder->createNamedParameter($ipAddress)),
                $queryBuilder->expr()->eq('endpoint', $queryBuilder->createNamedParameter($endpoint)),
                $queryBuilder->expr()->eq('window_start', $queryBuilder->createNamedParameter($windowStart, ParameterType::INTEGER)),
            )
            ->executeStatement();

        $selectBuilder = $connection->createQueryBuilder();
        $selectBuilder->getRestrictions()->removeAll();

        /** @var int|string|false $hitCount */
        $hitCount = $selectBuilder
            ->select('hit_count')
            ->from(self::TABLE)
            ->where(
                $selectBuilder->expr()->eq('ip_address', $selectBuilder->createNamedParameter($ipAddress)),
                $selectBuilder->expr()->eq('endpoint', $selectBuilder->createNamedParameter($endpoint)),
                $selectBuilder->expr()->eq('window_start', $selectBuilder->createNamedParameter($windowStart, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();

        return (int) $hitCount;
    }

    /** @param array<mixed> $config */
    private function intVal(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
