# Dropboxバックアップ・復元機能 - 実装ガイド

Laravel アプリケーションに Dropbox を使用したバックアップ・復元機能を実装するための技術ドキュメント

## 目次

- [概要](#概要)
- [アーキテクチャ](#アーキテクチャ)
- [主要コンポーネント](#主要コンポーネント)
- [実装手順](#実装手順)
- [コード詳細](#コード詳細)
- [トラブルシューティング](#トラブルシューティング)
- [ベストプラクティス](#ベストプラクティス)

## 概要

### 機能一覧

1. **OAuth 2.0 認証**
   - Dropbox との安全な連携
   - リフレッシュトークンによる自動更新
   - トークン有効期限管理

2. **データベースバックアップ**
   - mysqldump による完全バックアップ
   - SSL証明書対応（自己署名証明書サポート）
   - MariaDB/MySQL 両対応

3. **ファイルバックアップ**
   - アップロードファイルの ZIP 圧縮
   - 除外パス指定可能
   - 環境設定ファイルのバックアップ

4. **Dropbox アップロード**
   - 日付別フォルダ構造
   - 大容量ファイル対応（150MB まで）
   - 自動リトライ機能

5. **復元機能**
   - データベースとファイルの一括復元
   - 復元前の自動バックアップ
   - 安全性チェック

### 技術スタック

- **Framework**: Laravel 11.x
- **PHP**: 8.2+
- **Database**: MySQL 8.x / MariaDB 11.x
- **Storage**: Dropbox API v2
- **Package**: `spatie/flysystem-dropbox` ^3.0

## アーキテクチャ

### ディレクトリ構造

```
app/
├── Config/
│   └── backup.php                    # バックアップ設定
├── Console/
│   └── Commands/
│       └── BackupDropboxCommand.php  # CLI コマンド
├── Http/
│   └── Controllers/
│       ├── BackupController.php      # バックアップAPI
│       └── DropboxAuthController.php # OAuth認証
├── Models/
│   └── DropboxToken.php              # トークン管理
└── Services/
    ├── BackupService.php             # バックアップロジック
    └── DropboxService.php            # Dropbox API連携

database/
└── migrations/
    └── 2025_xx_xx_create_dropbox_tokens_table.php

resources/
└── views/
    └── settings/
        └── categories/
            └── features.blade.php    # UI

routes/
└── web.php                           # ルート定義
```

### データフロー

```
[ユーザー] → [UI/CLI] → [BackupController/Command]
                              ↓
                         [BackupService]
                              ↓
                    ┌─────────┴─────────┐
                    ↓                   ↓
            [Database Backup]    [Files Backup]
              (mysqldump)           (ZipArchive)
                    ↓                   ↓
                    └─────────┬─────────┘
                              ↓
                        [DropboxService]
                              ↓
                         [Dropbox API]
```

## 主要コンポーネント

### 1. OAuth 認証システム

#### DropboxAuthController.php

OAuth 2.0 フローを実装：

```php
// 認証開始
public function redirect(): RedirectResponse
{
    $url = 'https://www.dropbox.com/oauth2/authorize';
    $params = http_build_query([
        'client_id' => config('backup.dropbox.client_id'),
        'redirect_uri' => config('backup.dropbox.redirect_uri'),
        'response_type' => 'code',
        'token_access_type' => 'offline', // リフレッシュトークン取得
        'scope' => config('backup.dropbox.scope'),
    ]);
    return redirect("{$url}?{$params}");
}

// コールバック処理
public function callback(Request $request): RedirectResponse
{
    $code = $request->input('code');

    // アクセストークン取得
    $response = Http::withBasicAuth(
        config('backup.dropbox.client_id'),
        config('backup.dropbox.client_secret')
    )->asForm()->post('https://api.dropbox.com/oauth2/token', [
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => config('backup.dropbox.redirect_uri'),
    ]);

    // トークン保存
    DropboxToken::saveToken($response->json());
}
```

#### DropboxToken Model

```php
class DropboxToken extends Model
{
    protected $fillable = [
        'service_name',
        'access_token',
        'access_token_expires_at',
        'refresh_token',
        'account_id',
        'account_name',
        'scope',
        'is_active',
        'last_refreshed_at',
    ];

    // アクティブなトークン取得
    public static function getActiveToken(): ?self
    {
        return self::where('is_active', true)
            ->where('service_name', 'backup')
            ->first();
    }

    // トークン期限チェック
    public function isAccessTokenExpired(): bool
    {
        if (!$this->access_token_expires_at) {
            return false;
        }
        return Carbon::now()->isAfter($this->access_token_expires_at);
    }

    // リフレッシュトークン有効性チェック
    public function hasValidRefreshToken(): bool
    {
        return !empty($this->refresh_token);
    }
}
```

### 2. バックアップサービス

#### BackupService.php - データベースバックアップ

```php
private function createDatabaseBackup(string $timestamp): string
{
    $filename = "database_backup_{$timestamp}.sql";
    $backupPath = storage_path("app/backups/{$filename}");

    // データベース接続情報
    $connection = config('database.default');
    $database = config("database.connections.{$connection}.database");
    $host = config("database.connections.{$connection}.host");
    $port = config("database.connections.{$connection}.port");
    $username = config("database.connections.{$connection}.username");
    $password = config("database.connections.{$connection}.password");

    // mysqldump コマンド実行（SSL対応）
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

    if (!$result->successful()) {
        throw new Exception('Database backup failed: ' . $result->errorOutput());
    }

    return $backupPath;
}
```

**重要ポイント**:
- `--skip-ssl`: 自己署名証明書エラーを回避（MySQL/MariaDB 両対応）
- `escapeshellarg()`: インジェクション対策
- プロセス結果チェック

#### BackupService.php - ファイルバックアップ

```php
private function createFilesBackup(string $timestamp): string
{
    $filename = "files_backup_{$timestamp}.zip";
    $backupPath = storage_path("app/backups/{$filename}");

    $zip = new ZipArchive;
    if ($zip->open($backupPath, ZipArchive::CREATE) !== true) {
        throw new Exception("Cannot create zip file: {$backupPath}");
    }

    $paths = config('backup.files.paths', ['public/uploads']);
    $excludePaths = config('backup.files.exclude_paths', []);

    foreach ($paths as $path) {
        $fullPath = base_path($path);
        if (File::exists($fullPath)) {
            if (File::isDirectory($fullPath)) {
                $this->addDirectoryToZip($zip, $fullPath, $path, $excludePaths);
            } else {
                $zip->addFile($fullPath, $path);
            }
        }
    }

    $zip->close();
    return $backupPath;
}

private function addDirectoryToZip(ZipArchive $zip, string $directory, string $localPath, array $excludePaths): void
{
    $files = File::allFiles($directory);

    foreach ($files as $file) {
        $filePath = $file->getRealPath();
        $relativePath = $localPath . '/' . $file->getRelativePathname();

        // 除外パスチェック
        $shouldExclude = false;
        foreach ($excludePaths as $excludePath) {
            if (fnmatch($excludePath, $relativePath)) {
                $shouldExclude = true;
                break;
            }
        }

        if (!$shouldExclude) {
            $zip->addFile($filePath, $relativePath);
        }
    }
}
```

**重要ポイント**:
- ZIP 圧縮でストレージ節約
- 除外パスで不要ファイルをスキップ
- 相対パス保持で復元時の整合性確保

### 3. Dropbox サービス

#### DropboxService.php - アップロード

```php
public function uploadFile(string $filePath, string $remotePath): bool
{
    if (!$this->isAuthenticated()) {
        throw new Exception('Not authenticated with Dropbox');
    }

    $fileContent = file_get_contents($filePath);
    $fileSizeMB = strlen($fileContent) / 1024 / 1024;

    // ファイルサイズ制限チェック（150MB）
    if (strlen($fileContent) >= config('backup.dropbox.max_file_size', 150 * 1024 * 1024)) {
        throw new Exception('File too large: ' . number_format($fileSizeMB, 2) . 'MB');
    }

    try {
        // 階層フォルダを作成
        $this->ensureFoldersExist($remotePath);

        // Dropbox API v2 でアップロード
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getValidAccessToken(),
            'Content-Type' => 'application/octet-stream',
            'Dropbox-API-Arg' => json_encode([
                'path' => $remotePath,
                'mode' => 'overwrite',
                'autorename' => false,
            ]),
        ])->withBody($fileContent, 'application/octet-stream')
          ->post('https://content.dropboxapi.com/2/files/upload');

        if (!$response->successful()) {
            throw new Exception('Upload failed: ' . $response->body());
        }

        return true;

    } catch (Exception $e) {
        // トークンエラー時は自動リフレッシュして再試行
        if (strpos($e->getMessage(), 'invalid_access_token') !== false) {
            if ($this->tokenModel && $this->tokenModel->hasValidRefreshToken()) {
                $this->refreshAccessToken();
                return $this->uploadFile($filePath, $remotePath);
            }
        }
        throw $e;
    }
}

// トークン自動リフレッシュ
private function refreshAccessToken(): string
{
    $response = Http::withBasicAuth(
        config('backup.dropbox.client_id'),
        config('backup.dropbox.client_secret')
    )->asForm()->post('https://api.dropbox.com/oauth2/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $this->tokenModel->refresh_token,
    ]);

    if (!$response->successful()) {
        throw new Exception('Token refresh failed: ' . $response->body());
    }

    $data = $response->json();

    $this->tokenModel->update([
        'access_token' => $data['access_token'],
        'access_token_expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 14400),
        'last_refreshed_at' => Carbon::now(),
    ]);

    return $data['access_token'];
}
```

**重要ポイント**:
- トークン自動リフレッシュで長期運用に対応
- 階層フォルダの自動作成
- エラー時の自動リトライ

#### DropboxService.php - パス生成

```php
public function generateBackupPath(string $timestamp, string $fileName): string
{
    $now = Carbon::now(config('backup.timezone', 'Asia/Tokyo'));
    $basePath = config('backup.dropbox.folder_path', '');

    $pathParts = [
        $now->format('Y'),
        $now->format('m'),
        $now->format('d'),
        $timestamp,
        $fileName
    ];

    if (!empty($basePath)) {
        // 先頭のスラッシュを削除してダブルスラッシュを防ぐ
        $basePath = ltrim($basePath, '/');
        $pathParts = array_merge([$basePath], $pathParts);
    }

    return '/' . implode('/', $pathParts);
}
```

**生成例**:
```
/shin-on_wiki-backup/2025/11/15/2025-11-15_14-30-00/database_backup_2025-11-15_14-30-00.sql
```

### 4. 復元システム

#### BackupService.php - データベース復元

```php
public function restoreDatabase(string $sqlFilePath, bool $createBackupFirst = true): array
{
    try {
        // 復元前バックアップ
        if ($createBackupFirst) {
            $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
            $preRestoreBackup = $this->createDatabaseBackup("pre_restore_{$timestamp}");

            // Dropbox にもアップロード
            if ($this->dropboxService->isAuthenticated()) {
                $this->dropboxService->uploadFile(
                    $preRestoreBackup,
                    "/pre_restore_backups/database_backup_pre_restore_{$timestamp}.sql"
                );
            }
        }

        // SQL ファイル検証
        $this->validateSqlFile($sqlFilePath);

        // データベース接続情報
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $host = config("database.connections.{$connection}.host");
        $port = config("database.connections.{$connection}.port");
        $username = config("database.connections.{$connection}.username");
        $password = config("database.connections.{$connection}.password");

        // MySQL コマンドで復元（SSL対応）
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

        if (!$result->successful()) {
            throw new Exception('データベースの復元に失敗しました: ' . $result->errorOutput());
        }

        return ['success' => true, 'message' => 'データベースの復元が完了しました'];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// SQL ファイル検証
private function validateSqlFile(string $sqlFilePath): void
{
    $fileSize = File::size($sqlFilePath);
    if ($fileSize === 0) {
        throw new Exception('SQLファイルが空です');
    }

    // ファイルの先頭を読んで基本的な妥当性をチェック
    $handle = fopen($sqlFilePath, 'r');
    if (!$handle) {
        throw new Exception('SQLファイルを開けません');
    }

    $firstLine = fgets($handle);
    fclose($handle);

    // MySQL/MariaDB 両対応の形式チェック
    // --、/*、CREATE、INSERT のいずれかを含む
    if (!$firstLine ||
        (!str_contains($firstLine, '--') &&
         !str_contains($firstLine, '/*') &&
         !str_contains(strtoupper($firstLine), 'CREATE') &&
         !str_contains(strtoupper($firstLine), 'INSERT'))) {
        throw new Exception('有効なSQLファイルではありません');
    }
}
```

**重要ポイント**:
- 復元前の自動バックアップで安全性確保
- SQL ファイル検証で破損ファイルを検出
- MariaDB の `/*` 形式コメントに対応

#### BackupService.php - ファイル復元

```php
public function restoreFiles(string $zipFilePath): array
{
    try {
        if (!File::exists($zipFilePath)) {
            throw new Exception("バックアップファイルが見つかりません: {$zipFilePath}");
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFilePath) !== true) {
            throw new Exception("ZIPファイルを開けません: {$zipFilePath}");
        }

        // 一時展開先
        $tempExtractDir = storage_path('app/temp_restore_' . time());
        File::makeDirectory($tempExtractDir, 0755, true);

        // ZIP を展開
        $zip->extractTo($tempExtractDir);
        $zip->close();

        // ファイルを復元（public/uploads のみ）
        $sourceUploadDir = $tempExtractDir . '/public/uploads';
        $targetUploadDir = base_path('public/uploads');

        if (File::exists($sourceUploadDir)) {
            // 既存のアップロードディレクトリをバックアップ
            $backupUploadDir = base_path('public/uploads_backup_' . time());
            if (File::exists($targetUploadDir)) {
                File::moveDirectory($targetUploadDir, $backupUploadDir);
            }

            // 復元
            File::copyDirectory($sourceUploadDir, $targetUploadDir);
        }

        // 一時ディレクトリを削除
        File::deleteDirectory($tempExtractDir);

        return [
            'success' => true,
            'message' => 'ファイルの復元が完了しました',
        ];

    } catch (Exception $e) {
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
```

**重要ポイント**:
- 既存ファイルをバックアップしてから復元
- セキュリティのため `.env` は復元しない
- 一時ファイルの確実なクリーンアップ

### 5. UI 実装（CSP 対応）

#### features.blade.php - イベントリスナー

```javascript
// バックアップ一覧読み込み
async function loadBackupList() {
    const response = await fetch('/api/backup/restorable');
    const data = await response.json();

    if (data.success && data.backups && data.backups.length > 0) {
        let html = '<table class="table"><thead><tr>' +
            '<th>日時</th><th>ファイル数</th><th>サイズ</th><th>操作</th>' +
            '</tr></thead><tbody>';

        data.backups.forEach(backup => {
            html += '<tr>' +
                '<td><strong>' + backup.name + '</strong></td>' +
                '<td>' + backup.files.length + ' ファイル</td>' +
                '<td>' + (backup.total_size / 1024 / 1024).toFixed(2) + ' MB</td>' +
                '<td>' +
                // CSP 対応: onclick ではなく data 属性を使用
                '<button class="button backup-download-btn" data-timestamp="' + backup.name + '">ダウンロード</button> ' +
                '<button class="button backup-restore-btn" data-timestamp="' + backup.name + '">復元</button>' +
                '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        backupList.innerHTML = html;

        // イベントリスナーを追加（CSP 対応）
        backupList.querySelectorAll('.backup-download-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                downloadBackup(this.dataset.timestamp);
            });
        });
        backupList.querySelectorAll('.backup-restore-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                restoreBackup(this.dataset.timestamp);
            });
        });
    }
}

// 復元処理
async function restoreBackup(timestamp) {
    try {
        // バリデーション
        const validateResponse = await fetch('/api/backup/validate-restore', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.getAttribute('content') || ''
            },
            body: JSON.stringify({ timestamp: timestamp })
        });

        const validateData = await validateResponse.json();

        if (!validateData.success) {
            showAlert('danger', '検証失敗: ' + validateData.error);
            return;
        }

        // 確認ダイアログ
        const confirmMessage =
            '【警告】データベースを復元します\n\n' +
            '復元するバックアップ: ' + timestamp + '\n' +
            validateData.warning + '\n\n' +
            '本当に実行しますか？';

        if (!confirm(confirmMessage)) {
            return;
        }

        const createBackup = confirm('復元前に現在のデータベースをバックアップしますか？\n（強く推奨）');

        // 復元実行
        showAlert('info', 'データベースを復元中... しばらくお待ちください');

        const restoreResponse = await fetch('/api/backup/restore', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.getAttribute('content') || ''
            },
            body: JSON.stringify({
                timestamp: timestamp,
                create_backup_first: createBackup
            })
        });

        const restoreData = await restoreResponse.json();

        if (restoreData.success) {
            showAlert('success', '✅ データベースの復元が完了しました！');
            setTimeout(() => {
                if (confirm('復元が完了しました。ページをリロードしますか？')) {
                    window.location.reload();
                }
            }, 2000);
        } else {
            showAlert('danger', '❌ 復元失敗: ' + restoreData.error);
        }

    } catch (error) {
        showAlert('danger', 'エラー: ' + error.message);
    }
}
```

**重要ポイント**:
- **CSP 対応**: inline `onclick` ではなく `addEventListener` を使用
- **CSRF 保護**: BookStack の `meta[name="token"]` を使用
- **ユーザー確認**: 復元前に複数回確認
- **安全オプション**: 復元前バックアップの推奨

## 実装手順

### ステップ 1: データベース準備

```bash
php artisan make:migration create_dropbox_tokens_table
```

```php
Schema::create('dropbox_tokens', function (Blueprint $table) {
    $table->id();
    $table->string('service_name')->default('backup');
    $table->text('access_token');
    $table->timestamp('access_token_expires_at')->nullable();
    $table->text('refresh_token')->nullable();
    $table->string('account_id')->nullable();
    $table->string('account_name')->nullable();
    $table->text('scope')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_refreshed_at')->nullable();
    $table->timestamps();

    $table->index(['service_name', 'is_active']);
});
```

```bash
php artisan migrate
```

### ステップ 2: 環境変数設定

`.env`:
```env
# Dropbox OAuth 2.0
DROPBOX_CLIENT_ID=your_app_key
DROPBOX_CLIENT_SECRET=your_app_secret
DROPBOX_REDIRECT_URI="${APP_URL}/auth/dropbox/callback"

# Dropbox Backup Settings
DROPBOX_BACKUP_FOLDER=/your-app-backup
DROPBOX_ACCESS_TOKEN_LIFETIME=14400

# Backup Configuration
BACKUP_TIMEZONE=Asia/Tokyo
BACKUP_RETENTION_DAYS=30
```

### ステップ 3: 設定ファイル作成

`app/Config/backup.php`:
```php
return [
    'dropbox' => [
        'client_id' => env('DROPBOX_CLIENT_ID'),
        'client_secret' => env('DROPBOX_CLIENT_SECRET'),
        'redirect_uri' => env('DROPBOX_REDIRECT_URI'),
        'folder_path' => env('DROPBOX_BACKUP_FOLDER', ''),
        'access_token_lifetime' => env('DROPBOX_ACCESS_TOKEN_LIFETIME', 14400),
        'scope' => 'files.content.write files.content.read account_info.read',
        'max_file_size' => 150 * 1024 * 1024, // 150MB
    ],

    'database' => [
        'enabled' => true,
        'filename_format' => 'database_backup_{timestamp}.sql',
    ],

    'files' => [
        'enabled' => true,
        'filename_format' => 'files_backup_{timestamp}.zip',
        'paths' => [
            'public/uploads',   // アップロードファイル
            'storage/logs',     // ログファイル
            '.env',             // 環境設定
        ],
        'exclude_paths' => [
            'storage/app/backups/*',
            'node_modules/*',
            'vendor/*',
            '.git/*',
        ],
    ],

    'timezone' => env('BACKUP_TIMEZONE', 'Asia/Tokyo'),
    'retention_days' => env('BACKUP_RETENTION_DAYS', 30),
];
```

### ステップ 4: パッケージインストール

```bash
composer require spatie/flysystem-dropbox:^3.0
```

### ステップ 5: ルート設定

`routes/web.php`:
```php
use App\Http\Controllers\DropboxAuthController;
use App\Http\Controllers\BackupController;

// Dropbox OAuth
Route::get('/auth/dropbox/redirect', [DropboxAuthController::class, 'redirect'])
    ->middleware('auth');
Route::get('/auth/dropbox/callback', [DropboxAuthController::class, 'callback'])
    ->middleware('auth');

// Backup API
Route::prefix('api')->middleware('auth')->group(function () {
    Route::get('/dropbox/auth-status', [BackupController::class, 'authStatus']);
    Route::post('/dropbox/refresh-token', [BackupController::class, 'refreshToken']);
    Route::post('/dropbox/revoke-auth', [BackupController::class, 'revokeAuth']);

    Route::post('/backup/run', [BackupController::class, 'runBackup']);
    Route::post('/backup/test', [BackupController::class, 'testConnection']);
    Route::get('/backup/restorable', [BackupController::class, 'getRestorableBackups']);
    Route::post('/backup/validate-restore', [BackupController::class, 'validateRestore']);
    Route::post('/backup/restore', [BackupController::class, 'restoreDatabase']);
    Route::post('/backup/download', [BackupController::class, 'downloadBackup']);
});
```

### ステップ 6: 自動バックアップ設定

`app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    // 毎日深夜2時に自動バックアップ
    $schedule->command('backup:dropbox')->dailyAt('02:00');
}
```

Cron設定:
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## トラブルシューティング

### 1. SSL 証明書エラー

**症状**:
```
ERROR 2026 (HY000): TLS/SSL error: self-signed certificate
```

**解決策**:
```php
// mysqldump と mysql コマンドに --skip-ssl オプションを追加
$command = sprintf(
    'mysqldump -h%s -P%s -u%s -p%s --skip-ssl %s > %s',
    // ...
);

$command = sprintf(
    'mysql -h%s -P%s -u%s -p%s --skip-ssl %s < %s',
    // ...
);
```

### 2. MariaDB SQL 検証エラー

**症状**:
```
有効なSQLファイルではありません
```

**原因**: MariaDB の `/*M!999999\-` 形式コメントを検証していない

**解決策**:
```php
// /* 形式のコメントも受け入れる
if (!$firstLine ||
    (!str_contains($firstLine, '--') &&
     !str_contains($firstLine, '/*') &&  // 追加
     !str_contains(strtoupper($firstLine), 'CREATE') &&
     !str_contains(strtoupper($firstLine), 'INSERT'))) {
    throw new Exception('有効なSQLファイルではありません');
}
```

### 3. CSP 違反エラー

**症状**:
```
Refused to execute inline event handler because it violates CSP
```

**解決策**:
```html
<!-- ❌ 悪い例 -->
<button onclick="restoreBackup('2025-11-15_14-30-00')">復元</button>

<!-- ✅ 良い例 -->
<button class="backup-restore-btn" data-timestamp="2025-11-15_14-30-00">復元</button>

<script nonce="{{ $cspNonce }}">
backupList.querySelectorAll('.backup-restore-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        restoreBackup(this.dataset.timestamp);
    });
});
</script>
```

### 4. Dropbox パスエラー

**症状**:
```
path/malformed_path
```

**原因**: パスにダブルスラッシュ (`//`) が含まれている

**解決策**:
```php
// 先頭のスラッシュを削除
$basePath = ltrim($basePath, '/');
$pathParts = array_merge([$basePath], $pathParts);
return '/' . implode('/', $pathParts);
```

### 5. トークン有効期限切れ

**症状**:
```
Access token expired
```

**解決策**: 自動リフレッシュを実装
```php
private function getValidAccessToken(): string
{
    if ($this->tokenModel->isAccessTokenExpired()) {
        if ($this->tokenModel->hasValidRefreshToken()) {
            return $this->refreshAccessToken();
        }
        throw new Exception('Access token expired and no refresh token available');
    }
    return $this->tokenModel->access_token;
}
```

## ベストプラクティス

### 1. セキュリティ

#### トークンの保護
```php
// ❌ 悪い例: トークンをログに出力
Log::info('Token: ' . $token);

// ✅ 良い例: トークン情報を隠す
Log::info('Token saved', ['account_id' => $accountId]);
```

#### 復元前のバックアップ
```php
// 常に復元前バックアップを作成
if ($createBackupFirst) {
    $preRestoreBackup = $this->createDatabaseBackup("pre_restore_{$timestamp}");
}
```

#### .env ファイルの扱い
```php
// バックアップには含めるが、復元では適用しない
// 環境ごとに設定が異なるため
```

### 2. エラーハンドリング

#### 詳細なログ記録
```php
try {
    $result = $this->uploadFile($path, $remotePath);
} catch (Exception $e) {
    Log::error('Backup upload failed', [
        'file' => $path,
        'remote_path' => $remotePath,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    throw $e;
}
```

#### ユーザーフレンドリーなエラーメッセージ
```php
// 技術的な詳細はログに、ユーザーには分かりやすいメッセージを
return response()->json([
    'success' => false,
    'error' => 'バックアップのアップロードに失敗しました。管理者に問い合わせてください。',
], 500);
```

### 3. パフォーマンス

#### バックアップサイズの制限
```php
// 大容量ファイルを除外
'exclude_paths' => [
    'storage/app/backups/*',
    'node_modules/*',
    'vendor/*',
    '*.log',
],
```

#### 非同期処理（推奨）
```php
// キューを使用して重い処理をバックグラウンド化
dispatch(new BackupToDropboxJob($timestamp));
```

### 4. 運用

#### 定期的なテスト
```bash
# 月1回は復元テストを実施
php artisan backup:dropbox
php artisan backup:restore {timestamp} --test
```

#### バックアップ保持期限
```php
// 古いバックアップを自動削除
$schedule->command('backup:cleanup')->weekly();
```

#### モニタリング
```php
// バックアップ失敗時は管理者に通知
if (!$result['success']) {
    Notification::send($admins, new BackupFailedNotification($result['error']));
}
```

### 5. テスト

#### ユニットテスト例
```php
public function test_backup_creates_database_dump()
{
    $service = new BackupService(new DropboxService());
    $timestamp = Carbon::now()->format('Y-m-d_H-i-s');

    $result = $service->createDatabaseBackup($timestamp);

    $this->assertFileExists($result);
    $this->assertGreaterThan(0, filesize($result));
}

public function test_restore_validates_sql_file()
{
    $service = new BackupService(new DropboxService());

    // 無効なファイルで例外が発生することを確認
    $this->expectException(Exception::class);
    $this->expectExceptionMessage('有効なSQLファイルではありません');

    $service->restoreDatabase('/path/to/invalid.sql', false);
}
```

## まとめ

この実装ガイドでは、Laravel アプリケーションに Dropbox バックアップ・復元機能を実装する方法を詳しく解説しました。

### 主要な実装ポイント

1. **OAuth 2.0 認証**: リフレッシュトークンで長期運用に対応
2. **データベースバックアップ**: mysqldump で完全バックアップ、SSL 対応
3. **ファイルバックアップ**: ZIP 圧縮で効率的な保存
4. **Dropbox 連携**: API v2 使用、自動リトライ実装
5. **安全な復元**: 復元前バックアップ、SQL 検証
6. **CSP 対応 UI**: セキュアなイベントハンドリング

### 応用例

- **複数クラウド対応**: AWS S3、Google Drive などにも対応可能
- **差分バックアップ**: フルバックアップと差分バックアップの組み合わせ
- **暗号化**: バックアップファイルの暗号化
- **マルチテナント**: テナントごとのバックアップ管理

このガイドを参考に、プロジェクトの要件に合わせてカスタマイズしてください。

## ライセンス

このドキュメントは MIT ライセンスで公開されています。

---

**作成日**: 2025-11-15
**更新日**: 2025-11-15
**バージョン**: 1.0.0
