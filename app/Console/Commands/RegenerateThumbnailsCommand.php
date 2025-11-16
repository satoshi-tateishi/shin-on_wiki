<?php

namespace BookStack\Console\Commands;

use BookStack\Services\BackupService;
use Exception;
use Illuminate\Console\Command;

class RegenerateThumbnailsCommand extends Command
{
    protected $signature = 'bookstack:regenerate-thumbnails';

    protected $description = 'Regenerate all cover image thumbnails for books and bookshelves';

    public function handle(BackupService $backupService): int
    {
        try {
            $this->info('🔄 Starting thumbnail regeneration...');

            $result = $backupService->regenerateCoverThumbnails();

            if ($result['success']) {
                $this->newLine();
                $this->info('✅ Thumbnail regeneration completed successfully!');
                $this->newLine();

                $this->info("📊 Summary:");
                $this->info("  📚 Bookshelves: {$result['total_bookshelves']} regenerated");
                $this->info("  📖 Books: {$result['total_books']} regenerated");

                if ($result['total_errors'] > 0) {
                    $this->newLine();
                    $this->warn("⚠️  Errors: {$result['total_errors']}");

                    foreach ($result['regenerated']['errors'] as $error) {
                        $this->error("  • {$error['type']} '{$error['name']}' (ID: {$error['id']}): {$error['error']}");
                    }
                }

                return Command::SUCCESS;
            } else {
                $this->error("❌ Thumbnail regeneration failed: {$result['error']}");

                return Command::FAILURE;
            }

        } catch (Exception $e) {
            $this->error("❌ Thumbnail regeneration failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
