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

**✅ このプロジェクトでは既に対応済みです。**

`dev/docker/Dockerfile` に `default-mysql-client` が含まれているため、Dockerイメージのビルド時に自動的に `mysqldump` がインストールされます。

確認方法:
```bash
docker exec shin-on_wiki_app_1 which mysqldump
# /usr/bin/mysqldump

docker exec shin-on_wiki_app_1 mysqldump --version
# mysqldump from 11.8.3-MariaDB, client 10.19 for debian-linux-gnu (aarch64)
```

**参考: 手動でインストールする場合**

別のDockerプロジェクトで `mysqldump` がインストールされていない場合は、以下のように対応できます：

一時的にインストール（コンテナ再作成で消える）:
```bash
docker exec shin-on_wiki_app_1 apt-get update
docker exec shin-on_wiki_app_1 apt-get install -y default-mysql-client
```

永続化するには `Dockerfile` に追加:
```dockerfile
RUN apt-get update && \
    apt-get install -y default-mysql-client && \
    rm -rf /var/lib/apt/lists/*
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

---

## 本番環境での運用

### 本番環境セットアップ

本番環境（Ubuntu on-premises）では、Docker を使用しないため、コマンドの実行方法が異なります。

#### 1. mysqldump の確認

```bash
# mysqldump が利用可能か確認
which mysqldump
# /usr/bin/mysqldump

# インストールされていない場合
sudo apt install mysql-client
```

#### 2. 環境変数の設定

本番環境の `.env` ファイルに以下を設定:

```bash
# 本番環境のドメイン
APP_URL=https://your-domain.com

# Dropbox設定
DROPBOX_CLIENT_ID=your_production_app_key
DROPBOX_CLIENT_SECRET=your_production_app_secret
DROPBOX_REDIRECT_URI="${APP_URL}/auth/dropbox/callback"
DROPBOX_BACKUP_FOLDER=/shin-on_wiki-backup
DROPBOX_ACCESS_TOKEN_LIFETIME=14400

# バックアップ設定
BACKUP_TIMEZONE=Asia/Tokyo
BACKUP_RETENTION_DAYS=30
```

#### 3. Dropbox OAuth リダイレクト URI の更新

[Dropbox App Console](https://www.dropbox.com/developers/apps) で、本番環境のURLを追加:

```
https://your-domain.com/auth/dropbox/callback
```

#### 4. Web UI からの認証

1. 本番環境にログイン（管理者）
2. 設定 > 機能 に移動
3. 「Dropboxと連携」ボタンをクリック
4. Dropbox認証を完了

### 自動バックアップの設定（Cron）

#### cronジョブの作成

```bash
# www-dataユーザーのcrontabを編集
sudo crontab -e -u www-data

# 以下の行を追加（毎日午前2時にバックアップ）
0 2 * * * cd /var/www/shin-on_wiki && php artisan backup:dropbox >> /dev/null 2>&1
```

#### バックアップスケジュール例

| 時刻 | 頻度 | 用途 |
|---|---|---|
| 02:00 | 毎日 | 定期バックアップ |
| 14:00 | 毎週日曜 | 週次バックアップ |
| 03:00 | 毎月1日 | 月次バックアップ |

**複数スケジュール設定例:**
```cron
# 毎日午前2時: 通常バックアップ
0 2 * * * cd /var/www/shin-on_wiki && php artisan backup:dropbox

# 毎週日曜午後2時: 週次バックアップ
0 14 * * 0 cd /var/www/shin-on_wiki && php artisan backup:dropbox

# 毎月1日午前3時: 月次バックアップ
0 3 1 * * cd /var/www/shin-on_wiki && php artisan backup:dropbox
```

#### cron ログの確認

```bash
# cronログを確認
sudo tail -f /var/log/syslog | grep CRON

# アプリケーションログで成功/失敗を確認
sudo tail -f /var/www/shin-on_wiki/storage/logs/laravel.log
```

### コマンド実行方法（本番環境）

本番環境では Docker を使用しないため、直接 `php artisan` を実行します。

#### 開発環境 vs 本番環境

| 操作 | 開発環境 (Docker) | 本番環境 (Ubuntu) |
|---|---|---|
| **バックアップ実行** | `docker exec shin-on_wiki_app_1 php artisan backup:dropbox` | `php artisan backup:dropbox` |
| **バックアップテスト** | `docker exec shin-on_wiki_app_1 php artisan backup:dropbox --test` | `php artisan backup:dropbox --test` |
| **サムネイル再生成** | `docker exec shin-on_wiki_app_1 php artisan bookstack:regenerate-thumbnails` | `php artisan bookstack:regenerate-thumbnails` |

#### 本番環境での実行例

```bash
# アプリケーションディレクトリに移動
cd /var/www/shin-on_wiki

# バックアップ実行（www-dataユーザーで）
sudo -u www-data php artisan backup:dropbox

# バックアップテスト
sudo -u www-data php artisan backup:dropbox --test

# サムネイル再生成
sudo -u www-data php artisan bookstack:regenerate-thumbnails
```

### ファイルパーミッションの確認

バックアップが正常に動作するために、適切なパーミッションを設定:

```bash
# storage ディレクトリ
sudo chown -R www-data:www-data /var/www/shin-on_wiki/storage
sudo chmod -R 775 /var/www/shin-on_wiki/storage

