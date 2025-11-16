<?php

namespace BookStack\Console\Commands;

use BookStack\Services\BackupService;
use Exception;
use Illuminate\Console\Command;

class BackupDropboxCommand extends Command
{
    protected $signature = 'backup:dropbox
                            {--test : Run connection test only}
                            {--no-upload : Create backup without uploading to Dropbox}
                            {--db-only : Backup database only}
                            {--files-only : Backup files only}';

    protected $description = 'Create backup and upload to Dropbox';

    public function handle(BackupService $backupService): int
    {
        try {
            $this->info('🚀 Starting Dropbox backup process...');

            // 接続テストのみの場合
            if ($this->option('test')) {
                return $this->runConnectionTest($backupService);
            }

            // フルバックアップ実行
            $uploadToDropbox = ! $this->option('no-upload');
            $result = $backupService->createFullBackup($uploadToDropbox);

            if ($result['success']) {
                $this->info('✅ Backup completed successfully!');
                $this->info("📁 Timestamp: {$result['timestamp']}");

                $this->displayBackupResults($result['results']);

                // 古いバックアップのクリーンアップ
                if ($uploadToDropbox) {
                    $this->info('🧹 Cleaning up old backups...');
                    $cleanupResult = $backupService->cleanupOldBackups(false);

                    if ($cleanupResult['success']) {
                        $deletedCount = isset($cleanupResult['deleted']) ? count($cleanupResult['deleted']) : 0;
                        if ($deletedCount > 0) {
                            $this->info("✅ Cleaned up {$deletedCount} old backup(s)");
                        } else {
                            $this->info('ℹ️  No old backups to clean up');
                        }
                    } else {
                        $this->warn('⚠️  Cleanup failed: ' . ($cleanupResult['error'] ?? 'Unknown error'));
                    }
                }

                return Command::SUCCESS;
            } else {
                $this->error("❌ Backup failed: {$result['error']}");

                if (! empty($result['results'])) {
                    $this->displayBackupResults($result['results']);
                }

                return Command::FAILURE;
            }

        } catch (Exception $e) {
            $this->error("❌ Backup process failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    private function runConnectionTest(BackupService $backupService): int
    {
        $this->info('🔄 Testing Dropbox connection...');

        $result = $backupService->testDropboxConnection();

        if ($result['success']) {
            $this->info('✅ Dropbox connection test successful!');

            if (isset($result['account_info'])) {
                $account = $result['account_info'];
                $this->info("👤 Account: {$account['account_name']}");
                $this->info("🆔 ID: {$account['account_id']}");
                if (isset($account['email'])) {
                    $this->info("📧 Email: {$account['email']}");
                }
            }

            return Command::SUCCESS;
        } else {
            $this->error("❌ Connection test failed: {$result['error']}");

            return Command::FAILURE;
        }
    }

    private function displayBackupResults(array $results): void
    {
        $this->newLine();
        $this->info('📊 Backup Results:');

        foreach ($results as $type => $result) {
            if (! is_array($result)) {
                continue;
            }

            $status = $result['success'] ? '✅' : '❌';
            $this->info("  {$status} ".ucfirst($type).' backup');

            if (isset($result['path'])) {
                $this->info('    📂 Local: '.basename($result['path']));
            }

            if (isset($result['size'])) {
                $sizeMB = round($result['size'] / 1024 / 1024, 2);
                $this->info("    📏 Size: {$sizeMB} MB");
            }

            if (isset($result['dropbox_uploaded'])) {
                if ($result['dropbox_uploaded']) {
                    $this->info('    ☁️  Dropbox: ✅ Uploaded');
                    if (isset($result['dropbox_path'])) {
                        $this->info("    🔗 Remote: {$result['dropbox_path']}");
                    }
                } else {
                    $this->error('    ☁️  Dropbox: ❌ Failed');
                    if (isset($result['dropbox_error'])) {
                        $this->error("    ⚠️  Error: {$result['dropbox_error']}");
                    }
                }
            }

            $this->newLine();
        }
    }
}
