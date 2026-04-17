<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class FileReferenceAddTool
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private TcaSchemaService $tcaSchemaService,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(
        name: 'file_reference_add',
        description: 'Attach uploaded files to a record\'s file/image field. Pass sys_file UIDs from file_upload results.',
    )]
    public function execute(string $table, int $uid, string $fieldName, string $fileUids): string
    {
        $parsedUids = array_values(array_filter(
            array_map(static fn (string $v): int => (int) trim($v), explode(',', $fileUids)),
            static fn (int $v): bool => $v > 0,
        ));

        if ($parsedUids === []) {
            return json_encode(['error' => 'No valid file UIDs provided'], JSON_THROW_ON_ERROR);
        }

        $fileFields = $this->tcaSchemaService->getFileFields($table);
        if (!in_array($fieldName, $fileFields, true)) {
            return json_encode([
                'error' => 'Field \'' . $fieldName . '\' is not a file field on table \'' . $table . '\'',
                'availableFileFields' => $fileFields,
            ], JSON_THROW_ON_ERROR);
        }

        try {
            $referenceUids = $this->dataHandlerService->createFileReferences($table, $uid, $fieldName, $parsedUids);
        } catch (\Throwable $e) {
            $this->logger->error('file_reference_add tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode([
            'table' => $table,
            'uid' => $uid,
            'fieldName' => $fieldName,
            'referencesCreated' => count($referenceUids),
            'referenceUids' => $referenceUids,
        ], JSON_THROW_ON_ERROR);
    }
}
