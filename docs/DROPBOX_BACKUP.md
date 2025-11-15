# Dropboxバックアップ機能

BookStackアプリケーションのデータベースとファイルをDropboxに自動バックアップする機能です。

## 目次

- [機能概要](#機能概要)
- [セットアップ](#セットアップ)
- [使用方法](#使用方法)
- [自動バックアップ](#自動バックアップ)
- [復元機能](#復元機能)
- [トラブルシューティング](#トラブルシューティング)

## 機能概要

### 主な機能

1. **Dropbox OAuth認証**
   - OAuth 2.0による安全な認証
   - リフレッシュトークンによる自動更新
   - トークン有効期限管理

2. **自動バックアップ**
   - データベース全体のバックアップ (mysqldump)
   - アップロードファイルのバックアップ (ZIP圧縮)
   - Dropboxへの自動アップロード
   - 日付別フォルダ構造

3. **手動バックアップ**
   - Web UI からの実行
   - Artisanコマンドからの実行
   - Dropboxアップロードの有無を選択可能

4. **バックアップ履歴**
   - Dropbox上のバックアップ一覧表示
   - タイムスタンプ、サイズ情報の表示
   - 復元対象の選択

5. **データ復元** (実装済み)
   - バックアップからのデータベース復元
   - 復元前の自動バックアップ
   - 安全性確認機能

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
- `files.content.write`
- `files.content.read`
- `account_info.read`

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
php artisan migrate
```

これにより `dropbox_tokens` テーブルが作成されます。

### 5. 依存パッケージのインストール

```bash
composer install
```

### 6. mysqldump のインストール (Docker環境)

アプリケーションコンテナに `mysqldump` をインストール:

```bash
docker exec shin-on_wiki_app_1 apt-get update
docker exec shin-on_wiki_app_1 apt-get install -y default-mysql-client
```

**永続化するには** `Dockerfile` に追加:
```dockerfile
RUN apt-get update && apt-get install -y default-mysql-client
```

## 使用方法

### Web UIからの使用

1. **Dropbox認証**
   - `/settings/features` にアクセス
   - 「Dropboxバックアップ」セクションへ移動
   - 「Dropboxと連携」ボタンをクリック
   - Dropboxの認証画面で「許可」をクリック

2. **接続テスト**
   - 「接続テスト」ボタンで認証状態を確認
   - アカウント情報が表示されます

3. **手動バックアップ実行**
   - 「Dropboxにアップロード」チェックボックスで選択
   - 「バックアップ実行」ボタンをクリック
   - 進捗とログが表示されます

4. **バックアップ履歴**
   - 「履歴を更新」ボタンでDropbox上のバックアップ一覧を表示
   - タイムスタンプ、サイズ、ファイル数を確認

### Artisanコマンドからの使用

#### 基本的な使用

```bash
# 通常のバックアップ (Dropboxにアップロード)
php artisan backup:dropbox

# 接続テストのみ
php artisan backup:dropbox --test

# ローカルバックアップのみ (Dropboxにアップロードしない)
php artisan backup:dropbox --no-upload

# データベースのみバックアップ
php artisan backup:dropbox --db-only

# ファイルのみバックアップ
php artisan backup:dropbox --files-only
```

#### 出力例

```
🚀 Starting Dropbox backup process...
✅ Backup completed successfully!
📁 Timestamp: 2025-11-15_14-30-00

📊 Backup Results:
  ✅ Database backup
    📂 Local: database_backup_2025-11-15_14-30-00.sql
    📏 Size: 0.13 MB
    ☁️  Dropbox: ✅ Uploaded
    🔗 Remote: /shin-on_wiki-backup/2025/11/15/2025-11-15_14-30-00/database_backup_2025-11-15_14-30-00.sql

  ✅ Files backup
    📂 Local: files_backup_2025-11-15_14-30-00.zip
    📏 Size: 0.01 MB
    ☁️  Dropbox: ✅ Uploaded
    🔗 Remote: /shin-on_wiki-backup/2025/11/15/2025-11-15_14-30-00/files_backup_2025-11-15_14-30-00.zip
```

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
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Docker環境の場合:
```bash
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

## バックアップの構造

### Dropbox内のフォルダ構造

```
/shin-on_wiki-backup/
  └── 2025/
      └── 11/
          └── 15/
              └── 2025-11-15_14-30-00/
                  ├── database_backup_2025-11-15_14-30-00.sql
                  └── files_backup_2025-11-15_14-30-00.zip
```

### ローカルバックアップ

一時的に `storage/app/backups/` に保存されます:
- `database_backup_{timestamp}.sql`
- `files_backup_{timestamp}.zip`

## 復元機能

### Web UIからの復元

1. **バックアップ履歴の確認**
   - 「履歴を更新」ボタンをクリック
   - 復元したいバックアップを選択

2. **復元の実行**
   - 「復元」ボタンをクリック
   - 確認ダイアログで内容を確認
   - 「復元前に現在のデータベースをバックアップする」を推奨
   - 「実行」をクリック

3. **復元の確認**
   - 復元完了メッセージを確認
   - アプリケーションの動作を確認

### Artisanコマンドからの復元

```bash
# バックアップ一覧を表示
php artisan backup:dropbox:list

# 特定のバックアップをダウンロード
php artisan backup:dropbox:download 2025-11-15_14-30-00

# データベースを復元 (復元前バックアップあり)
php artisan backup:restore:database 2025-11-15_14-30-00

# データベースを復元 (復元前バックアップなし - 非推奨)
php artisan backup:restore:database 2025-11-15_14-30-00 --no-backup
```

## トラブルシューティング

### 1. 認証エラー: "Invalid redirect_uri"

**原因**: Dropbox App Console の Redirect URIs 設定が正しくない

**解決方法**:
1. Dropbox App Console にアクセス
2. Settings タブの Redirect URIs に正確なURLを追加:
   ```
   http://localhost:8083/auth/dropbox/callback
   ```
3. 保存後、再度認証を試行

### 2. バックアップエラー: "mysqldump: not found"

**原因**: mysqldump がインストールされていない

**解決方法**:
```bash
# Docker環境
docker exec shin-on_wiki_app_1 apt-get update
docker exec shin-on_wiki_app_1 apt-get install -y default-mysql-client

# ホスト環境
sudo apt-get install default-mysql-client
```

### 3. SSL証明書エラー

**原因**: 自己署名証明書の使用

**解決方法**: `BackupService.php` で `--skip-ssl` オプションが設定済み
```php
'mysqldump -h%s -P%s -u%s -p%s --skip-ssl %s > %s'
```

### 4. アップロードエラー: "path/malformed_path"

**原因**: パスにダブルスラッシュが含まれている

**解決方法**: `DropboxService.php` の `generateBackupPath` で修正済み
```php
$basePath = ltrim($basePath, '/');
```

### 5. トークン有効期限切れ

**症状**: "Access token expired" エラー

**解決方法**:
1. Web UI の「トークン更新」ボタンをクリック
2. または再度「Dropboxと連携」で認証

### 6. バックアップファイルが大きすぎる

**制限**: Dropbox API の制限は150MB

**解決方法**:
- `config/backup.php` の `max_file_size` を確認
- 古いデータをアーカイブして削除
- ファイルバックアップから不要なファイルを除外

## セキュリティ考慮事項

1. **環境変数の保護**
   - `.env` ファイルを `.gitignore` に追加済み
   - Client Secret は公開しない

2. **権限管理**
   - `Permission::SettingsManage` 権限が必要
   - 管理者のみアクセス可能

3. **トークンの保存**
   - データベースに暗号化せず保存
   - 本番環境では Laravel Encryption を推奨

4. **バックアップファイルの保護**
   - Dropbox上のファイルは認証済みユーザーのみアクセス可能
   - ローカルバックアップは定期的に削除

## 今後の改善案

- [ ] バックアップファイルの暗号化
- [ ] 複数のバックアップ先対応 (AWS S3, Google Drive等)
- [ ] 差分バックアップ機能
- [ ] バックアップの完全性チェック
- [ ] メール通知機能
- [ ] バックアップローテーション自動化

## 技術仕様

### 使用パッケージ

- `spatie/flysystem-dropbox`: ^3.0
- Laravel Framework: 11.x

### データベーステーブル

#### dropbox_tokens

| カラム | 型 | 説明 |
|--------|-----|------|
| id | bigint | 主キー |
| service_name | string | サービス名 (default: 'backup') |
| access_token | text | アクセストークン |
| access_token_expires_at | timestamp | トークン有効期限 |
| refresh_token | text | リフレッシュトークン |
| account_id | string | DropboxアカウントID |
| account_name | string | Dropboxアカウント名 |
| scope | text | 許可されたスコープ |
| is_active | boolean | アクティブ状態 |
| last_refreshed_at | timestamp | 最終更新日時 |
| created_at | timestamp | 作成日時 |
| updated_at | timestamp | 更新日時 |

### API エンドポイント

| メソッド | エンドポイント | 説明 |
|----------|----------------|------|
| GET | `/auth/dropbox/redirect` | OAuth認証開始 |
| GET | `/auth/dropbox/callback` | OAuth認証コールバック |
| GET | `/api/dropbox/auth-status` | 認証状態取得 |
| POST | `/api/dropbox/refresh-token` | トークン更新 |
| POST | `/api/dropbox/revoke-auth` | 認証解除 |
| POST | `/api/backup/run` | バックアップ実行 |
| GET | `/api/backup/list` | バックアップ一覧 |
| POST | `/api/backup/test` | 接続テスト |
| GET | `/api/backup/restorable` | 復元可能なバックアップ取得 |
| POST | `/api/backup/download` | バックアップダウンロード |
| POST | `/api/backup/restore` | データベース復元 |
| POST | `/api/backup/validate-restore` | 復元前検証 |

## サポート

問題が発生した場合:
1. ログを確認: `storage/logs/laravel.log`
2. Dropbox App Console の設定を確認
3. 環境変数 (`.env`) の設定を確認

## ライセンス

このプロジェクトのライセンスに従います。
