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

### MyDNS.JP サブドメイン設定

複数のアプリケーションを同一サーバーでホストする場合、サブドメインを使用。

**構成**:
| ドメイン | アプリ | ポート | 備考 |
|-------------|--------|--------|------|
| `wiki.shin-on1981.com` | shin-on_wiki | 8083 | 独自ドメイン（メイン） |

> **Note**: shin-on_wikiは `wiki.shin-on1981.com` のみでアクセス可能。詳細は[CUSTOM_DOMAIN_SETUP.md](./CUSTOM_DOMAIN_SETUP.md)参照。

**DNS伝播確認**:
```bash
dig wiki.shin-on1981.com
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
| `DEPLOY_HOST` | wiki.shin-on1981.com |
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

### 単一ドメインの場合

```bash
# VirtualHost設定
sudo cp apache-vhost.conf.example /etc/apache2/sites-available/shin-on_wiki.conf
sudo a2ensite shin-on_wiki.conf
sudo systemctl reload apache2

# SSL証明書取得
sudo certbot --apache -d wiki.shin-on1981.com
```

### 現在のApache設定（wiki.shin-on1981.com専用）

shin-on_wiki は独自ドメイン `wiki.shin-on1981.com` のみでアクセス可能です。

**有効な設定ファイル**:
- `/etc/apache2/sites-enabled/shin-on_wiki-le-ssl.conf` - HTTPS (ポート443)
- `/etc/apache2/sites-enabled/wiki-shin-on1981-redirect.conf` - HTTP→HTTPSリダイレクト

**HTTPSサイト設定** (`shin-on_wiki-le-ssl.conf`):

```apache
<IfModule mod_ssl.c>
    <VirtualHost *:443>
        ServerName wiki.shin-on1981.com

        SSLEngine on
        SSLCertificateFile /etc/letsencrypt/live/wiki.shin-on1981.com/fullchain.pem
        SSLCertificateKeyFile /etc/letsencrypt/live/wiki.shin-on1981.com/privkey.pem
        Include /etc/letsencrypt/options-ssl-apache.conf

        ProxyPreserveHost On
        ProxyPass / http://localhost:8083/
        ProxyPassReverse / http://localhost:8083/

        RequestHeader set X-Forwarded-Proto "https"
        RequestHeader set X-Forwarded-Port "443"

        Header always set X-Frame-Options "SAMEORIGIN"
        Header always set X-Content-Type-Options "nosniff"
        Header always set X-XSS-Protection "1; mode=block"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
        Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        Header unset X-Powered-By
        Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"

        ErrorLog ${APACHE_LOG_DIR}/shin-on_wiki-error.log
        CustomLog ${APACHE_LOG_DIR}/shin-on_wiki-access.log combined
    </VirtualHost>
</IfModule>
```

**HTTPリダイレクト設定** (`wiki-shin-on1981-redirect.conf`):

```apache
<VirtualHost *:80>
    ServerName wiki.shin-on1981.com

    RewriteEngine on
    RewriteCond %{SERVER_NAME} =wiki.shin-on1981.com
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>
```

**設定確認**:

```bash
# VirtualHost一覧
apachectl -S

# 設定テスト
sudo apachectl configtest

# Apache再読み込み
sudo systemctl reload apache2
```

**SSL証明書取得**:

```bash
# DNS伝播後に実行
sudo certbot --apache -d wiki.shin-on1981.com
```

**.env設定**:

```bash
APP_URL=https://wiki.shin-on1981.com
```

**OAuth Redirect URI**:

LINE WORKS / Dropbox などのOAuth設定でRedirect URIを設定。

- LINE WORKS: `https://wiki.shin-on1981.com/lineworks/callback`
- Dropbox: `https://wiki.shin-on1981.com/auth/dropbox/callback`

詳細は [CUSTOM_DOMAIN_SETUP.md](./CUSTOM_DOMAIN_SETUP.md) を参照

---

## 6. LINE WORKS OTP 二段階認証設定

LINE WORKS OIDC ログイン後にOTP（ワンタイムパスワード）検証を行う二段階認証機能。

### 6.1 LINE WORKS Developer Console での設定

1. [LINE WORKS Developer Console](https://developers.worksmobile.com/) にアクセス
2. **Bot** を作成し、Bot ID を取得
3. **Service Account** を作成し、秘密鍵をダウンロード
4. **Developer Console App** を作成し、Client ID/Secret を取得

### 6.2 .env に Bot API 設定を追加

```bash
nano .env
```

以下を追加：

```env
# LINE WORKS Bot API Settings (Two-Factor Authentication)
LINEWORKS_API_BASE_URL=https://www.worksapis.com/v1.0
LINEWORKS_AUTH_URL=https://auth.worksmobile.com/oauth2/v2.0/token
LINEWORKS_BOT_ID=your_bot_id
LINEWORKS_BOT_SECRET=your_bot_secret
LINEWORKS_DB_CLIENT_ID=your_developer_console_client_id
LINEWORKS_DB_CLIENT_SECRET=your_developer_console_client_secret
LINEWORKS_SERVICE_ACCOUNT=xxxxx.serviceaccount@your-domain
LINEWORKS_PRIVATE_KEY_PATH=lineworks/private_key.pem
```

### 6.3 秘密鍵の配置

```bash
# ディレクトリ作成
sudo mkdir -p storage/app/lineworks

# 秘密鍵ファイルを作成（Developer Consoleからダウンロードした内容を貼り付け）
sudo nano storage/app/lineworks/private_key.pem

# パーミッション設定
sudo chown www-data:www-data storage/app/lineworks
sudo chown www-data:www-data storage/app/lineworks/private_key.pem
sudo chmod 600 storage/app/lineworks/private_key.pem
```

### 6.4 キャッシュ再生成

```bash
docker compose -f docker-compose.production.yml exec app php artisan config:cache
```

### 6.5 動作確認

1. LINE WORKS でログイン
2. OTP入力画面が表示される
3. LINE WORKS にOTPメッセージが届く
4. OTPを入力してログイン完了

詳細は [LINEWORKS_OTP_2FA.md](./LINEWORKS_OTP_2FA.md) を参照。

---

## 7. バックアップと復元

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

## 8. トラブルシューティング

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

## 9. 運用コマンド

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
- [LINEWORKS_OTP_2FA.md](./LINEWORKS_OTP_2FA.md) - LINE WORKS OTP二段階認証
- [scripts/README.md](../../scripts/README.md) - 自動化スクリプト