# backups ディレクトリ（自動作成される）
sudo chown -R www-data:www-data /var/www/shin-on_wiki/storage/app/backups
sudo chmod -R 775 /var/www/shin-on_wiki/storage/app/backups

# public/uploads ディレクトリ
sudo chown -R www-data:www-data /var/www/shin-on_wiki/public/uploads
sudo chmod -R 775 /var/www/shin-on_wiki/public/uploads
```

### ディスク容量の監視

バックアップファイルはローカルに一時保存されるため、ディスク容量を監視:

```bash
# 現在のディスク使用量
df -h /var/www/shin-on_wiki

# バックアップディレクトリのサイズ
du -sh /var/www/shin-on_wiki/storage/app/backups

# アップロードファイルのサイズ
du -sh /var/www/shin-on_wiki/public/uploads

# データベースサイズ
mysql -u bookstack -p -e "SELECT table_schema AS 'Database', ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)' FROM information_schema.tables WHERE table_schema = 'shin_on_wiki' GROUP BY table_schema;"
```

#### ディスク容量アラートの設定（推奨）

```bash
# ディスク使用量が80%を超えたらメール送信
sudo crontab -e

# 以下を追加
0 8 * * * df -h / | awk 'NR==2 {if ($5+0 > 80) print "Disk usage: " $5}' | mail -s "Disk Alert" admin@your-domain.com
```

### バックアップの検証

定期的にバックアップが正常に動作しているか確認:

```bash
# 最新のバックアップファイルを確認
ls -lht /var/www/shin-on_wiki/storage/app/backups | head -5

# バックアップログを確認
sudo grep -i "backup" /var/www/shin-on_wiki/storage/logs/laravel.log | tail -20

# Dropbox にアップロードされたか確認（Web UI）
# https://www.dropbox.com/ にログインして /shin-on_wiki-backup を確認
```

### 復元テスト（推奨）

少なくとも月に1回、復元テストを実施:

1. **ステージング環境を用意**（本番環境とは別のサーバー）
2. **最新のバックアップを復元**
3. **動作確認**
   - ログインできるか
   - ページが表示されるか
   - 画像が表示されるか
   - 検索が動作するか

```bash
# ステージング環境での復元例
cd /var/www/shin-on_wiki-staging

# Dropboxから最新バックアップをダウンロード
# (Web UIで実行)

# 復元実行
sudo -u www-data php artisan backup:restore database /path/to/database.sql
sudo -u www-data php artisan backup:restore files /path/to/files.zip
```

### トラブルシューティング（本番環境）

#### 問題: バックアップが失敗する

**原因1: mysqldump が見つからない**
```bash
# 確認
which mysqldump

# インストール
sudo apt install mysql-client
```

**原因2: ディスク容量不足**
```bash
# 確認
df -h

# 古いバックアップを削除
sudo -u www-data php artisan backup:cleanup
```

**原因3: パーミッション不足**
```bash
# 修正
sudo chown -R www-data:www-data /var/www/shin-on_wiki/storage
sudo chmod -R 775 /var/www/shin-on_wiki/storage/app/backups
```

#### 問題: cron が実行されない

```bash
# cron サービスが動作しているか確認
sudo systemctl status cron

# cron ログを確認
sudo tail -f /var/log/syslog | grep CRON

# cronジョブが設定されているか確認
sudo crontab -l -u www-data
```

#### 問題: Dropbox アップロードが失敗

```bash
# トークンの有効期限を確認（ログから）
sudo grep "Dropbox" /var/www/shin-on_wiki/storage/logs/laravel.log | tail -20

# 再認証が必要な場合
# Web UI から「Dropboxと連携」を再実行
```

### セキュリティ考慮事項

1. **バックアップファイルの保護**
   - ローカルバックアップは一時的（Dropboxアップロード後に削除）
   - `.env` ファイルが含まれるため、パーミッション600で保護

2. **Dropbox アクセス制御**
   - Dropbox のアクセストークンはデータベースに暗号化保存
   - アクセストークンは14400秒（4時間）で自動リフレッシュ

3. **復元前のバックアップ**
   - 復元前に自動的に現在の状態をバックアップ
   - 誤操作からの保護

### パフォーマンス最適化

#### バックアップ時間の短縮

大規模なデータベースの場合、圧縮オプションを調整:

```bash
# config/backup.php で調整（将来の機能）
'compression' => 'gzip',  // 'none', 'gzip', 'bzip2'
```

#### Dropbox アップロード速度

```bash
# ネットワーク帯域幅を確認
speedtest-cli

# アップロード速度が遅い場合、複数ファイルに分割（将来の機能）
```

---

## 関連ドキュメント

- [DEPLOYMENT.md](./DEPLOYMENT.md) - デプロイメント手順
- [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md) - デプロイチェックリスト
- [SYSTEM_REQUIREMENTS.md](./SYSTEM_REQUIREMENTS.md) - システム要件
- [プロジェクトREADME](../README.md)
- [claudeディレクトリREADME](../../README.md)

---

**最終更新**: 2025年11月17日
**作成者**: Claude Code + satoshi
