# 自宅サーバーデプロイガイド

shin-on_wikiを自宅サーバーでDocker環境として公開し、GitHub Actionsで自動デプロイする手順。

## 構成概要

```
インターネット → ルーター(80,443,56834) → 自宅サーバー
                                           ├─ Apache (SSL終端, リバースプロキシ)
                                           └─ Docker
                                               ├─ App:8083
                                               └─ MySQL:3308
```

**デプロイフロー**: `git push origin release` → GitHub Actions → SSH経由で本番サーバー更新

---

## 1. サーバー環境構築

### 必要パッケージ

```bash
# Docker
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER

# Apache
sudo apt install -y apache2
sudo a2enmod proxy proxy_http headers ssl rewrite

# その他
sudo apt install -y certbot python3-certbot-apache fail2ban ufw git
```

### セキュリティ設定

```bash
# ファイアウォール
sudo ufw default deny incoming
sudo ufw allow 56834/tcp  # SSH（カスタムポート）
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# SSH設定（/etc/ssh/sshd_config）
# Port 56834
# PasswordAuthentication no
# PubkeyAuthentication yes
# PermitRootLogin no
```

---

## 2. ネットワーク設定

### MyDNS.JP 自動更新

```bash
# /usr/local/bin/mydns-update.sh
#!/bin/bash
MYDNS_ID="your-id"
MYDNS_PASSWORD="your-password"
curl -4 -u "${MYDNS_ID}:${MYDNS_PASSWORD}" https://www.mydns.jp/login.html

# crontab -e で追加
*/10 * * * * /usr/local/bin/mydns-update.sh
```

### ルーター ポートフォワーディング

| 外部ポート | 内部ポート | 説明 |
|-----------|-----------|------|
| 80 | 80 | HTTP |
| 443 | 443 | HTTPS |
| 56834 | 56834 | SSH |

---

## 3. GitHub設定

### デプロイキー作成（サーバー側）

```bash
ssh-keygen -t ed25519 -C "deploy@shin-on-wiki" -f ~/.ssh/id_ed25519_deploy -N ""
cat ~/.ssh/id_ed25519_deploy.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

### GitHub Secrets設定

| Secret名 | 値 |
|---------|---|
| `DEPLOY_HOST` | shin-on-wiki.mydns.jp |
| `DEPLOY_USER` | ユーザー名 |
| `DEPLOY_KEY` | `~/.ssh/id_ed25519_deploy` の内容 |
| `DEPLOY_PATH` | /var/www/shin-on_wiki |

---

## 4. 初回デプロイ

```bash
# プロジェクト配置
sudo mkdir -p /var/www && sudo chown $USER:$USER /var/www
cd /var/www
GIT_SSH_COMMAND='ssh -i ~/.ssh/id_ed25519_deploy' git clone git@github.com:satoshi-tateishi/shin-on_wiki.git
cd shin-on_wiki && git checkout release

# .env設定
cp .env.production.example .env
nano .env  # APP_URL, DB_PASSWORD, APP_PROXIES=* 等を設定
chmod 644 .env  # Webサーバーから読み取り可能にする

# 依存関係（ホスト側）
composer install --no-dev --optimize-autoloader
npm ci && npm run production && npm prune --omit=dev

# Docker起動
docker compose -f docker-compose.production.yml up -d

# 初期設定
php artisan key:generate
php artisan storage:link
docker compose -f docker-compose.production.yml exec app php artisan migrate --force

# パーミッション
sudo chown -R 33:33 storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# キャッシュ最適化
docker compose -f docker-compose.production.yml exec app php artisan config:cache
docker compose -f docker-compose.production.yml exec app php artisan route:cache
docker compose -f docker-compose.production.yml exec app php artisan view:cache
```

---

## 5. Apache & SSL設定

```bash
# VirtualHost設定
sudo cp apache-vhost.conf.example /etc/apache2/sites-available/shin-on_wiki.conf
sudo a2ensite shin-on_wiki.conf
sudo systemctl reload apache2

