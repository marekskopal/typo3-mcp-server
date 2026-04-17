<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use const JSON_THROW_ON_ERROR;

readonly class DataHandlerService
{
    /**
     * @param array<string, mixed> $fields
     * @return int The uid of the created record
     */
    public function createRecord(string $table, int $pid, array $fields): int
    {
        $newId = 'NEW' . bin2hex(random_bytes(8));
        $fields['pid'] = $pid;

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$newId => $fields]], []);
        $dataHandler->process_datamap();

        $this->checkErrors($dataHandler);

        /** @var int|string|null $uid */
        $uid = $dataHandler->substNEWwithIDs[$newId] ?? null;
        if ($uid === null) {
            throw new \RuntimeException('Failed to create record: no uid returned', 1712000020);
        }

        return (int) $uid;
    }

    /** @param array<string, mixed> $fields */
    public function updateRecord(string $table, int $uid, array $fields): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();

        $this->checkErrors($dataHandler);
    }

    public function deleteRecord(string $table, int $uid): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();

        $this->checkErrors($dataHandler);
    }

    /**
     * @param list<int> $fileUids sys_file UIDs to attach
     * @return list<int> UIDs of the created sys_file_reference records
     */
    public function createFileReferences(string $table, int $recordUid, string $fieldName, array $fileUids): array
    {
        $newIds = [];
        $datamap = [];

        foreach ($fileUids as $index => $fileUid) {
            $newId = 'NEW_ref_' . bin2hex(random_bytes(4));
            $newIds[] = $newId;

            $datamap['sys_file_reference'][$newId] = [
                'uid_local' => $fileUid,
                'uid_foreign' => $recordUid,
                'tablenames' => $table,
                'fieldname' => $fieldName,
                'sorting_foreign' => $index + 1,
                'pid' => 0,
            ];
        }

        $datamap[$table][$recordUid] = [
            $fieldName => implode(',', $newIds),
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();

        $this->checkErrors($dataHandler);

        $referenceUids = [];
        foreach ($newIds as $newId) {
            /** @var int|string|null $uid */
            $uid = $dataHandler->substNEWwithIDs[$newId] ?? null;
            if ($uid !== null) {
                $referenceUids[] = (int) $uid;
            }
        }

        return $referenceUids;
    }

    private function checkErrors(DataHandler $dataHandler): void
    {
        // @phpstan-ignore property.internal
        $errorLog = $dataHandler->errorLog;
        if ($errorLog !== []) {
            throw new \RuntimeException(
                'DataHandler errors: ' . implode('; ', array_map(
                    static fn (mixed $e): string => is_string($e) ? $e : json_encode($e, JSON_THROW_ON_ERROR),
                    $errorLog,
                )),
                1712000021,
            );
        }
    }
}
