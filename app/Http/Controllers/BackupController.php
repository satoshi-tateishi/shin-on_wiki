<?php

namespace BookStack\Http\Controllers;

use BookStack\Services\BackupService;
use BookStack\Services\DropboxService;
use BookStack\Http\Controller;
use BookStack\Permissions\Permission;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{
    private BackupService $backupService;
    private DropboxService $dropboxService;

    public function __construct(BackupService $backupService, DropboxService $dropboxService)
    {
        $this->backupService = $backupService;
        $this->dropboxService = $dropboxService;
    }

    public function runBackup(Request $request): JsonResponse
    {
        $this->checkPermission(Permission::SettingsManage);

        try {
            $request->validate([
                'upload_to_dropbox' => 'boolean',
            ]);

            $uploadToDropbox = $request->boolean('upload_to_dropbox', true);

            if ($uploadToDropbox && ! $this->dropboxService->isAuthenticated()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Dropbox認証が必要です。まず認証を完了してください。',
                ], 400);
            }

            Log::info('Starting backup from settings panel', [
                'user_id' => auth()->id(),
                'upload_to_dropbox' => $uploadToDropbox,
            ]);

            $result = $this->backupService->createFullBackup($uploadToDropbox);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'バックアップが正常に完了しました',
                    'timestamp' => $result['timestamp'],
                    'results' => $result['results'],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'],
                    'results' => $result['results'] ?? [],
                ], 500);
            }

        } catch (Exception $e) {
            Log::error('Backup failed from settings panel', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'バックアップ処理中にエラーが発生しました: '.$e->getMessage(),
            ], 500);
        }
    }

    public function listBackups(): JsonResponse
    {
        $this->checkPermission(Permission::SettingsManage);

        try {
            if (! $this->dropboxService->isAuthenticated()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Dropbox認証が必要です',
                ], 400);
            }

            $result = $this->backupService->listBackups();

            return response()->json($result);

        } catch (Exception $e) {
            Log::error('Failed to list backups from settings panel', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'バックアップ一覧の取得に失敗しました: '.$e->getMessage(),
            ], 500);
        }
    }

    public function testConnection(): JsonResponse
    {
        $this->checkPermission(Permission::SettingsManage);

        try {
            if (! $this->dropboxService->isAuthenticated()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Dropbox認証が必要です',
                ], 400);
            }

            $connectionTest = $this->dropboxService->testConnection();
            $tokenInfo = $this->dropboxService->getTokenInfo();

            return response()->json([
                'success' => true,
                'account_info' => $connectionTest,
                'token_info' => $tokenInfo,
                'message' => '接続テストが成功しました',
            ]);

        } catch (Exception $e) {
            Log::error('Connection test failed from settings panel', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => '接続テストに失敗しました: '.$e->getMessage(),
            ], 500);
        }
    }

    public function refreshToken(): JsonResponse
    {
        $this->checkPermission(Permission::SettingsManage);

        try {
            if (! $this->dropboxService->isAuthenticated()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Dropbox認証が必要です',
                ], 400);
            }

            $result = $this->dropboxService->forceRefreshToken();

            if ($result) {
                $tokenInfo = $this->dropboxService->getTokenInfo();

                return response()->json([
                    'success' => true,
                    'message' => 'トークンが正常に更新されました',
                    'token_info' => $tokenInfo,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'トークンの更新に失敗しました',
                ], 500);
            }

        } catch (Exception $e) {
            Log::error('Token refresh failed from settings panel', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'トークン更新中にエラーが発生しました: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getRestorableBackups(): JsonResponse
    {
        $this->checkPermission(Permission::SettingsManage);

        try {
            if (! $this->dropboxService->isAuthenticated()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Dropbox認証が必要です',
                ], 400);
            }

            $result = $this->backupService->getRestorableBackups();

            return response()->json($result);

        } catch (Exception $e) {
            Log::error('Failed to get restorable backups from settings panel', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => '復元可能なバックアップの取得に失敗しました: '.$e->getMessage(),
            ], 500);
        }
    }

    public function downloadBackup(Request $request): JsonResponse
    {
        $this->checkPermission(Permission::SettingsManage);

        try {
            $request->validate([
                'timestamp' => 'required|string|regex:/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/',
            ]);

            $timestamp = $request->input('timestamp');

            if (! $this->dropboxService->isAuthenticated()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Dropbox認証が必要です',
                ], 400);
            }

            Log::info('Starting backup download from settings panel', [
                'user_id' => auth()->id(),
                'timestamp' => $timestamp,
            ]);

            $result = $this->backupService->downloadBackupFromDropbox($timestamp);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'バックアップのダウンロードが完了しました',
                    'download_info' => $result,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'],
                ], 500);
            }

        } catch (Exception $e) {
            Log::error('Backup download failed from settings panel', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'バックアップのダウンロードに失敗しました: '.$e->getMessage(),
            ], 500);
        }
    }

    public function restoreDatabase(Request $request): JsonResponse
    {
        $this->checkPermission(Permission::SettingsManage);

        try {
            $request->validate([
                'timestamp' => 'required|string|regex:/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/',
                'create_backup_first' => 'boolean',
            ]);

            $timestamp = $request->input('timestamp');
            $createBackupFirst = $request->boolean('create_backup_first', true);

            if (! $this->dropboxService->isAuthenticated()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Dropbox認証が必要です',
                ], 400);
            }

            Log::info('Starting database restore from settings panel', [
                'user_id' => auth()->id(),
                'timestamp' => $timestamp,
                'create_backup_first' => $createBackupFirst,
            ]);

            // まずバックアップをダウンロード
            $downloadResult = $this->backupService->downloadBackupFromDropbox($timestamp);

            if (! $downloadResult['success']) {
                return response()->json([
                    'success' => false,
                    'error' => 'バックアップのダウンロードに失敗しました: '.$downloadResult['error'],
                ], 500);
            }

            // データベースファイルとファイルバックアップを探す
            $databaseFile = null;
            $filesBackupZip = null;
            foreach ($downloadResult['files'] as $file) {
                if ($file['type'] === 'database') {
                    $databaseFile = $file['local_path'];
                } elseif ($file['type'] === 'files') {
                    $filesBackupZip = $file['local_path'];
                }
            }

            if (! $databaseFile) {
                return response()->json([
                    'success' => false,
                    'error' => 'バックアップにデータベースファイルが含まれていません',
                ], 400);
            }

            // データベースを復元
            $restoreResult = $this->backupService->restoreDatabase($databaseFile, $createBackupFirst);

            if (! $restoreResult['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $restoreResult['error'],
                ], 500);
            }

            // ファイルを復元
            $filesRestoreResult = ['success' => true, 'message' => 'ファイルバックアップが見つかりませんでした'];
            if ($filesBackupZip) {
                $filesRestoreResult = $this->backupService->restoreFiles($filesBackupZip);
            }

            // URL自動変換（復元元と復元先のAPP_URLが異なる場合に自動で変換）
            $urlUpdateResult = $this->backupService->autoUpdateUrls();

            // クリーンアップ
            $this->backupService->cleanupRestoreFiles($timestamp);

            // URLが更新された場合はキャッシュ再生成が必要
            $urlsUpdated = !empty($urlUpdateResult['updates']);

            return response()->json([
                'success' => true,
                'message' => $urlsUpdated
                    ? 'データベースとファイルの復元が完了しました。CSSを反映するにはページをリロードしてください。'
                    : 'データベースとファイルの復元が完了しました',
                'database_restore' => $restoreResult,
                'files_restore' => $filesRestoreResult,
                'url_update' => $urlUpdateResult,
            ]);

        } catch (Exception $e) {
            Log::error('Database restore failed from settings panel', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'データベースの復元に失敗しました: '.$e->getMessage(),
            ], 500);
        }
    }

    public function validateRestore(Request $request): JsonResponse
    {
        $this->checkPermission(Permission::SettingsManage);

        try {
            $request->validate([
                'timestamp' => 'required|string|regex:/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/',
            ]);

            $timestamp = $request->input('timestamp');

            if (! $this->dropboxService->isAuthenticated()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Dropbox認証が必要です',
                ], 400);
            }

            // バックアップの詳細情報を取得
            $backups = $this->backupService->getRestorableBackups();

            if (!$backups['success']) {
                return response()->json([
                    'success' => false,
                    'error' => 'バックアップ情報の取得に失敗しました',
                ], 500);
            }

            $targetBackup = null;
            foreach ($backups['backups'] as $backup) {
                if ($backup['name'] === $timestamp) {
                    $targetBackup = $backup;
                    break;
                }
            }

            if (!$targetBackup) {
                return response()->json([
                    'success' => false,
                    'error' => '指定されたバックアップが見つかりません',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'backup' => $targetBackup,
                'warning' => '復元を実行すると、現在のデータベースが上書きされます。復元前に自動バックアップを作成することをお勧めします。',
            ]);

        } catch (Exception $e) {
            Log::error('Restore validation failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => '検証中にエラーが発生しました: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * 古いバックアップのクリーンアップ
     */
    public function cleanupBackups(Request $request)
    {
        if (!userCan('settings-manage')) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 403);
        }

        try {
            $dryRun = $request->input('dry_run', false);

            Log::info('Manual backup cleanup initiated', [
                'user_id' => auth()->id(),
                'dry_run' => $dryRun,
            ]);

            $result = $this->backupService->cleanupOldBackups($dryRun);

            return response()->json($result);

        } catch (Exception $e) {
            Log::error('Backup cleanup failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'クリーンアップ中にエラーが発生しました: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * キャッシュを再生成（復元後のCSS反映用）
     */
    public function regenerateCache(): JsonResponse
    {
        $this->checkPermission(Permission::SettingsManage);

        try {
            Log::info('Cache regeneration requested from settings panel', [
                'user_id' => auth()->id(),
            ]);

            $appPath = base_path();

            // .envファイルから環境変数を読み込んでexportコマンドを生成
            $envFile = $appPath . '/.env';
            $envVars = '';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos($line, '#') === 0) continue; // コメントをスキップ
                    if (strpos($line, '=') === false) continue;
                    // シェルで使用するためにエスケープ
                    $line = str_replace('"', '\\"', $line);
                    $envVars .= "export \"{$line}\" && ";
                }
            }

            // キャッシュをクリア
            exec("cd {$appPath} && {$envVars} php artisan cache:clear 2>&1", $output1, $return1);
            exec("cd {$appPath} && {$envVars} php artisan config:clear 2>&1", $output2, $return2);
            exec("cd {$appPath} && {$envVars} php artisan route:clear 2>&1", $output3, $return3);
            exec("cd {$appPath} && {$envVars} php artisan view:clear 2>&1", $output4, $return4);

            // キャッシュを再生成（.envの環境変数を明示的に設定）
            exec("cd {$appPath} && {$envVars} php artisan config:cache 2>&1", $output5, $return5);
            exec("cd {$appPath} && {$envVars} php artisan route:cache 2>&1", $output6, $return6);
            exec("cd {$appPath} && {$envVars} php artisan view:cache 2>&1", $output7, $return7);

            $success = ($return5 === 0 && $return6 === 0 && $return7 === 0);

            Log::info('Cache regeneration completed', [
                'success' => $success,
                'config_cache' => ['return' => $return5, 'output' => implode("\n", $output5)],
                'route_cache' => ['return' => $return6, 'output' => implode("\n", $output6)],
                'view_cache' => ['return' => $return7, 'output' => implode("\n", $output7)],
            ]);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'キャッシュの再生成が完了しました。ページをリロードしてください。',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'キャッシュの再生成に失敗しました',
                    'details' => [
                        'config' => implode("\n", $output5),
                        'route' => implode("\n", $output6),
                        'view' => implode("\n", $output7),
                    ],
                ], 500);
            }

        } catch (Exception $e) {
            Log::error('Cache regeneration failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'キャッシュ再生成中にエラーが発生しました: '.$e->getMessage(),
            ], 500);
        }
    }
}