# SSL証明書取得
sudo certbot --apache -d shin-on-wiki.mydns.jp
```

---

## 6. バックアップと復元

### 重要：復元後のキャッシュ再生成

**復元処理では`config:clear`を呼ばない設計になっている。**

理由：
- `config:clear`を呼ぶと`config.php`が削除される
- 次のリクエストで`.env`から`DB_HOST`を読み込もうとするが、Webコンテキストでは環境変数が正しく解決されない
- `DB_HOST=localhost`になり、Docker内の`db`コンテナに接続できずエラーになる

### 復元フロー

1. 設定画面からDropbox復元を実行
2. 復元成功後、「キャッシュ再生成」ボタンが表示される
3. ボタンをクリックしてキャッシュを再生成
4. CSSが正常に反映される

### キャッシュ再生成の内部動作

`BackupController::regenerateCache()`では以下を実行：

```php
// config:clear は呼ばない（重要）
\Artisan::call('cache:clear');

// フルパスでartisanを指定（Webコンテキストでは必須）
$phpBinary = PHP_BINARY;
$artisanPath = base_path() . '/artisan';
exec("{$phpBinary} {$artisanPath} config:cache 2>&1", ...);
exec("{$phpBinary} {$artisanPath} route:cache 2>&1", ...);
exec("{$phpBinary} {$artisanPath} view:cache 2>&1", ...);
```

**技術的注意点**：
- Webリクエストから`exec()`を呼ぶ場合、`artisan`のフルパスが必要
- `cd /path && php artisan`形式は`artisan: not found`エラーになる
- `PHP_BINARY`で実行中のPHPバイナリパスを取得

### .envパーミッション

`.env`は`644`にする（Webサーバーから読み取り可能）：
```bash
chmod 644 .env
```

`600`だとWebサーバーが読み取れず、DB接続エラーになる。

---

## 7. トラブルシューティング

### DB接続エラー（復元後）

```
SQLSTATE[HY000] [2002] No such file or directory
```

**原因**: `config:clear`後に`DB_HOST`がlocalhostになる

**解決**:
1. 復元処理で`config:clear`を呼ばない（実装済み）
2. 復元後に「キャッシュ再生成」ボタンで`config:cache`のみ実行

### CSS反映されない（復元後）

**原因**: `APP_PROXIES`設定がキャッシュされていない

**解決**: キャッシュ再生成ボタンをクリック

### artisan: not found（exec()）

**原因**: Webコンテキストでのパス解決問題

**解決**: フルパスを使用
```php
// NG
exec("cd {$appPath} && php artisan config:cache");

// OK
$artisanPath = $appPath . '/artisan';
exec("{$phpBinary} {$artisanPath} config:cache");
```

### GitHub Actions SSH接続失敗

確認事項：
1. ルーターのポートフォワーディングがON
2. `authorized_keys`にデプロイ公開鍵が登録済み
3. MyDNS更新スクリプトに`-4`フラグ（IPv4強制）

---

## 8. 運用コマンド

```bash
# ログ確認
docker compose -f docker-compose.production.yml logs -f app

# 手動バックアップ
docker compose -f docker-compose.production.yml exec app php artisan backup:dropbox

# キャッシュクリア＆再生成
docker compose -f docker-compose.production.yml exec app php artisan cache:clear
docker compose -f docker-compose.production.yml exec app php artisan config:cache
docker compose -f docker-compose.production.yml exec app php artisan route:cache
docker compose -f docker-compose.production.yml exec app php artisan view:cache

# コンテナ再起動
docker compose -f docker-compose.production.yml restart app
```

---

## 参考ドキュメント

- [BACKUP_RESTORE.md](./BACKUP_RESTORE.md) - バックアップ/リストア詳細
- [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md) - LINE WORKS SSO設定
- [scripts/README.md](../../scripts/README.md) - 自動化スクリプト
