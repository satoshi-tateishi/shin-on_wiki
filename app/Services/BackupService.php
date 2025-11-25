<?php

namespace BookStack\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use ZipArchive;

class BackupService
{
    private DropboxService $dropboxService;

    public function __construct(DropboxService $dropboxService)
    {
        $this->dropboxService = $dropboxService;
    }

    public function createFullBackup(bool $uploadToDropbox = true): array
    {
        $timestamp = Carbon::now(config('backup.timezone', 'Asia/Tokyo'))->format('Y-m-d_H-i-s');
        $results = [];

        try {
            Log::info('Starting full backup process', ['timestamp' => $timestamp]);

            // データベースバックアップ
            if (config('backup.database.enabled', true)) {
                $dbBackupPath = $this->createDatabaseBackup($timestamp);
                $results['database'] = [
                    'success' => true,
                    'path' => $dbBackupPath,
                    'size' => File::size($dbBackupPath),
                ];
            }

            // ファイルバックアップ
            if (config('backup.files.enabled', true)) {
                $filesBackupPath = $this->createFilesBackup($timestamp);
                $results['files'] = [
                    'success' => true,
                    'path' => $filesBackupPath,
                    'size' => File::size($filesBackupPath),
                ];
            }

            // テストファイル
            if (config('backup.test.enabled', true)) {
                $testFilePath = $this->createTestFile($timestamp);
                $results['test'] = [
                    'success' => true,
                    'path' => $testFilePath,
                    'size' => File::size($testFilePath),
                ];
            }

            // Dropboxにアップロード
            if ($uploadToDropbox && $this->dropboxService->isAuthenticated()) {
                $this->uploadBackupsToDropbox($results, $timestamp);
                $results['dropbox_upload'] = ['success' => true];
            }

            Log::info('Full backup completed successfully', [
                'timestamp' => $timestamp,
                'results' => $results,
            ]);

            return [
                'success' => true,
                'timestamp' => $timestamp,
                'results' => $results,
            ];

        } catch (Exception $e) {
            Log::error('Backup process failed', [
                'timestamp' => $timestamp,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'timestamp' => $timestamp,
                'error' => $e->getMessage(),
                'results' => $results,
            ];
        }
    }

    public function testDropboxConnection(): array
    {
        try {
            if (! $this->dropboxService->isAuthenticated()) {
                return [
                    'success' => false,
                    'error' => 'Not authenticated with Dropbox',
                ];
            }

            $connectionTest = $this->dropboxService->testConnection();

            // テストファイルのアップロード・ダウンロードテスト
            $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
            $testContent = "Dropbox connection test from BookStack at {$timestamp}";
            $testFilePath = storage_path("app/temp/dropbox_test_{$timestamp}.txt");

            // テンポラリディレクトリ作成
            $tempDir = dirname($testFilePath);
            if (! File::exists($tempDir)) {
                File::makeDirectory($tempDir, 0755, true);
            }

            File::put($testFilePath, $testContent);

            $basePath = config('backup.dropbox.folder_path', '');
            $remoteTestPath = "{$basePath}/connection-test/test_{$timestamp}.txt";

            // アップロードテスト
            $this->dropboxService->uploadFile($testFilePath, $remoteTestPath);

            // ダウンロードテスト
            $downloadedContent = $this->dropboxService->downloadFile($remoteTestPath);

            // クリーンアップ
            File::delete($testFilePath);

            if ($downloadedContent === $testContent) {
                return [
                    'success' => true,
                    'message' => 'Dropbox connection test successful',
                    'account_info' => $connectionTest,
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Upload/download content mismatch',
                ];
            }

        } catch (Exception $e) {
            Log::error('Dropbox connection test failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function listBackups(): array
    {
        try {
            if (! $this->dropboxService->isAuthenticated()) {
                return [
                    'success' => false,
                    'error' => 'Not authenticated with Dropbox',
                ];
            }

            $backups = $this->dropboxService->findBackupFolders();

            return [
                'success' => true,
                'backups' => $backups,
            ];

        } catch (Exception $e) {
            Log::error('Failed to list backups', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function createDatabaseBackup(string $timestamp): string
    {
        $filename = str_replace('{timestamp}', $timestamp, config('backup.database.filename_format', 'database_backup_{timestamp}.sql'));
        $backupPath = storage_path("app/backups/{$filename}");

        // バックアップディレクトリ作成
        $backupDir = dirname($backupPath);
        if (! File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        // データベース接続情報取得
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $host = config("database.connections.{$connection}.host");
        $port = config("database.connections.{$connection}.port");
        $username = config("database.connections.{$connection}.username");
        $password = config("database.connections.{$connection}.password");

        if ($connection === 'mysql') {
            // MySQLダンプコマンド実行
            $command = sprintf(
                'mysqldump -h%s -P%s -u%s -p%s --skip-ssl %s > %s',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($database),
                escapeshellarg($backupPath)
            );

            $result = Process::run($command);

            if (! $result->successful()) {
                throw new Exception('Database backup failed: '.$result->errorOutput());
            }
        } else {
            // SQLiteや他のデータベースの場合はLaravelのクエリを使用
            $this->createDatabaseBackupFallback($backupPath);
        }

        if (! File::exists($backupPath) || File::size($backupPath) === 0) {
            throw new Exception('Database backup file was not created or is empty');
        }

        Log::info('Database backup created', [
            'path' => $backupPath,
            'size' => File::size($backupPath),
        ]);

        return $backupPath;
    }

    private function createDatabaseBackupFallback(string $backupPath): void
    {
        $sql = "-- BookStack Database Backup\n";
        $sql .= '-- Generated on: '.Carbon::now()."\n\n";

        // 全テーブルのデータをダンプ
        $tables = DB::select('SHOW TABLES');
        $database = config('database.connections.mysql.database');
        $tableKey = 'Tables_in_'.$database;

        foreach ($tables as $table) {
            $tableName = $table->$tableKey;
            $sql .= "-- Table: {$tableName}\n";

            // テーブル構造取得
            $createTable = DB::select("SHOW CREATE TABLE `{$tableName}`")[0];
            $sql .= $createTable->{'Create Table'}.";\n\n";

            // データ取得
            $rows = DB::table($tableName)->get();
            foreach ($rows as $row) {
                $values = array_map(function ($value) {
                    return $value === null ? 'NULL' : "'".addslashes($value)."'";
                }, (array) $row);

                $sql .= "INSERT INTO `{$tableName}` VALUES (".implode(', ', $values).");\n";
            }
            $sql .= "\n";
        }

        File::put($backupPath, $sql);
    }

    private function createFilesBackup(string $timestamp): string
    {
        $filename = str_replace('{timestamp}', $timestamp, config('backup.files.filename_format', 'files_backup_{timestamp}.zip'));
        $backupPath = storage_path("app/backups/{$filename}");

        $zip = new ZipArchive;
        if ($zip->open($backupPath, ZipArchive::CREATE) !== true) {
            throw new Exception("Cannot create zip file: {$backupPath}");
        }

        $paths = config('backup.files.paths', ['storage/uploads', 'storage/logs', '.env']);
        $excludePaths = config('backup.files.exclude_paths', []);

        foreach ($paths as $path) {
            $fullPath = base_path($path);
            if (File::exists($fullPath)) {
                if (File::isDirectory($fullPath)) {
                    $this->addDirectoryToZip($zip, $fullPath, $path, $excludePaths);
                } else {
                    // 単一ファイル（.envなど）
                    $zip->addFile($fullPath, $path);
                }
            }
        }

        $zip->close();

        if (! File::exists($backupPath) || File::size($backupPath) === 0) {
            throw new Exception('Files backup was not created or is empty');
        }

        Log::info('Files backup created', [
            'path' => $backupPath,
            'size' => File::size($backupPath),
        ]);

        return $backupPath;
    }

    private function createTestFile(string $timestamp): string
    {
        $filename = str_replace('{timestamp}', $timestamp, config('backup.test.filename_format', 'test_{timestamp}.txt'));
        $backupPath = storage_path("app/backups/{$filename}");

        $content = str_replace('{timestamp}', $timestamp, config('backup.test.content', 'Dropbox backup test from BookStack at {timestamp}'));

        File::put($backupPath, $content);

        return $backupPath;
    }

    private function addDirectoryToZip(ZipArchive $zip, string $directory, string $localPath, array $excludePaths): void
    {
        $files = File::allFiles($directory);

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relativePath = $localPath.'/'.$file->getRelativePathname();

            // 除外パスチェック
            $shouldExclude = false;
            foreach ($excludePaths as $excludePath) {
                if (fnmatch($excludePath, $relativePath)) {
                    $shouldExclude = true;
                    break;
                }
            }

            if (! $shouldExclude) {
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    private function uploadBackupsToDropbox(array &$results, string $timestamp): void
    {
        foreach ($results as $type => &$result) {
            if ($result['success'] && isset($result['path'])) {
                try {
                    $filename = basename($result['path']);
                    $remotePath = $this->dropboxService->generateBackupPath($timestamp, $filename);

                    $this->dropboxService->uploadFile($result['path'], $remotePath);

                    $result['dropbox_path'] = $remotePath;
                    $result['dropbox_uploaded'] = true;

                    Log::info("Uploaded {$type} backup to Dropbox", [
                        'local_path' => $result['path'],
                        'remote_path' => $remotePath,
                    ]);

                    // Dropboxアップロード成功後、ローカルファイルを削除
                    if (File::exists($result['path'])) {
                        File::delete($result['path']);
                        Log::info("Deleted local backup file after successful upload", [
                            'local_path' => $result['path'],
                        ]);
                    }

                } catch (Exception $e) {
                    $result['dropbox_uploaded'] = false;
                    $result['dropbox_error'] = $e->getMessage();

                    Log::error("Failed to upload {$type} backup to Dropbox", [
                        'error' => $e->getMessage(),
                        'path' => $result['path'],
                    ]);
                }
            }
        }
    }

    /**
     * Dropboxから復元可能なバックアップ一覧を取得
     */
    public function getRestorableBackups(): array
    {
        try {
            if (! $this->dropboxService->isAuthenticated()) {
                return [
                    'success' => false,
                    'error' => 'Dropbox認証が必要です',
                ];
            }

            $backups = $this->dropboxService->findBackupFolders();

            $restorableBackups = [];

            foreach ($backups as $backup) {
                try {
                    // full_pathを使用してバックアップ情報を取得
                    $backupInfo = $this->getBackupInfo($backup['name'], $backup['full_path']);
                    if ($backupInfo) {
                        $restorableBackups[] = $backupInfo;
                    } else {
                        // デバッグのため、簡単な形式で追加
                        $restorableBackups[] = [
                            'name' => $backup['name'],
                            'path' => $backup['path'],
                            'date' => $this->parseBackupDate($backup['name']),
                            'files' => [['name' => 'unknown', 'size' => 0, 'type' => 'unknown']],
                            'total_size' => 0,
                        ];
                    }
                } catch (Exception $e) {
                    Log::error('Exception while getting backup info', [
                        'backup' => $backup,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'success' => true,
                'backups' => $restorableBackups,
            ];

        } catch (Exception $e) {
            Log::error('Failed to get restorable backups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'バックアップ一覧の取得に失敗しました: '.$e->getMessage(),
            ];
        }
    }

    /**
     * バックアップ情報を取得
     */
    private function getBackupInfo(string $backupName, string $backupPath): ?array
    {
        try {
            $contents = $this->dropboxService->listFolder($backupPath);

            $info = [
                'name' => $backupName,
                'path' => $backupPath,
                'date' => $this->parseBackupDate($backupName),
                'files' => [],
                'total_size' => 0,
            ];

            foreach ($contents['entries'] as $entry) {
                if ($entry['.tag'] === 'file') {
                    $filename = basename($entry['name']);
                    $size = $entry['size'] ?? 0;

                    $fileInfo = [
                        'name' => $filename,
                        'size' => $size,
                        'type' => $this->getBackupFileType($filename),
                        'path' => $entry['path_display'],
                    ];

                    $info['files'][] = $fileInfo;
                    $info['total_size'] += $size;
                }
            }

            return $info;

        } catch (Exception $e) {
            Log::error('Failed to get backup info', [
                'backup_name' => $backupName,
                'backup_path' => $backupPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * バックアップファイル種別を判定
     */
    private function getBackupFileType(string $filename): string
    {
        if (str_contains($filename, 'database_backup') && str_ends_with($filename, '.sql')) {
            return 'database';
        }
        if (str_contains($filename, 'files_backup') && str_ends_with($filename, '.zip')) {
            return 'files';
        }
        if (str_contains($filename, 'test_') && str_ends_with($filename, '.txt')) {
            return 'test';
        }
        return 'unknown';
    }

    /**
     * バックアップ名から日時を解析
     */
    private function parseBackupDate(string $backupName): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})$/', $backupName, $matches)) {
            return sprintf('%s-%s-%s %s:%s:%s', $matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6]);
        }
        return null;
    }

    /**
     * Dropboxからバックアップをダウンロード
     */
    public function downloadBackupFromDropbox(string $timestamp): array
    {
        try {
            if (! $this->dropboxService->isAuthenticated()) {
                throw new Exception('Dropbox認証が必要です');
            }

            // バックアップフォルダのパスを構築
            $backupFolders = $this->dropboxService->findBackupFolders();
            $targetBackup = null;

            foreach ($backupFolders as $backup) {
                if ($backup['name'] === $timestamp) {
                    $targetBackup = $backup;
                    break;
                }
            }

            if (! $targetBackup) {
                throw new Exception("バックアップが見つかりません: {$timestamp}");
            }

            // ダウンロード先ディレクトリを作成
            $downloadDir = storage_path("app/restore/{$timestamp}");
            if (! File::exists($downloadDir)) {
                File::makeDirectory($downloadDir, 0755, true);
            }

            // バックアップファイル一覧を取得
            $contents = $this->dropboxService->listFolder($targetBackup['full_path']);
            $downloadedFiles = [];

            foreach ($contents['entries'] as $entry) {
                if ($entry['.tag'] === 'file') {
                    $filename = basename($entry['name']);
                    $localPath = $downloadDir . '/' . $filename;

                    // Dropboxからファイルをダウンロード
                    $fileContent = $this->dropboxService->downloadFile($entry['path_display']);
                    File::put($localPath, $fileContent);

                    $downloadedFiles[] = [
                        'filename' => $filename,
                        'local_path' => $localPath,
                        'type' => $this->getBackupFileType($filename),
                        'size' => strlen($fileContent),
                    ];

                    Log::info('Downloaded backup file', [
                        'filename' => $filename,
                        'size' => strlen($fileContent),
                    ]);
                }
            }

            return [
                'success' => true,
                'download_dir' => $downloadDir,
                'files' => $downloadedFiles,
            ];

        } catch (Exception $e) {
            Log::error('Failed to download backup from Dropbox', [
                'timestamp' => $timestamp,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * データベースを復元
     */
    public function restoreDatabase(string $sqlFilePath, bool $createBackupFirst = true): array
    {
        try {
            if (! File::exists($sqlFilePath)) {
                throw new Exception("バックアップファイルが見つかりません: {$sqlFilePath}");
            }

            // 復元前に現在のデータベースをバックアップ
            if ($createBackupFirst) {
                $timestamp = Carbon::now(config('backup.timezone', 'Asia/Tokyo'))->format('Y-m-d_H-i-s');
                $preRestoreBackup = $this->createDatabaseBackup("pre_restore_{$timestamp}");

                // Dropboxにもアップロード
                if ($this->dropboxService->isAuthenticated()) {
                    try {
                        $uploadResult = $this->dropboxService->uploadFile(
                            $preRestoreBackup,
                            "/pre_restore_backups/database_backup_pre_restore_{$timestamp}.sql"
                        );
                        Log::info('Pre-restore backup uploaded to Dropbox', [
                            'local_path' => $preRestoreBackup,
                            'dropbox_path' => "/pre_restore_backups/database_backup_pre_restore_{$timestamp}.sql",
                        ]);

                        // Dropboxアップロード成功後、ローカルファイルを削除
                        if (File::exists($preRestoreBackup)) {
                            File::delete($preRestoreBackup);
                            Log::info("Deleted local pre-restore backup file after successful upload", [
                                'local_path' => $preRestoreBackup,
                            ]);
                        }
                    } catch (Exception $e) {
                        Log::warning('Failed to upload pre-restore backup to Dropbox', [
                            'error' => $e->getMessage(),
                            'local_path' => $preRestoreBackup,
                        ]);
                    }
                }

                Log::info('Created pre-restore backup', [
                    'backup_path' => $preRestoreBackup,
                ]);
            }

            // SQLファイルの妥当性をチェック
            $this->validateSqlFile($sqlFilePath);

            // データベース接続情報取得
            $connection = config('database.default');
            $database = config("database.connections.{$connection}.database");
            $host = config("database.connections.{$connection}.host");
            $port = config("database.connections.{$connection}.port");
            $username = config("database.connections.{$connection}.username");
            $password = config("database.connections.{$connection}.password");

            if ($connection === 'mysql') {
                // MySQLコマンドで復元実行
                $command = sprintf(
                    'mysql -h%s -P%s -u%s -p%s --skip-ssl %s < %s',
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($username),
                    escapeshellarg($password),
                    escapeshellarg($database),
                    escapeshellarg($sqlFilePath)
                );

                $result = Process::run($command);

                if (! $result->successful()) {
                    throw new Exception('データベースの復元に失敗しました: '.$result->errorOutput());
                }
            } else {
                throw new Exception('MySQL以外のデータベースの復元は現在サポートされていません');
            }

            Log::info('Database restored successfully', [
                'sql_file' => $sqlFilePath,
                'database' => $database,
            ]);

            return [
                'success' => true,
                'message' => 'データベースの復元が完了しました',
                'pre_restore_backup' => $createBackupFirst ? $preRestoreBackup : null,
            ];

        } catch (Exception $e) {
            Log::error('Database restore failed', [
                'sql_file' => $sqlFilePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * SQLファイルの妥当性をチェック
     */
    private function validateSqlFile(string $sqlFilePath): void
    {
        $fileSize = File::size($sqlFilePath);
        if ($fileSize === 0) {
            throw new Exception('SQLファイルが空です');
        }

        // ファイルの先頭を読んで基本的な妥当性をチェック
        $handle = fopen($sqlFilePath, 'r');
        if (! $handle) {
            throw new Exception('SQLファイルを開けません');
        }

        $firstLine = fgets($handle);
        fclose($handle);

        // SQLファイルの基本的な形式チェック（--、/*、CREATE、INSERTのいずれかを含む）
        if (! $firstLine || (! str_contains($firstLine, '--') && ! str_contains($firstLine, '/*') && ! str_contains(strtoupper($firstLine), 'CREATE') && ! str_contains(strtoupper($firstLine), 'INSERT'))) {
            throw new Exception('有効なSQLファイルではありません');
        }
    }

    /**
     * ファイルを復元
     */
    public function restoreFiles(string $zipFilePath): array
    {
        try {
            if (! File::exists($zipFilePath)) {
                throw new Exception("バックアップファイルが見つかりません: {$zipFilePath}");
            }

            $zip = new ZipArchive;
            if ($zip->open($zipFilePath) !== true) {
                throw new Exception("ZIPファイルを開けません: {$zipFilePath}");
            }

            // 一時展開先
            $tempExtractDir = storage_path('app/temp_restore_'.time());
            File::makeDirectory($tempExtractDir, 0755, true);

            // ZIPを展開
            $zip->extractTo($tempExtractDir);
            $zip->close();

            $restoredPaths = [];

            // 1. public/uploads を復元（画像ファイル）
            // 本番環境ではシンボリックリンク構成の場合があるため、サブディレクトリごとに処理
            $sourcePublicUploads = $tempExtractDir . '/public/uploads';
            $targetPublicUploads = base_path('public/uploads');

            if (File::exists($sourcePublicUploads)) {
                $this->restoreUploadsDirectory($sourcePublicUploads, $targetPublicUploads);
                $restoredPaths[] = 'public/uploads';
            }

            // 2. storage/uploads を復元（添付ファイル：PDFなど）
            $sourceStorageUploads = $tempExtractDir . '/storage/uploads';
            $targetStorageUploads = storage_path('uploads');

            if (File::exists($sourceStorageUploads)) {
                $this->restoreUploadsDirectory($sourceStorageUploads, $targetStorageUploads);
                $restoredPaths[] = 'storage/uploads';
            } else {
                Log::warning('storage/uploads not found in backup', [
                    'expected_path' => $sourceStorageUploads,
                ]);
            }

            // .env ファイルは復元しない（セキュリティ上の理由）
            // ログファイルも復元しない

            // 一時ディレクトリを削除
            File::deleteDirectory($tempExtractDir);

            // カバー画像のサムネイルを再生成
            Log::info('Starting thumbnail regeneration after restore');
            $thumbnailResult = $this->regenerateCoverThumbnails();

            return [
                'success' => true,
                'message' => 'ファイルの復元が完了しました',
                'restored_paths' => $restoredPaths,
                'thumbnail_regeneration' => $thumbnailResult,
            ];

        } catch (Exception $e) {
            Log::error('File restore failed', [
                'zip_file' => $zipFilePath,
                'error' => $e->getMessage(),
            ]);

            // クリーンアップ
            if (isset($tempExtractDir) && File::exists($tempExtractDir)) {
                File::deleteDirectory($tempExtractDir);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * アップロードディレクトリを復元（シンボリックリンク対応）
     */
    private function restoreUploadsDirectory(string $sourceDir, string $targetDir): void
    {
        // ターゲットディレクトリが存在しない場合は作成
        if (! File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        // ソースディレクトリ内のアイテムを処理
        $items = File::directories($sourceDir);
        $files = File::files($sourceDir);

        // ファイルを直接コピー
        foreach ($files as $file) {
            $targetFile = $targetDir . '/' . $file->getFilename();
            File::copy($file->getRealPath(), $targetFile);
        }

        // サブディレクトリを処理
        foreach ($items as $item) {
            $itemName = basename($item);
            $targetPath = $targetDir . '/' . $itemName;

            // ターゲットがシンボリックリンクの場合、リンク先に復元
            if (is_link($targetPath)) {
                $linkTarget = readlink($targetPath);
                // 相対パスを絶対パスに変換
                if (! str_starts_with($linkTarget, '/')) {
                    $realTarget = realpath($targetDir . '/' . $linkTarget);
                    if ($realTarget === false) {
                        // リンク先が存在しない場合は作成
                        $realTarget = $targetDir . '/' . $linkTarget;
                        File::makeDirectory($realTarget, 0755, true);
                    }
                    $targetPath = $realTarget;
                } else {
                    $targetPath = $linkTarget;
                    if (! File::exists($targetPath)) {
                        File::makeDirectory($targetPath, 0755, true);
                    }
                }

                Log::info('Restoring to symlink target', [
                    'source' => $item,
                    'symlink_target' => $targetPath,
                ]);
            }

            // 既存ディレクトリの内容をバックアップして復元
            if (File::exists($targetPath) && File::isDirectory($targetPath)) {
                // 既存ファイルを削除せずにマージ（上書き）
                File::copyDirectory($item, $targetPath);

                Log::info('Restored directory (merged)', [
                    'source' => $item,
                    'target' => $targetPath,
                ]);
            } else {
                // ディレクトリがない場合は作成してコピー
                File::makeDirectory($targetPath, 0755, true);
                File::copyDirectory($item, $targetPath);

                Log::info('Restored directory (created)', [
                    'source' => $item,
                    'target' => $targetPath,
                ]);
            }
        }

        Log::info('Uploads directory restored successfully', [
            'source' => $sourceDir,
            'target' => $targetDir,
        ]);
    }

    /**
     * カバー画像のサムネイルを再生成
     */
    public function regenerateCoverThumbnails(): array
    {
        try {
            $regenerated = [
                'bookshelves' => [],
                'books' => [],
                'errors' => [],
            ];

            // BookStackのクラスを使用
            $bookshelfClass = \BookStack\Entities\Models\Bookshelf::class;
            $bookClass = \BookStack\Entities\Models\Book::class;
            $imageResizerClass = \BookStack\Uploads\ImageResizer::class;

            // 本棚のカバー画像サムネイルを再生成
            $shelves = $bookshelfClass::whereHas('relatedData', function ($q) {
                $q->whereNotNull('image_id');
            })->get();

            $resizer = app($imageResizerClass);

            foreach ($shelves as $shelf) {
                try {
                    $image = $shelf->coverInfo()->getImage();
                    if ($image) {
                        // 各サイズのサムネイルを生成
                        $resizer->resizeToThumbnailUrl($image, 250, 250, false, true);
                        $resizer->resizeToThumbnailUrl($image, 150, 150, false, true);
                        $resizer->resizeToThumbnailUrl($image, 440, 250, false, true);

                        $regenerated['bookshelves'][] = [
                            'id' => $shelf->id,
                            'name' => $shelf->name,
                            'image_id' => $image->id,
                        ];

                        Log::info('Regenerated bookshelf cover thumbnails', [
                            'shelf_id' => $shelf->id,
                            'shelf_name' => $shelf->name,
                            'image_id' => $image->id,
                        ]);
                    }
                } catch (Exception $e) {
                    $regenerated['errors'][] = [
                        'type' => 'bookshelf',
                        'id' => $shelf->id,
                        'name' => $shelf->name,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('Failed to regenerate bookshelf cover thumbnails', [
                        'shelf_id' => $shelf->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 本のカバー画像サムネイルを再生成
            $books = $bookClass::whereHas('relatedData', function ($q) {
                $q->whereNotNull('image_id');
            })->get();

            foreach ($books as $book) {
                try {
                    $image = $book->coverInfo()->getImage();
                    if ($image) {
                        // 各サイズのサムネイルを生成
                        $resizer->resizeToThumbnailUrl($image, 250, 250, false, true);
                        $resizer->resizeToThumbnailUrl($image, 150, 150, false, true);
                        $resizer->resizeToThumbnailUrl($image, 440, 250, false, true);

                        $regenerated['books'][] = [
                            'id' => $book->id,
                            'name' => $book->name,
                            'image_id' => $image->id,
                        ];

                        Log::info('Regenerated book cover thumbnails', [
                            'book_id' => $book->id,
                            'book_name' => $book->name,
                            'image_id' => $image->id,
                        ]);
                    }
                } catch (Exception $e) {
                    $regenerated['errors'][] = [
                        'type' => 'book',
                        'id' => $book->id,
                        'name' => $book->name,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('Failed to regenerate book cover thumbnails', [
                        'book_id' => $book->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'success' => true,
                'regenerated' => $regenerated,
                'total_bookshelves' => count($regenerated['bookshelves']),
                'total_books' => count($regenerated['books']),
                'total_errors' => count($regenerated['errors']),
            ];

        } catch (Exception $e) {
            Log::error('Thumbnail regeneration failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 復元後のクリーンアップ
     */
    public function cleanupRestoreFiles(string $timestamp): void
    {
        $restoreDir = storage_path("app/restore/{$timestamp}");

        if (File::exists($restoreDir)) {
            File::deleteDirectory($restoreDir);
            Log::info('Cleaned up restore files', ['directory' => $restoreDir]);
        }
    }

    /**
     * 古いバックアップの削除
     *
     * @param bool $dryRun 削除せずに対象のみ表示
     * @return array
     */
    public function cleanupOldBackups(bool $dryRun = false): array
    {
        try {
            Log::info('Starting backup cleanup', ['dry_run' => $dryRun]);

            if (!$this->dropboxService->isAuthenticated()) {
                return [
                    'success' => false,
                    'error' => 'Dropbox not authenticated',
                ];
            }

            // 設定を取得
            $retentionDays = config('backup.retention.days', 30);
            $minimumCount = config('backup.retention.minimum_count', 3);

            // すべてのバックアップを取得
            $backups = $this->dropboxService->findBackupFolders();

            if (empty($backups)) {
                Log::info('No backups found');
                return [
                    'success' => true,
                    'message' => 'No backups found',
                    'deleted' => [],
                    'kept' => [],
                ];
            }

            // 日付順にソート（新しい順）
            usort($backups, function ($a, $b) {
                return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
            });

            $totalBackups = count($backups);
            $toDelete = [];
            $toKeep = [];
            $cutoffDate = Carbon::now(config('backup.timezone', 'Asia/Tokyo'))->subDays($retentionDays);

            // 削除対象を決定
            foreach ($backups as $index => $backup) {
                $backupDate = Carbon::parse($backup['timestamp']);
                $isOld = $backupDate->lt($cutoffDate);
                $isInMinimumRange = $index < $minimumCount;

                if ($isInMinimumRange) {
                    // 最新N個は必ず保持
                    $toKeep[] = [
                        'path' => $backup['path'],
                        'timestamp' => $backup['timestamp'],
                        'reason' => 'within minimum retention count',
                    ];
                } elseif (!$isOld) {
                    // 保持期間内は保持
                    $toKeep[] = [
                        'path' => $backup['path'],
                        'timestamp' => $backup['timestamp'],
                        'reason' => 'within retention period',
                    ];
                } else {
                    // 古いバックアップを削除対象に
                    $toDelete[] = [
                        'path' => $backup['path'],
                        'timestamp' => $backup['timestamp'],
                        'age_days' => Carbon::now(config('backup.timezone', 'Asia/Tokyo'))->diffInDays($backupDate),
                    ];
                }
            }

            // 削除後も最低保持数を確保
            $remainingCount = $totalBackups - count($toDelete);
            if ($remainingCount < $minimumCount) {
                $excessDelete = $minimumCount - $remainingCount;
                Log::warning('Adjusting deletion to maintain minimum retention count', [
                    'excess_delete' => $excessDelete,
                    'minimum_count' => $minimumCount,
                ]);

                // 削除対象から新しいものを保持に移動
                for ($i = 0; $i < $excessDelete && !empty($toDelete); $i++) {
                    $preserved = array_shift($toDelete);
                    $toKeep[] = [
                        'path' => $preserved['path'],
                        'timestamp' => $preserved['timestamp'],
                        'reason' => 'adjusted to maintain minimum count',
                    ];
                }
            }

            $result = [
                'success' => true,
                'dry_run' => $dryRun,
                'total_backups' => $totalBackups,
                'to_delete_count' => count($toDelete),
                'to_keep_count' => count($toKeep),
                'retention_days' => $retentionDays,
                'minimum_count' => $minimumCount,
                'deleted' => [],
                'kept' => $toKeep,
            ];

            // 実際の削除処理（dry_runでない場合のみ）
            if (!$dryRun && !empty($toDelete)) {
                $deleted = [];
                foreach ($toDelete as $backup) {
                    try {
                        $this->dropboxService->deleteFolder($backup['path']);
                        $deleted[] = $backup;
                        Log::info('Deleted old backup', [
                            'path' => $backup['path'],
                            'timestamp' => $backup['timestamp'],
                            'age_days' => $backup['age_days'],
                        ]);
                    } catch (Exception $e) {
                        Log::error('Failed to delete backup', [
                            'path' => $backup['path'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                $result['deleted'] = $deleted;
            } else {
                $result['to_delete'] = $toDelete;
            }

            Log::info('Backup cleanup completed', [
                'deleted_count' => count($result['deleted']),
                'kept_count' => count($toKeep),
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error('Backup cleanup failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 復元後にURLを自動変換
     * データベース内の古いURLを現在の環境のAPP_URLに変換する
     */
    public function autoUpdateUrls(): array
    {
        try {
            $currentAppUrl = rtrim(config('app.url'), '/');

            if (empty($currentAppUrl)) {
                throw new Exception('APP_URLが設定されていません');
            }

            Log::info('Starting auto URL update', [
                'target_url' => $currentAppUrl,
            ]);

            // データベース内の既存URLパターンを検出
            $detectedUrls = $this->detectExistingUrls();

            if (empty($detectedUrls)) {
                Log::info('No URLs found to update');
                return [
                    'success' => true,
                    'message' => '変換が必要なURLは見つかりませんでした',
                    'updates' => [],
                ];
            }

            $updates = [];

            foreach ($detectedUrls as $oldUrl) {
                // 現在のAPP_URLと同じ場合はスキップ
                if ($oldUrl === $currentAppUrl) {
                    Log::info('Skipping URL update - same as current', [
                        'url' => $oldUrl,
                    ]);
                    continue;
                }

                Log::info('Updating URLs', [
                    'old_url' => $oldUrl,
                    'new_url' => $currentAppUrl,
                ]);

                $result = $this->replaceUrlsInDatabase($oldUrl, $currentAppUrl);
                $updates[$oldUrl] = $result;
            }

            // キャッシュクリア
            $this->clearApplicationCache();

            Log::info('Auto URL update completed', [
                'updates' => $updates,
            ]);

            return [
                'success' => true,
                'message' => 'URLの自動変換が完了しました',
                'target_url' => $currentAppUrl,
                'updates' => $updates,
            ];

        } catch (Exception $e) {
            Log::error('Auto URL update failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * データベース内の既存URLパターンを検出
     */
    private function detectExistingUrls(): array
    {
        $urls = [];

        // imagesテーブルからURLパターンを検出
        $imageUrls = DB::table('images')
            ->select(DB::raw("DISTINCT SUBSTRING_INDEX(url, '/uploads/', 1) as base_url"))
            ->whereNotNull('url')
            ->where('url', 'like', 'http%')
            ->pluck('base_url')
            ->toArray();

        foreach ($imageUrls as $url) {
            if (!empty($url) && !in_array($url, $urls)) {
                $urls[] = $url;
            }
        }

        // attachmentsテーブルからもチェック
        $attachmentUrls = DB::table('attachments')
            ->select(DB::raw("DISTINCT SUBSTRING_INDEX(path, '/uploads/', 1) as base_url"))
            ->whereNotNull('path')
            ->where('path', 'like', 'http%')
            ->pluck('base_url')
            ->toArray();

        foreach ($attachmentUrls as $url) {
            if (!empty($url) && !in_array($url, $urls)) {
                $urls[] = $url;
            }
        }

        Log::info('Detected existing URLs in database', [
            'urls' => $urls,
        ]);

        return $urls;
    }

    /**
     * データベース内のURLを置換
     */
    private function replaceUrlsInDatabase(string $oldUrl, string $newUrl): array
    {
        $results = [];

        $columnsToUpdateByTable = [
            'attachments' => ['path'],
            'entity_page_data' => ['html', 'text', 'markdown'],
            'entity_container_data' => ['description_html'],
            'page_revisions' => ['html', 'text', 'markdown'],
            'images' => ['url'],
            'settings' => ['value'],
            'comments' => ['html'],
        ];

        foreach ($columnsToUpdateByTable as $table => $columns) {
            foreach ($columns as $column) {
                try {
                    $oldQuoted = DB::getPdo()->quote($oldUrl);
                    $newQuoted = DB::getPdo()->quote($newUrl);

                    $changeCount = DB::table($table)->update([
                        $column => DB::raw("REPLACE({$column}, {$oldQuoted}, {$newQuoted})"),
                    ]);

                    $results["{$table}.{$column}"] = $changeCount;

                    if ($changeCount > 0) {
                        Log::info("Updated URLs in {$table}->{$column}", [
                            'count' => $changeCount,
                        ]);
                    }
                } catch (Exception $e) {
                    Log::warning("Failed to update {$table}->{$column}", [
                        'error' => $e->getMessage(),
                    ]);
                    $results["{$table}.{$column}"] = "error: {$e->getMessage()}";
                }
            }
        }

        // JSON形式のURLも置換
        $oldJson = trim(json_encode($oldUrl), '"');
        $newJson = trim(json_encode($newUrl), '"');

        try {
            $oldJsonQuoted = DB::getPdo()->quote($oldJson);
            $newJsonQuoted = DB::getPdo()->quote($newJson);

            $changeCount = DB::table('settings')->update([
                'value' => DB::raw("REPLACE(value, {$oldJsonQuoted}, {$newJsonQuoted})"),
            ]);

            $results['settings.value_json'] = $changeCount;
        } catch (Exception $e) {
            Log::warning('Failed to update JSON encoded URLs in settings', [
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * アプリケーションキャッシュをクリアして再生成
     */
    private function clearApplicationCache(): void
    {
        try {
            // キャッシュをクリア
            Process::run('php /app/artisan cache:clear');
            Process::run('php /app/artisan config:clear');
            Process::run('php /app/artisan route:clear');
            Process::run('php /app/artisan view:clear');

            Log::info('Application cache cleared');

            // キャッシュを再生成
            Process::run('php /app/artisan config:cache');
            Process::run('php /app/artisan route:cache');
            Process::run('php /app/artisan view:cache');

            Log::info('Application cache regenerated after restore');
        } catch (Exception $e) {
            Log::warning('Failed to clear/regenerate cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
