# Dropboxバックアップ・復元ガイド

BookStackのDropboxバックアップ・復元機能の完全ガイドです。

## 📋 目次

- [概要](#概要)
- [セットアップ](#セットアップ)
- [バックアップ](#バックアップ)
- [自動バックアップ](#自動バックアップ)
- [復元](#復元)
- [サムネイル再生成](#サムネイル再生成)
- [トラブルシューティング](#トラブルシューティング)
- [API エンドポイント](#apiエンドポイント)

---

## 概要

このシステムは、BookStackのデータを自動的にDropboxにバックアップし、必要に応じて復元する機能を提供します。

### バックアップ対象

1. **データベース** (MySQLダンプ)
2. **ファイル** (アップロード画像、ログ、.envなど)
3. **テストファイル** (接続確認用)

### 重要な機能

- ✅ 自動Dropboxアップロード
- ✅ 復元前の自動バックアップ
- ✅ **復元後の自動サムネイル再生成** (2025-11-16追加)
- ✅ 詳細なログ記録

---

## セットアップ

### 1. Dropboxアプリの作成

1. [Dropbox App Console](https://www.dropbox.com/developers/apps) にアクセス
2. 「Create app」をクリック
3. 以下の設定を選択:
   - **API**: Scoped access
   - **Access**: Full Dropbox
   - **App name**: 任意の名前 (例: shin-on_wiki-backup)
4. 「Create app」をクリック

### 2. Dropboxアプリの設定

#### Permissions タブ
以下のスコープを有効化:
- ✅ `files.content.write`
- ✅ `files.content.read`
- ✅ `account_info.read`

「Submit」をクリックして保存

#### Settings タブ
1. **App key** と **App secret** をコピー
2. **Redirect URIs** に以下を追加:
   ```
   http://localhost:8083/auth/dropbox/callback
   ```
   本番環境では実際のドメインを使用:
   ```
   https://yourdomain.com/auth/dropbox/callback
   ```

### 3. 環境変数の設定

`.env` ファイルに以下を追加:

```bash
# Dropbox OAuth 2.0 Configuration
DROPBOX_CLIENT_ID=your_app_key_here
DROPBOX_CLIENT_SECRET=your_app_secret_here
DROPBOX_REDIRECT_URI="${APP_URL}/auth/dropbox/callback"

# Dropbox Backup Settings
DROPBOX_BACKUP_FOLDER=/shin-on_wiki-backup
DROPBOX_ACCESS_TOKEN_LIFETIME=14400

# Backup Configuration
BACKUP_TIMEZONE=Asia/Tokyo
BACKUP_RETENTION_DAYS=30
```

### 4. データベースマイグレーション

```bash
docker exec shin-on_wiki_app_1 php artisan migrate
```

これにより `dropbox_tokens` テーブルが作成されます。

### 5. mysqldump のインストール (Docker環境)

アプリケーションコンテナに `mysqldump` をインストール:

```bash
docker exec shin-on_wiki_app_1 apt-get update
docker exec shin-on_wiki_app_1 apt-get install -y default-mysql-client
```

**永続化するには** `Dockerfile` に追加:
```dockerfile
RUN apt-get update && apt-get install -y default-mysql-client
```

### 6. Web UIからの初回認証

1. `/settings/features` にアクセス
2. 「Dropboxバックアップ」セクションへ移動
3. 「Dropboxと連携」ボタンをクリック
4. Dropboxの認証画面で「許可」をクリック
5. 「接続テスト」ボタンで認証状態を確認

---

## バックアップ

### 基本的なバックアップ

```bash
# フルバックアップ（データベース + ファイル）
docker exec shin-on_wiki_app_1 php artisan backup:dropbox

# Dropbox接続テストのみ
docker exec shin-on_wiki_app_1 php artisan backup:dropbox --test

# バックアップ作成のみ（Dropboxアップロードなし）
docker exec shin-on_wiki_app_1 php artisan backup:dropbox --no-upload
```

### オプション

| オプション | 説明 |
|-----------|------|
| `--test` | Dropbox接続テストのみ実行 |
| `--no-upload` | ローカルバックアップのみ（Dropboxアップロードなし） |
| `--db-only` | データベースのみバックアップ |
| `--files-only` | ファイルのみバックアップ |

### バックアップファイル命名規則

```
2025-11-16_10-42-07/
├── database_backup_2025-11-16_10-42-07.sql
├── files_backup_2025-11-16_10-42-07.zip
└── test_2025-11-16_10-42-07.txt
```

---

## 復元

### 復元プロセス

復元は以下の順序で実行されます：

```
1. Dropboxからバックアップをダウンロード
2. 現在のデータベースをバックアップ（安全対策）
3. データベースを復元
4. ファイルを復元
5. 🆕 カバー画像のサムネイルを自動再生成
6. 完了
```

### 復元コマンド（推定）

```bash
# バックアップ一覧を表示
docker exec shin-on_wiki_app_1 php artisan restore:dropbox:list

# 特定のバックアップから復元
docker exec shin-on_wiki_app_1 php artisan restore:dropbox <timestamp>
# 例: docker exec shin-on_wiki_app_1 php artisan restore:dropbox 2025-11-16_10-42-07
```

### 復元時の注意事項

⚠️ **重要**: 復元を実行すると、現在のデータは上書きされます。

- 復元前に自動的に現在のデータベースがバックアップされます
- バックアップは `/pre_restore_backups/` に保存されます
- .envファイルは復元されません（セキュリティ対策）

---

## サムネイル再生成

### なぜサムネイル再生成が必要？

**問題**: Dropbox復元時に、元画像は復元されますが、サムネイル画像（thumbs-150-150、thumbs-250-250など）は含まれません。

**解決策**: 復元後に自動的にサムネイルを再生成します。

### 自動再生成（2025-11-16追加）

復元時に**自動的に**実行されます。手動での操作は不要です。

```bash
# 復元コマンドを実行すると、自動的にサムネイルが再生成される
docker exec shin-on_wiki_app_1 php artisan restore:dropbox <timestamp>

# 復元ログに以下が表示される
# "Starting thumbnail regeneration after restore"
# "✅ Thumbnail regeneration completed successfully!"
```

### 手動再生成

必要に応じて手動で実行することも可能です：

```bash
# すべてのカバー画像サムネイルを再生成
docker exec shin-on_wiki_app_1 php artisan bookstack:regenerate-thumbnails
```

### 再生成される対象

- **本棚（Bookshelf）のカバー画像**
- **本（Book）のカバー画像**

各画像について3種類のサイズが生成されます：
- 150x150 (グリッド表示用)
- 250x250 (プレビュー用)
- 440x250 (ヘッダー用)

### 実行結果の例

```
🔄 Starting thumbnail regeneration...

✅ Thumbnail regeneration completed successfully!

📊 Summary:
  📚 Bookshelves: 2 regenerated
  📖 Books: 3 regenerated
```

---

## トラブルシューティング

### カバー画像が表示されない

**症状**: 本棚や本のカバー画像が表示されない

**原因**: Dropbox復元後、サムネイルが再生成されていない

**解決方法**:
```bash
# サムネイルを手動で再生成
docker exec shin-on_wiki_app_1 php artisan bookstack:regenerate-thumbnails
```

### 復元に失敗する

**確認事項**:
1. Dropbox認証が有効か確認
2. バックアップファイルが破損していないか確認
3. ディスク容量が十分にあるか確認

**ログを確認**:
```bash
docker exec shin-on_wiki_app_1 tail -f storage/logs/laravel.log
```

### バックアップが大きすぎる

**対策**: サムネイルはバックアップに含めない設計になっています。

バックアップサイズを確認：
```bash
# Dropboxのバックアップサイズを確認
docker exec shin-on_wiki_app_1 php artisan backup:dropbox:list
```

---

## 自動バックアップ

### スケジュール設定

`app/Console/Kernel.php` に以下が設定済み:

```php
protected function schedule(Schedule $schedule)
{
    // 毎日深夜2時に自動バックアップ
    $schedule->command('backup:dropbox')->dailyAt('02:00');
}
```

### Cronの設定

Laravelのスケジューラを動作させるため、サーバーのcronに以下を追加:

```bash
# Docker環境の場合
* * * * * docker exec shin-on_wiki_app_1 php artisan schedule:run >> /dev/null 2>&1
```

### スケジュールのカスタマイズ

`app/Console/Kernel.php` を編集して頻度を変更:

```php
// 毎時実行
$schedule->command('backup:dropbox')->hourly();

// 毎週日曜日の深夜2時
$schedule->command('backup:dropbox')->weekly()->sundays()->at('02:00');

// 毎月1日の深夜2時
$schedule->command('backup:dropbox')->monthlyOn(1, '02:00');
```

---

## API エンドポイント

### バックアップ関連

| メソッド | エンドポイント | 説明 |
|----------|----------------|------|
| POST | `/api/backup/run` | バックアップ実行 |
| GET | `/api/backup/list` | バックアップ一覧取得 |
| POST | `/api/backup/test` | Dropbox接続テスト |
| GET | `/api/backup/restorable` | 復元可能なバックアップ取得 |

### Dropbox認証関連

| メソッド | エンドポイント | 説明 |
|----------|----------------|------|
| GET | `/auth/dropbox/redirect` | OAuth認証開始 |
| GET | `/auth/dropbox/callback` | OAuth認証コールバック |
| GET | `/api/dropbox/auth-status` | 認証状態取得 |
| POST | `/api/dropbox/refresh-token` | トークン更新 |
| POST | `/api/dropbox/revoke-auth` | 認証解除 |

### 復元関連

| メソッド | エンドポイント | 説明 |
|----------|----------------|------|
| POST | `/api/backup/download` | バックアップダウンロード |
| POST | `/api/backup/restore` | データベース復元 |
| POST | `/api/backup/validate-restore` | 復元前検証 |

---

## 実装詳細

### ディレクトリ構造

```
app/
├── Config/
│   └── backup.php                    # バックアップ設定
├── Console/
│   └── Commands/
│       ├── BackupDropboxCommand.php  # バックアップCLIコマンド
│       └── RegenerateThumbnailsCommand.php  # サムネイル再生成コマンド
├── Http/
│   └── Controllers/
│       ├── BackupController.php      # バックアップAPI
│       └── DropboxAuthController.php # OAuth認証
├── Models/
│   └── DropboxToken.php              # トークン管理モデル
└── Services/
    ├── BackupService.php             # バックアップ・復元ロジック
    └── DropboxService.php            # Dropbox API連携

database/
└── migrations/
    └── xxxx_create_dropbox_tokens_table.php  # トークン管理テーブル

resources/
└── views/
    └── settings/
        └── categories/
            └── features.blade.php    # バックアップUI
```

### 関連ファイル

| ファイル | 説明 |
|---------|------|
| `app/Services/BackupService.php` | バックアップ・復元のメインロジック、サムネイル再生成 |
| `app/Services/DropboxService.php` | Dropbox OAuth 2.0認証、ファイルアップロード/ダウンロード |
| `app/Console/Commands/BackupDropboxCommand.php` | バックアップCLIコマンド |
| `app/Console/Commands/RegenerateThumbnailsCommand.php` | サムネイル再生成CLIコマンド |
| `app/Console/Commands/RestoreDropboxCommand.php` | 復元CLIコマンド（推定） |
| `app/Http/Controllers/BackupController.php` | バックアップWeb API |
| `app/Http/Controllers/DropboxAuthController.php` | Dropbox OAuth認証フロー |
| `app/Models/DropboxToken.php` | アクセストークン管理 |
| `app/Config/backup.php` | バックアップ設定ファイル |
| `resources/views/settings/categories/features.blade.php` | Web UI |

### BackupServiceの主要メソッド

```php
// フルバックアップ作成（データベース + ファイル）
public function createFullBackup(bool $uploadToDropbox = true): array

// データベースバックアップ作成（mysqldump使用）
private function createDatabaseBackup(string $timestamp): string

// ファイルバックアップ作成（ZIP圧縮）
private function createFilesBackup(string $timestamp): string

// Dropboxからバックアップをダウンロード
public function downloadBackupFromDropbox(string $timestamp): array

// データベース復元（復元前バックアップオプション付き）
public function restoreDatabase(string $sqlFilePath, bool $createBackupFirst = true): array

// ファイル復元（サムネイル再生成を含む）
public function restoreFiles(string $zipFilePath): array

// カバー画像サムネイル再生成（本棚・本）
public function regenerateCoverThumbnails(): array
```

### DropboxServiceの主要メソッド

```php
// Dropbox認証状態チェック
public function isAuthenticated(): bool

// ファイルをDropboxにアップロード
public function uploadFile(string $filePath, string $remotePath): bool

// Dropboxからファイルをダウンロード
public function downloadFile(string $remotePath, string $localPath): bool

// アクセストークン自動リフレッシュ
private function refreshAccessToken(): string

// バックアップパス生成（年/月/日/タイムスタンプ）
public function generateBackupPath(string $timestamp, string $fileName): string
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

### 技術スタック

- **Framework**: Laravel 11.x
- **PHP**: 8.2+
- **Database**: MySQL 8.x / MariaDB 11.x
- **Storage**: Dropbox API v2
- **Package**: `spatie/flysystem-dropbox` ^3.0

---

## 更新履歴

### 2025-11-16
- 🆕 復元後の自動サムネイル再生成機能を追加
- 🆕 手動サムネイル再生成コマンドを追加 (`bookstack:regenerate-thumbnails`)
- 📝 ドキュメント作成

---

## 関連ドキュメント

- [プロジェクトREADME](../README.md)
- [claudeディレクトリREADME](../../README.md)

---

**最終更新**: 2025年11月16日
**作成者**: Claude Code + satoshi
