# Dropboxバックアップ - クイックスタート

BookStackのDropboxバックアップ機能を5分でセットアップ！

## 前提条件

- BookStack が動作していること
- Dropboxアカウントを持っていること
- 管理者権限でログインできること

## ステップ1: Dropboxアプリの作成 (5分)

1. **[Dropbox App Console](https://www.dropbox.com/developers/apps)** にアクセス

2. **「Create app」をクリック**

3. **アプリ設定**:
   - API: `Scoped access`
   - Access: `Full Dropbox`
   - Name: `shin-on_wiki-backup` (任意)

4. **「Create app」をクリック**

## ステップ2: 権限とリダイレクトURI設定 (3分)

### Permissions タブ

以下のスコープにチェック:
- ✅ `files.content.write`
- ✅ `files.content.read`
- ✅ `account_info.read`

**「Submit」をクリック**

### Settings タブ

1. **App key** と **App secret** をコピー

2. **Redirect URIs** に追加:
   ```
   http://localhost:8083/auth/dropbox/callback
   ```
   **「Add」をクリック**

## ステップ3: 環境変数の設定 (2分)

`.env` ファイルに以下を追加:

```bash
# Dropbox OAuth 2.0 Configuration
DROPBOX_CLIENT_ID=コピーしたApp_Key
DROPBOX_CLIENT_SECRET=コピーしたApp_Secret
DROPBOX_REDIRECT_URI="${APP_URL}/auth/dropbox/callback"

# Dropbox Backup Settings
DROPBOX_BACKUP_FOLDER=/shin-on_wiki-backup
DROPBOX_ACCESS_TOKEN_LIFETIME=14400

# Backup Configuration
BACKUP_TIMEZONE=Asia/Tokyo
BACKUP_RETENTION_DAYS=30
```

## ステップ4: データベースのセットアップ (1分)

```bash
# マイグレーション実行
php artisan migrate

# または Docker環境
docker exec shin-on_wiki_app_1 php artisan migrate
```

## ステップ5: mysqldump のインストール (Docker環境のみ)

```bash
docker exec shin-on_wiki_app_1 apt-get update
docker exec shin-on_wiki_app_1 apt-get install -y default-mysql-client
```

**💡 永続化するには**: `Dockerfile` に追加推奨
```dockerfile
RUN apt-get update && apt-get install -y default-mysql-client
```

## ステップ6: 動作確認 (3分)

### 1. Dropbox認証

1. ブラウザで `/settings/features` にアクセス
2. 「Dropboxバックアップ」セクションまでスクロール
3. **「Dropboxと連携」** ボタンをクリック
4. Dropboxの認証画面で **「許可」** をクリック
5. 自動的に設定画面に戻ります

✅ **認証成功**: アカウント名が表示されます

### 2. 接続テスト

**「接続テスト」** ボタンをクリック

✅ **成功メッセージ**: "接続テスト成功"

### 3. バックアップ実行

1. **「Dropboxにアップロード」** にチェック
2. **「バックアップ実行」** ボタンをクリック
3. 進捗を確認

✅ **完了メッセージ**: "バックアップが正常に完了しました"

### 4. Dropboxで確認

Dropboxを開いて以下のフォルダを確認:
```
/shin-on_wiki-backup/2025/11/15/2025-11-15_XX-XX-XX/
```

✅ **2つのファイル**:
- `database_backup_*.sql` (データベース)
- `files_backup_*.zip` (ファイル)

## 完了！🎉

バックアップ機能が使用可能になりました！

## 次のステップ

### 自動バックアップの設定

サーバーのcronに追加:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Docker環境:
```bash
* * * * * docker exec shin-on_wiki_app_1 php artisan schedule:run >> /dev/null 2>&1
```

これで **毎日深夜2時** に自動バックアップが実行されます。

### コマンドラインからの使用

```bash
# 手動バックアップ
php artisan backup:dropbox

# 接続テストのみ
php artisan backup:dropbox --test
```

## トラブルシューティング

### ❌ "Invalid redirect_uri"

**解決**: Dropbox App Console の Redirect URIs を再確認
```
http://localhost:8083/auth/dropbox/callback
```
最後にスラッシュがないこと、HTTPSではなくHTTPであることを確認

### ❌ "mysqldump: not found"

**解決**: mysqldump を再インストール
```bash
docker exec shin-on_wiki_app_1 apt-get install -y default-mysql-client
```

### ❌ "Cannot read properties of null"

**解決**: ページをハードリロード (Ctrl+Shift+R / Cmd+Shift+R)

### ❌ "SSL certificate error"

**解決**: すでに対応済み (`--skip-ssl` オプション使用)

### ❌ "path/malformed_path"

**解決**: すでに対応済み (パス生成修正済み)

## さらに詳しく

完全なドキュメント: [DROPBOX_BACKUP.md](./DROPBOX_BACKUP.md)

- 復元機能の使い方
- スケジュールのカスタマイズ
- セキュリティ考慮事項
- API仕様

## サポート

問題が発生した場合:
1. `storage/logs/laravel.log` を確認
2. Dropbox App Console の設定を確認
3. `.env` ファイルの設定を確認

---

**所要時間**: 約15分で完全セットアップ完了！
