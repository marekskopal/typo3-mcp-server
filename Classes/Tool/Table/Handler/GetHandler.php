<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordNotFoundResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Psr\Log\LoggerInterface;

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
        return 'Get a single ' . $this->config->subject() . ' by its uid.'
            . ' A uid that does not exist is reported as {"found": false}, not an error.' . $this->config->mmReadHint();
    }

    public function __invoke(int $uid): RecordResult|RecordNotFoundResult
    {
        return $this->run(
            function () use ($uid): RecordResult|RecordNotFoundResult {
                $record = $this->recordService->findByUid($this->config->tableName, $uid, $this->config->readFields);

                if ($record === null) {
                    return new RecordNotFoundResult(
                        $this->config->tableName,
                        $uid,
                        $this->config->subjectSentenceStart() . ' not found',
                    );
                }

                return new RecordResult($record, $this->findTranslations($uid, $record));
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
