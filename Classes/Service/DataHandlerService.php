<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use const JSON_THROW_ON_ERROR;

readonly class DataHandlerService
{
    public function __construct(private SiteFinder $siteFinder)
    {
    }

    /**
     * @param array<string, mixed> $fields
     * @return int The uid of the created record
     */
    public function createRecord(string $table, int $pid, array $fields): int
    {
        $newId = 'NEW' . bin2hex(random_bytes(8));
        $fields['pid'] = $pid;

        $originalRequest = $table === 'pages' ? $this->ensureSiteContext($pid) : null;

        try {
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
        } finally {
            if ($originalRequest !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
            }
        }
    }

    /** @param array<string, mixed> $fields */
    public function updateRecord(string $table, int $uid, array $fields): void
    {
        $originalRequest = $table === 'pages' ? $this->ensureSiteContext($uid) : null;

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([$table => [$uid => $fields]], []);
            $dataHandler->process_datamap();

            $this->checkErrors($dataHandler);
        } finally {
            if ($originalRequest !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
            }
        }
    }

    /**
     * Move a record to a new position.
     *
     * @param int $target Positive = page pid (move to top of page), negative = -(uid) of record to move after
     */
    public function moveRecord(string $table, int $uid, int $target): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['move' => $target]]]);
        $dataHandler->process_cmdmap();

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
     * Create a translation of an existing record using TYPO3 localize command (connected mode).
     *
     * @return int The uid of the new translated record
     */
    public function localizeRecord(string $table, int $uid, int $targetLanguageId): int
    {
        $originalRequest = $table === 'pages' ? $this->ensureSiteContext($uid) : null;

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], [$table => [$uid => ['localize' => $targetLanguageId]]]);
            $dataHandler->process_cmdmap();

            $this->checkErrors($dataHandler);

            // @phpstan-ignore property.internal
            $newUid = $dataHandler->copyMappingArray[$table][$uid] ?? null;
            if (!is_int($newUid) && !is_string($newUid)) {
                throw new \RuntimeException('Localize command did not return a new record uid', 1712000030);
            }

            return (int) $newUid;
        } finally {
            if ($originalRequest !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
            }
        }
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

    private function ensureSiteContext(int $pageId): ?ServerRequestInterface
    {
        if (!isset($GLOBALS['TYPO3_REQUEST'])) {
            return null;
        }

        /** @var ServerRequestInterface $originalRequest */
        $originalRequest = $GLOBALS['TYPO3_REQUEST'];

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $GLOBALS['TYPO3_REQUEST'] = $originalRequest->withAttribute('site', $site);
        } catch (SiteNotFoundException) {
            // No site found for this page — leave request unchanged
        }

        return $originalRequest;
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
