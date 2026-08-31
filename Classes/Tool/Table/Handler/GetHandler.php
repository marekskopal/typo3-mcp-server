<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

/**
 * `<prefix>_get`. One signature for every table: whether translations are attached is decided from
 * the config inside the body, not by the parameter list.
 *
 * @internal
 */
final readonly class GetHandler extends AbstractTableToolHandler
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
        return $this->config->toolName('get');
    }

    public function description(): string
    {
        return 'Get a single ' . $this->config->label . ' record by its uid.';
    }

    public function __invoke(int $uid): string
    {
        return $this->run(
            function () use ($uid): string {
                $record = $this->recordService->findByUid($this->config->tableName, $uid, $this->config->readFields);

                if ($record === null) {
                    return json_encode(['error' => $this->config->label . ' record not found'], JSON_THROW_ON_ERROR);
                }

                $translations = $this->findTranslations($uid, $record);
                if ($translations !== null) {
                    $record['translations'] = $translations;
                }

                return json_encode($record, JSON_THROW_ON_ERROR);
            },
            [$uid],
            $uid,
        );
    }

    /**
     * Translations hang off the default-language record only, so a record in another language
     * returns none rather than a confusing sibling list.
     *
     * @param array<string, mixed> $record
     * @return list<array<string, mixed>>|null
     */
    private function findTranslations(int $uid, array $record): ?array
    {
        $languageField = $this->config->languageField;
        $transOrigPointerField = $this->config->transOrigPointerField;

        if ($languageField === null || $transOrigPointerField === null) {
            return null;
        }

        $langValue = $record[$languageField] ?? -1;
        if ((!is_int($langValue) && !is_string($langValue)) || (int) $langValue !== 0) {
            return null;
        }

        return $this->recordService->findTranslations($this->config->tableName, $uid, $languageField, $transOrigPointerField);
    }
}
