<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use CurlHandle;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use const CURLE_ABORTED_BY_CALLBACK;
use const CURLINFO_REDIRECT_URL;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FILE;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_NOPROGRESS;
use const CURLOPT_RESOLVE;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const CURLOPT_USERAGENT;
use const CURLOPT_XFERINFOFUNCTION;
use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_VALIDATE_IP;
use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

readonly class FileService
{
    public function __construct(
        private StorageRepository $storageRepository,
        private ConnectionPool $connectionPool,
        private StoragePermissionService $storagePermissionService,
    ) {
    }

    /**
     * Accessible storages and the filemounts that bound them.
     *
     * Without this the client has no way to learn where it may work: a non-admin's storage root is
     * normally outside their mounts, so probing with `file_list('/')` only yields a permission error.
     *
     * @return array{storages: list<array{uid: int, name: string, fullAccess: bool, mounts: list<array{path: string, title: string, readOnly: bool}>}>}
     */
    public function listStorages(): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return ['storages' => []];
        }

        $storages = [];
        foreach ($backendUser->getFileStorages() as $storageUid => $storage) {
            $this->storagePermissionService->applyUserPermissions($storage, $backendUser);

            $mounts = array_map(static fn (array $mount): array => [
                'path' => $mount['folder']->getIdentifier(),
                'title' => $mount['title'],
                'readOnly' => $mount['readOnly'],
            ], $this->getFileMounts($storage));

            $storages[] = [
                'uid' => (int) $storageUid,
                'name' => $storage->getName(),
                // Admins are not confined to mounts; for them every path from '/' down is valid.
                'fullAccess' => !$storage->getEvaluatePermissions(),
                'mounts' => $mounts,
            ];
        }

        return ['storages' => $storages];
    }

    /** @return array{files: list<array{name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int}>, directories: list<array{name: string, identifier: string, modificationTime: int}>, totalFiles: int, totalDirectories: int} */
    public function listDirectory(int $storageUid, string $directoryPath, int $limit, int $offset): array
    {
        $limit = min(max($limit, 1), 500);

        $storage = $this->getStorage($storageUid);

        // A mounted non-admin cannot read the storage root, so listing '/' would only ever throw a
        // permission error and leave the client stuck. Mirror the backend file list and present the
        // mount folders as the entries of the root instead.
        $mountFolders = $this->getRootMountFolders($storage, $directoryPath);
        if ($mountFolders !== null) {
            return [
                'files' => [],
                'directories' => array_map($this->mapFolderToArray(...), array_slice($mountFolders, $offset, $limit)),
                'totalFiles' => 0,
                'totalDirectories' => count($mountFolders),
            ];
        }

        $folder = $storage->getFolder($directoryPath);

        $totalFiles = $storage->countFilesInFolder($folder);
        $totalDirectories = $storage->countFoldersInFolder($folder);

        $files = [];
        foreach ($storage->getFilesInFolder($folder, $offset, $limit) as $file) {
            $files[] = $this->mapFileToArray($file);
        }

        $directories = [];
        foreach ($storage->getFoldersInFolder($folder, $offset, $limit) as $subfolder) {
            $directories[] = $this->mapFolderToArray($subfolder);
        }

        return [
            'files' => $files,
            'directories' => $directories,
            'totalFiles' => $totalFiles,
            'totalDirectories' => $totalDirectories,
        ];
    }

    /** @return array{uid: int, name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int, publicUrl: string|null} */
    public function getFileInfo(int $storageUid, string $fileIdentifier): array
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002001);
        }

        return [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
            'extension' => $file->getExtension(),
            'modificationTime' => $file->getModificationTime(),
            'publicUrl' => $file->getPublicUrl(),
        ];
    }

    /** @return array{uid: int, name: string, identifier: string, size: int, mimeType: string} */
    public function uploadFile(int $storageUid, string $directoryPath, string $fileName, string $content): array
    {
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryPath);

        $tempFile = tempnam(sys_get_temp_dir(), 'mcp_upload_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file', 1712002003);
        }

        try {
            file_put_contents($tempFile, $content);
            $file = $storage->addFile($tempFile, $folder, $fileName);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
        ];
    }

    /** @return array{uid: int, name: string, identifier: string, size: int, mimeType: string} */
    public function uploadFileFromUrl(int $storageUid, string $directoryPath, string $url, string $fileName = ''): array
    {
        $this->assertHttpUrl($url);

        if ($fileName === '') {
            $path = parse_url($url, PHP_URL_PATH);
            $fileName = is_string($path) ? basename($path) : '';
            if ($fileName === '' || $fileName === '.') {
                $fileName = 'download_' . bin2hex(random_bytes(4));
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'mcp_url_upload_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file', 1712002003);
        }

        try {
            $this->downloadToFile($url, $tempFile);

            $storage = $this->getStorage($storageUid);
            $folder = $storage->getFolder($directoryPath);
            $file = $storage->addFile($tempFile, $folder, $fileName);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
        ];
    }

    /**
     * SSRF-safe download into $tempFile.
     *
     * Pins DNS resolution to a single validated IP per hop via CURLOPT_RESOLVE so a
     * short-TTL DNS rebind can't switch the connection to a private IP mid-request,
     * and revalidates the host on every redirect so a public URL can't 30x into the
     * cloud metadata service.
     */
    private function downloadToFile(string $url, string $tempFile): void
    {
        $maxRedirects = 5;
        // 100 MB
        $maxSize = 104857600;

        $fp = fopen($tempFile, 'w');
        if ($fp === false) {
            throw new \RuntimeException('Failed to open temporary file for writing', 1712002016);
        }

        try {
            $currentUrl = $url;
            $completed = false;

            for ($hop = 0; $hop <= $maxRedirects; $hop++) {
                $hopHost = parse_url($currentUrl, PHP_URL_HOST);
                $hopScheme = parse_url($currentUrl, PHP_URL_SCHEME);
                if (
                    !is_string($hopHost)
                    || $hopHost === ''
                    || !is_string($hopScheme)
                    || !in_array($hopScheme, ['http', 'https'], true)
                ) {
                    throw new \RuntimeException('Redirect target is not a valid http(s) URL', 1712002017);
                }

                $parsedPort = parse_url($currentUrl, PHP_URL_PORT);
                $hopPort = is_int($parsedPort) ? $parsedPort : ($hopScheme === 'https' ? 443 : 80);

                $resolvedIp = $this->resolveAndValidateHost($hopHost);

                // Discard any body from the previous (redirect) hop.
                ftruncate($fp, 0);
                rewind($fp);

                $ch = curl_init();
                if ($ch === false) {
                    throw new \RuntimeException('Failed to initialize cURL', 1712002011);
                }

                curl_setopt_array($ch, [
                    CURLOPT_URL => $currentUrl,
                    CURLOPT_RESOLVE => [$hopHost . ':' . $hopPort . ':' . $resolvedIp],
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_FILE => $fp,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERAGENT => 'TYPO3-MCP-Server/1.0',
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_XFERINFOFUNCTION => static fn (
                        CurlHandle $_,
                        int $_dlTotal,
                        int $dlNow,
                    ): int => $dlNow > $maxSize ? 1 : 0,
                ]);

                $result = curl_exec($ch);
                $errno = curl_errno($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                // cURL handles auto-destruct in PHP 8+; curl_close() is deprecated and unnecessary.

                if ($result === false) {
                    if ($errno === CURLE_ABORTED_BY_CALLBACK) {
                        throw new \RuntimeException('Downloaded file exceeds maximum allowed size of 100 MB', 1712002012);
                    }

                    throw new \RuntimeException('Failed to download file from URL', 1712002011);
                }

                if ($status >= 200 && $status < 300) {
                    $completed = true;
                    break;
                }

                if ($status >= 300 && $status < 400) {
                    if (!is_string($redirectUrl) || $redirectUrl === '') {
                        throw new \RuntimeException('Redirect response missing Location header', 1712002018);
                    }
                    $currentUrl = $redirectUrl;
                    continue;
                }

                throw new \RuntimeException('Upstream returned HTTP ' . $status, 1712002019);
            }

            if (!$completed) {
                throw new \RuntimeException('Too many redirects (max ' . $maxRedirects . ')', 1712002020);
            }
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    private function assertHttpUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only http and https URLs are allowed', 1712002010);
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('Invalid URL: missing host', 1712002014);
        }
    }

    private function resolveAndValidateHost(string $host): string
    {
        $resolvedIp = gethostbyname($host);
        if (
            $resolvedIp === $host
            || filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw new \RuntimeException('URL resolves to a private or reserved IP address', 1712002015);
        }

        return $resolvedIp;
    }

    /** @return array{name: string, identifier: string} */
    public function createDirectory(int $storageUid, string $parentPath, string $directoryName): array
    {
        $storage = $this->getStorage($storageUid);
        $parentFolder = $storage->getFolder($parentPath);
        $folder = $storage->createFolder($directoryName, $parentFolder);

        return [
            'name' => $folder->getName(),
            'identifier' => $folder->getIdentifier(),
        ];
    }

    public function copyFile(int $storageUid, string $fileIdentifier, string $targetDirectoryPath): void
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002007);
        }

        $targetFolder = $storage->getFolder($targetDirectoryPath);
        $storage->copyFile($file, $targetFolder);
    }

    public function moveFile(int $storageUid, string $fileIdentifier, string $targetDirectoryPath): void
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002005);
        }

        $targetFolder = $storage->getFolder($targetDirectoryPath);
        $storage->moveFile($file, $targetFolder);
    }

    public function renameFile(int $storageUid, string $fileIdentifier, string $newName): void
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002006);
        }

        $storage->renameFile($file, $newName);
    }

    public function deleteFile(int $storageUid, string $fileIdentifier): void
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002004);
        }

        $storage->deleteFile($file);
    }

    public function moveDirectory(int $storageUid, string $directoryIdentifier, string $targetDirectoryPath): void
    {
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryIdentifier);
        $targetFolder = $storage->getFolder($targetDirectoryPath);
        $storage->moveFolder($folder, $targetFolder);
    }

    public function renameDirectory(int $storageUid, string $directoryIdentifier, string $newName): void
    {
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryIdentifier);
        $storage->renameFolder($folder, $newName);
    }

    public function deleteDirectory(int $storageUid, string $directoryIdentifier, bool $recursive): void
    {
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryIdentifier);
        $storage->deleteFolder($folder, $recursive);
    }

    /** @return array{files: list<array{name: string, identifier: string, size: int, mimeType: string, extension: string, storage: int}>, total: int} */
    public function searchFiles(int $storageUid, string $namePattern, string $extension, int $limit, int $offset): array
    {
        // Enforce storage access before querying sys_file directly: every other file operation
        // resolves through getStorage() (backed by the user's accessible storages), but this method
        // reads the table straight, so without this gate any user could enumerate file metadata of
        // any storage uid, including storages they have no access to.
        $storage = $this->getStorage($storageUid);

        $limit = min(max($limit, 1), 500);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $countQueryBuilder->getRestrictions()->removeAll();

        $queryBuilder->select('name', 'identifier', 'size', 'mime_type', 'extension', 'storage')->from('sys_file');
        $countQueryBuilder->count('uid')->from('sys_file');

        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq('storage', $queryBuilder->createNamedParameter($storageUid, ParameterType::INTEGER)),
        );
        $countQueryBuilder->andWhere(
            $countQueryBuilder->expr()->eq('storage', $countQueryBuilder->createNamedParameter($storageUid, ParameterType::INTEGER)),
        );

        // Storage access alone still spans the whole storage; the backend confines a non-admin
        // user to their filemount folders, so mirror that here. With permission evaluation on and
        // no filemounts, nothing in the storage is reachable — return empty rather than everything.
        if ($storage->getEvaluatePermissions()) {
            $mountIdentifiers = array_map(strval(...), array_keys($storage->getFileMounts()));
            if ($mountIdentifiers === []) {
                return ['files' => [], 'total' => 0];
            }

            foreach ([$queryBuilder, $countQueryBuilder] as $builder) {
                $mountConditions = array_map(
                    static fn(string $mountIdentifier): string => $builder->expr()->like(
                        'identifier',
                        $builder->createNamedParameter($builder->escapeLikeWildcards($mountIdentifier) . '%'),
                    ),
                    $mountIdentifiers,
                );
                $builder->andWhere($builder->expr()->or(...$mountConditions));
            }
        }

        if ($namePattern !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like('name', $queryBuilder->createNamedParameter('%' . $namePattern . '%')),
            );
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->like('name', $countQueryBuilder->createNamedParameter('%' . $namePattern . '%')),
            );
        }

        if ($extension !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('extension', $queryBuilder->createNamedParameter($extension)),
            );
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq('extension', $countQueryBuilder->createNamedParameter($extension)),
            );
        }

        /** @var int|string $totalResult */
        $totalResult = $countQueryBuilder->executeQuery()->fetchOne();

        /** @var list<array{name: string, identifier: string, size: int, mime_type: string, extension: string, storage: int}> $rows */
        $rows = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $files = array_map(static fn (array $row): array => [
            'name' => $row['name'],
            'identifier' => $row['identifier'],
            'size' => (int) $row['size'],
            'mimeType' => $row['mime_type'],
            'extension' => $row['extension'],
            'storage' => (int) $row['storage'],
        ], $rows);

        return [
            'files' => $files,
            'total' => (int) $totalResult,
        ];
    }

    private function getStorage(int $storageUid): ResourceStorage
    {
        // Resolve through the backend user's accessible storages rather than StorageRepository
        // directly: getFileStorages() returns only the storages the user may use (admins get every
        // storage with full access), so a programmatic lookup cannot be used to reach a storage the
        // user holds no mount in.
        //
        // That call alone does *not* confine the user within the storage, though: the filemounts and
        // file-operation rights are attached by core's StoragePermissionsAspect, which only runs for
        // backend requests and so never fires on the MCP paths. StoragePermissionService applies them
        // here — see its class comment.
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication) {
            $storage = $backendUser->getFileStorages()[$storageUid] ?? null;
            if ($storage instanceof ResourceStorage) {
                $this->storagePermissionService->applyUserPermissions($storage, $backendUser);

                return $storage;
            }

            throw new \RuntimeException('Storage not found or not accessible: ' . $storageUid, 1712002000);
        }

        // No backend-user context (not reachable on the authenticated tool paths).
        $storage = $this->storageRepository->findByUid($storageUid);
        if ($storage === null) {
            throw new \RuntimeException('Storage not found: ' . $storageUid, 1712002000);
        }

        return $storage;
    }

    /**
     * Typed view of the filemounts registered with a storage.
     *
     * `ResourceStorage::getFileMounts()` is untyped and its rows are raw `sys_filemounts` records
     * with a `folder` object grafted on, so narrow them once here rather than at each use site.
     *
     * @return list<array{folder: Folder, title: string, readOnly: bool}>
     */
    private function getFileMounts(ResourceStorage $storage): array
    {
        $mounts = [];

        /** @var mixed $fileMount */
        foreach ($storage->getFileMounts() as $fileMount) {
            if (!is_array($fileMount)) {
                continue;
            }

            /** @var mixed $folder */
            $folder = $fileMount['folder'] ?? null;
            if (!$folder instanceof Folder) {
                continue;
            }

            /** @var mixed $title */
            $title = $fileMount['title'] ?? null;

            $mounts[] = [
                'folder' => $folder,
                'title' => is_string($title) ? $title : '',
                'readOnly' => (bool) ($fileMount['read_only'] ?? false),
            ];
        }

        return $mounts;
    }

    /**
     * The user's filemount folders, when they should stand in for the storage root listing.
     *
     * Returns null whenever the requested path can be listed normally: a path other than the root,
     * an unrestricted (admin) storage, a storage whose root is itself a mount, or a restricted
     * storage with no usable mount at all — the last case falls through to the regular code path so
     * the caller gets core's permission error rather than a misleading empty listing.
     *
     * @return list<Folder>|null
     */
    private function getRootMountFolders(ResourceStorage $storage, string $directoryPath): ?array
    {
        if (!in_array(trim($directoryPath), ['', '/'], true) || !$storage->getEvaluatePermissions()) {
            return null;
        }

        $folders = [];
        foreach ($this->getFileMounts($storage) as $mount) {
            if ($mount['folder']->getIdentifier() === '/') {
                return null;
            }

            $folders[] = $mount['folder'];
        }

        return $folders === [] ? null : $folders;
    }

    /** @return array{name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int} */
    private function mapFileToArray(File $file): array
    {
        return [
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
            'extension' => $file->getExtension(),
            'modificationTime' => $file->getModificationTime(),
        ];
    }

    /** @return array{name: string, identifier: string, modificationTime: int} */
    private function mapFolderToArray(Folder $folder): array
    {
        return [
            'name' => $folder->getName(),
            'identifier' => $folder->getIdentifier(),
            'modificationTime' => $folder->getModificationTime(),
        ];
    }
}
