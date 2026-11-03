<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use MarekSkopal\MsMcpServer\DataHandling\LocalizationFieldOverrideHook;
use Mcp\Exception\ToolCallException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class DataHandlerService
{
    public function __construct(
        private SiteFinder $siteFinder,
        private MmFieldNormalizer $mmFieldNormalizer,
        private LoggerInterface $logger,
        private TcaSchemaService $tcaSchemaService = new TcaSchemaService(),
        private LocalizationFieldOverrideHook $localizationFieldOverrideHook = new LocalizationFieldOverrideHook(),
        private ?FlexFormFieldConverter $flexFormFieldConverter = null,
    ) {
    }

    /**
     * The field data exactly as the write methods hand it to DataHandler, with MM relation fields
     * normalised to comma-separated UID strings. For dry runs: a preview has to fail where the real
     * write would, or it reports a rejected value as "would be updated".
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function normalizeFields(string $table, array $fields): array
    {
        return $this->mmFieldNormalizer->normalize($table, $fields);
    }

    /**
     * @param array<string, mixed> $fields
     * @return int The uid of the created record
     */
    public function createRecord(string $table, int $pid, array $fields): int
    {
        $newId = 'NEW' . bin2hex(random_bytes(8));
        $fields = $this->mmFieldNormalizer->normalize($table, $fields);
        $fields = $this->convertFlexForms($table, $fields, null, $pid);
        $fields['pid'] = $pid;

        // A negative pid places the page after that sibling, which is in the same site.
        $originalRequest = $table === 'pages' ? $this->ensureSiteContext(abs($pid)) : null;

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([$table => [$newId => $fields]], []);
            $this->processDatamap($dataHandler, sprintf('creating a %s record on pid %d', $table, $pid), $table, null);

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
        $fields = $this->mmFieldNormalizer->normalize($table, $fields);
        $fields = $this->convertFlexForms($table, $fields, $uid);
        $originalRequest = $table === 'pages' ? $this->ensureSiteContext($uid) : null;

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([$table => [$uid => $fields]], []);
            $this->processDatamap($dataHandler, sprintf('updating %s:%d', $table, $uid), $table, $uid);
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
        $this->processCmdmap($dataHandler, sprintf('moving %s:%d', $table, $uid), $table, $uid);
    }

    /**
     * Copy a record to a new position.
     *
     * @param int $target Positive = destination pid, negative = -(uid) of record to copy after
     * @param int $copyTreeDepth For pages: depth of subpages to include (0 = page only, 99 = all)
     * @return int The uid of the new copied record
     */
    public function copyRecord(string $table, int $uid, int $target, int $copyTreeDepth = 0): int
    {
        $originalRequest = $table === 'pages' ? $this->ensureSiteContext($uid) : null;
        $previousCopyLevels = null;

        try {
            if ($table === 'pages' && $copyTreeDepth > 0 && isset($GLOBALS['BE_USER'])) {
                /** @var BackendUserAuthentication $beUser */
                $beUser = $GLOBALS['BE_USER'];
                $previousCopyLevels = $beUser->uc['copyLevels'] ?? 0;
                $beUser->uc['copyLevels'] = $copyTreeDepth;
            }

            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], [$table => [$uid => ['copy' => $target]]]);
            $this->processCmdmap($dataHandler, sprintf('copying %s:%d', $table, $uid), $table, $uid);

            // @phpstan-ignore property.internal
            $newUid = $dataHandler->copyMappingArray[$table][$uid] ?? null;
            if (!is_int($newUid) && !is_string($newUid)) {
                throw new \RuntimeException('Copy command did not return a new record uid', 1712000040);
            }

            return (int) $newUid;
        } finally {
            if ($previousCopyLevels !== null) {
                /** @var BackendUserAuthentication $beUser */
                $beUser = $GLOBALS['BE_USER'];
                $beUser->uc['copyLevels'] = $previousCopyLevels;
            }
            if ($originalRequest !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
            }
        }
    }

    /** @param list<int> $uids */
    public function deleteRecords(string $table, array $uids): void
    {
        $cmdmap = [];
        foreach ($uids as $uid) {
            $cmdmap[$uid] = ['delete' => 1];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => $cmdmap]);
        $this->processCmdmap($dataHandler, sprintf('deleting %d %s records', count($uids), $table), $table, null);
    }

    /**
     * @param list<int> $uids
     * @param array<string, mixed> $fields
     */
    public function updateRecords(string $table, array $uids, array $fields): void
    {
        $fields = $this->mmFieldNormalizer->normalize($table, $fields);
        $datamap = [];
        foreach ($uids as $uid) {
            // A FlexForm object is resolved against each record's own data structure.
            $datamap[$uid] = $this->convertFlexForms($table, $fields, $uid);
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => $datamap], []);
        $this->processDatamap($dataHandler, sprintf('updating %d %s records', count($uids), $table), $table, null);
    }

    /** @param list<int> $uids */
    public function moveRecords(string $table, array $uids, int $target): void
    {
        $cmdmap = [];
        foreach ($uids as $uid) {
            $cmdmap[$uid] = ['move' => $target];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => $cmdmap]);
        $this->processCmdmap($dataHandler, sprintf('moving %d %s records', count($uids), $table), $table, null);
    }

    public function deleteRecord(string $table, int $uid): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['delete' => 1]]]);
        $this->processCmdmap($dataHandler, sprintf('deleting %s:%d', $table, $uid), $table, $uid);
    }

    /**
     * Execute a raw DataHandler command map. Used by workspace operations (publish, discard, version).
     *
     * @param array<string, array<int|string, array<string, mixed>>> $cmdmap
     */
    public function processCommand(array $cmdmap): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdmap);
        $this->processCmdmap(
            $dataHandler,
            'executing a command map on ' . implode(', ', array_keys($cmdmap)),
            (string) (array_key_first($cmdmap) ?? ''),
            null,
        );
    }

    /**
     * Create a translation of an existing record using TYPO3 localize command (connected mode).
     *
     * `$fields` are the translated values. They go into the record `localize` creates, in the same
     * DataHandler run (see LocalizationFieldOverrideHook), so a page's slug is generated from the
     * translated title instead of the "[Translate to …:]" one and no redirect is left behind for it.
     * Should the hook not have reached the new record, they are written with a plain update instead.
     *
     * @param array<string, mixed> $fields
     * @return int The uid of the new translated record
     */
    public function localizeRecord(string $table, int $uid, int $targetLanguageId, array $fields = []): int
    {
        $fields = $this->mmFieldNormalizer->normalize($table, $fields);
        // The translation has the type, and so the FlexForm data structure, of its source record.
        $fields = $this->convertFlexForms($table, $fields, $uid);
        $originalRequest = $table === 'pages' ? $this->ensureSiteContext($uid) : null;
        $overridesRegistered = $fields !== [] && $this->registerLocalizationOverrides($table, $uid, $targetLanguageId, $fields);

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], [$table => [$uid => ['localize' => $targetLanguageId]]]);
            $this->processCmdmap(
                $dataHandler,
                sprintf('localizing %s:%d into language %d', $table, $uid, $targetLanguageId),
                $table,
                $uid,
            );

            // @phpstan-ignore property.internal
            $newUid = $dataHandler->copyMappingArray[$table][$uid] ?? null;
            if (!is_int($newUid) && !is_string($newUid)) {
                throw new \RuntimeException('Localize command did not return a new record uid', 1712000030);
            }
        } finally {
            // Released on failure too: a pending override must never reach an unrelated later run.
            $overridesUnapplied = $overridesRegistered
                && $this->localizationFieldOverrideHook->release($table, $uid, $targetLanguageId);
            if ($originalRequest !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
            }
        }

        if ($fields !== [] && (!$overridesRegistered || $overridesUnapplied)) {
            $this->updateRecord($table, (int) $newUid, $fields);
        }

        return (int) $newUid;
    }

    /**
     * @param list<int> $fileUids sys_file UIDs to attach
     * @param array<int, array<string, mixed>> $referenceFields extra sys_file_reference values (alternative, title, …)
     *     per entry of $fileUids, by index; the relation columns are always set here and cannot be overridden
     * @return list<int> UIDs of the created sys_file_reference records
     */
    public function createFileReferences(
        string $table,
        int $recordUid,
        string $fieldName,
        array $fileUids,
        array $referenceFields = [],
    ): array {
        $newIds = [];
        $datamap = [];

        foreach ($fileUids as $index => $fileUid) {
            $newId = 'NEW_ref_' . bin2hex(random_bytes(4));
            $newIds[] = $newId;

            $datamap['sys_file_reference'][$newId] = array_merge($referenceFields[$index] ?? [], [
                'uid_local' => $fileUid,
                'uid_foreign' => $recordUid,
                'tablenames' => $table,
                'fieldname' => $fieldName,
                'sorting_foreign' => $index + 1,
                'pid' => 0,
            ]);
        }

        $datamap[$table][$recordUid] = [
            $fieldName => implode(',', $newIds),
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $this->processDatamap(
            $dataHandler,
            sprintf('attaching %d file references to %s:%d', count($fileUids), $table, $recordUid),
            $table,
            $recordUid,
        );

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

    /**
     * FlexForm values given as objects become DataHandler's flex array, validated against the record's data
     * structure (#40). The stored record is only read when there is such a value.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function convertFlexForms(string $table, array $fields, ?int $uid, int $pid = 0): array
    {
        if ($this->flexFormFieldConverter === null || !$this->flexFormFieldConverter->hasObjectValues($table, $fields)) {
            return $fields;
        }

        $row = $uid !== null ? (BackendUtility::getRecordWSOL($table, $uid) ?? []) : ['pid' => $pid];

        return $this->flexFormFieldConverter->toDataHandler($table, $fields, $row);
    }

    /** @param array<string, mixed> $fields */
    private function registerLocalizationOverrides(string $table, int $uid, int $targetLanguageId, array $fields): bool
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig($table);
        if ($translationConfig['languageField'] === null || $translationConfig['transOrigPointerField'] === null) {
            return false;
        }

        $this->localizationFieldOverrideHook->register(
            $table,
            $uid,
            $targetLanguageId,
            $translationConfig['languageField'],
            $translationConfig['transOrigPointerField'],
            $fields,
        );

        return true;
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

    private function processDatamap(DataHandler $dataHandler, string $subject, string $table, ?int $uid): void
    {
        $this->run(static function () use ($dataHandler): void {
            $dataHandler->process_datamap();
        }, $dataHandler, $subject, $table, $uid);
    }

    private function processCmdmap(DataHandler $dataHandler, string $subject, string $table, ?int $uid): void
    {
        $this->run(static function () use ($dataHandler): void {
            $dataHandler->process_cmdmap();
        }, $dataHandler, $subject, $table, $uid);
    }

    /**
     * Runs DataHandler and turns both of its failure modes into a client-visible error.
     *
     * DataHandler itself does not throw: it collects refusals ("Attempt to modify record … without
     * permission", an invalid value) in `errorLog`, which are TYPO3's own editor-facing messages
     * and safe to relay. A hook can throw, though — and the hooks that matter run *after* the row
     * was written (EXT:redirects reacts to a slug change from `processDatamap_afterDatabaseOperations`).
     * Reported as a generic internal error, the client cannot tell that its change went through.
     * The raw message stays in the log (a DBAL exception embeds SQL and parameters); the client
     * gets the exception class, the code, what was being done and the fact that it may have landed.
     *
     * @param callable(): void $process
     */
    private function run(callable $process, DataHandler $dataHandler, string $subject, string $table, ?int $uid): void
    {
        try {
            $process();
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('TYPO3 DataHandler threw while ' . $subject, ['exception' => $e, 'table' => $table, 'uid' => $uid]);

            throw new ToolCallException(
                sprintf(
                    'TYPO3 DataHandler threw %s (code %d) while %s. Hooks run after the row is written, so the change'
                        . ' may already have been applied — read the record back to verify. The exception is in the TYPO3 log.',
                    $e::class,
                    (int) $e->getCode(),
                    $subject,
                ),
                1725700010,
                $e,
            );
        }

        // @phpstan-ignore property.internal
        $errorLog = $dataHandler->errorLog;
        if ($errorLog !== []) {
            throw new ToolCallException(
                sprintf('TYPO3 DataHandler reported errors while %s: %s', $subject, $this->formatErrorLog($errorLog)),
                1712000021,
            );
        }
    }

    /**
     * DataHandler::$errorLog is declared list<non-empty-string> on v14 but only array on v13, so on v13 the
     * entries arrive as mixed. Declaring the parameter mixed-valued normalizes them on both versions without
     * the check becoming dead code on v14. Core only ever appends strings; the fallback is a last resort.
     *
     * @param array<mixed> $errorLog
     */
    private function formatErrorLog(array $errorLog): string
    {
        $messages = array_map(
            static fn (mixed $message): string => is_string($message) ? $message : get_debug_type($message),
            $errorLog,
        );

        return implode('; ', $messages);
    }
}
