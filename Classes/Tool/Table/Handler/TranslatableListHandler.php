<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

/**
 * `<prefix>_list` for a translatable table: adds the sysLanguageUid filter and always returns the
 * language field, so a caller can tell the returned rows apart by language.
 *
 * @internal
 */
final readonly class TranslatableListHandler extends AbstractTableToolHandler
{
    public function __construct(
        TableToolConfig $config,
        AuditLogger $auditLogger,
        LoggerInterface $logger,
        private RecordService $recordService,
    ) {
        parent::__construct($config, $auditLogger, $logger);
    }

    public function toolName(): string
    {
        return $this->config->toolName('list');
    }

    public function description(): string
    {
        return 'List ' . $this->config->label . ' records by parent page ID with pagination.'
            . ' Use sysLanguageUid to filter by language (0 = default, -1 = all).'
            . ' Use selectFields (comma-separated) to choose which fields to return.';
    }

    public function __invoke(int $pid = 0, int $limit = 20, int $offset = 0, int $sysLanguageUid = -1, string $selectFields = '',): string
    {
        return $this->run(
            function () use ($pid, $limit, $offset, $sysLanguageUid, $selectFields): string {
                $languageField = $this->config->languageField;
                $fields = SelectedFields::resolve($selectFields, $this->config);

                if ($languageField !== null && !in_array($languageField, $fields, true)) {
                    $fields[] = $languageField;
                }

                $result = $this->recordService->findByPid(
                    $this->config->tableName,
                    $pid,
                    $limit,
                    $offset,
                    $fields,
                    $sysLanguageUid >= 0 ? $sysLanguageUid : null,
                    $sysLanguageUid >= 0 ? $languageField : null,
                );

                return json_encode($result, JSON_THROW_ON_ERROR);
            },
            [$pid, $limit, $offset, $sysLanguageUid, $selectFields],
        );
    }
}
