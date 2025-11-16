# Ubuntu On-Premises サーバーデプロイメントガイド

shin·on Wiki by BookStack をUbuntuサーバーにデプロイするための完全ガイドです。

## 📋 目次

1. [前提条件](#前提条件)
2. [サーバー準備](#サーバー準備)
3. [デプロイ手順](#デプロイ手順)
4. [Apache設定](#apache設定)
5. [SSL証明書](#ssl証明書)
6. [OAuth設定更新](#oauth設定更新)
7. [デプロイ後の確認](#デプロイ後の確認)
8. [トラブルシューティング](#トラブルシューティング)

---

## 前提条件

### サーバー要件

- **OS**: Ubuntu 20.04 LTS または 22.04 LTS
- **RAM**: 最低 2GB（推奨 4GB以上）
- **ストレージ**: 最低 10GB（データ量に応じて調整）
- **ネットワーク**: 固定IPアドレスまたはドメイン名
- **SSH**: ルートまたはsudo権限を持つユーザーアクセス

### 必須ソフトウェア

- PHP 8.3以上
- MySQL 8.0以上（推奨 8.4）
- Apache 2.4以上
- Composer 2.x
- Node.js 18以上（推奨 22.x）
- Git

### 事前準備

- [ ] ドメイン名の取得とDNS設定
- [ ] LINE WORKS Developer Consoleへのアクセス
- [ ] Dropbox App Consoleへのアクセス
- [ ] サーバーへのSSHアクセス確認
- [ ] Gitリポジトリへのアクセス権限

---

## サーバー準備

### 1. パッケージの更新

```bash
sudo apt update
sudo apt upgrade -y
```

### 2. 必要なパッケージのインストール

```bash
# Apache、MySQL、PHPのインストール
sudo apt install -y \
  apache2 \
  mysql-server \
  php8.3 php8.3-cli php8.3-fpm \
  php8.3-mysql php8.3-curl php8.3-gd \
  php8.3-mbstring php8.3-xml php8.3-zip \
  php8.3-ldap php8.3-dom php8.3-fileinfo \
  libapache2-mod-php8.3

# 開発ツールのインストール
sudo apt install -y \
  composer \
  git \
  nodejs npm \
  mysql-client \
  zip unzip \
  curl wget

# SSL証明書ツールのインストール
sudo apt install -y \
  certbot \
  python3-certbot-apache
```

### 3. Node.jsのバージョン確認・更新

```bash
# Node.js 22.xをインストール（推奨）
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs

# バージョン確認
node --version  # v22.x.x
npm --version   # 10.x.x
```

### 4. MySQL初期設定

```bash
# MySQLのセキュリティ設定
sudo mysql_secure_installation

# データベースとユーザーの作成
sudo mysql << EOF
CREATE DATABASE shin_on_wiki CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bookstack'@'localhost' IDENTIFIED BY 'YOUR_SECURE_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON shin_on_wiki.* TO 'bookstack'@'localhost';
FLUSH PRIVILEGES;
EOF
```

**重要**: `YOUR_SECURE_PASSWORD_HERE` を強力なパスワードに変更してください。

### 5. ファイアウォール設定

```bash
# UFWの設定
sudo ufw allow 22/tcp   # SSH
sudo ufw allow 80/tcp   # HTTP
sudo ufw allow 443/tcp  # HTTPS
sudo ufw enable
sudo ufw status
```

---

## デプロイ手順

### 1. アプリケーションディレクトリの作成

```bash
# Webルートディレクトリの作成
sudo mkdir -p /var/www/shin-on_wiki
sudo chown $USER:$USER /var/www/shin-on_wiki
```

### 2. Gitリポジトリのクローン

```bash
cd /var/www
git clone https://github.com/satoshi-tateishi/shin-on_wiki.git
cd shin-on_wiki
```

または、SSHキーを使用する場合：

```bash
git clone git@github.com:satoshi-tateishi/shin-on_wiki.git
```

### 3. Composer依存関係のインストール

```bash
# 本番環境用にインストール
composer install --no-dev --optimize-autoloader

# インストール中にエラーが出た場合
composer install --no-dev --optimize-autoloader --ignore-platform-reqs
```

### 4. NPM依存関係とアセットのビルド

```bash
# Node_modulesのインストール
npm install

# 本番環境用にビルド
npm run production
```

### 5. 環境設定ファイルの作成

```bash
# .env.production.exampleをコピー
cp .env.production.example .env

# または.env.exampleから作成
# cp .env.example .env

# 設定ファイルを編集
nano .env
```

**重要な設定項目**:

```env
# アプリケーション基本設定
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# データベース設定
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=shin_on_wiki
DB_USERNAME=bookstack
DB_PASSWORD=YOUR_SECURE_PASSWORD_HERE

# LINE WORKS SSO設定
AUTH_METHOD=oidc
OIDC_NAME="LINE WORKS"
OIDC_CLIENT_ID=YOUR_CLIENT_ID
OIDC_CLIENT_SECRET=YOUR_CLIENT_SECRET
OIDC_ISSUER=https://auth.worksmobile.com
OIDC_ISSUER_DISCOVER=false
OIDC_AUTH_ENDPOINT=https://auth.worksmobile.com/oauth2/v2.0/authorize
OIDC_TOKEN_ENDPOINT=https://auth.worksmobile.com/oauth2/v2.0/token
OIDC_ADDITIONAL_SCOPES=profile
LINEWORKS_DOMAIN=shin-on1981

# Dropbox Backup設定
DROPBOX_CLIENT_ID=YOUR_DROPBOX_CLIENT_ID
DROPBOX_CLIENT_SECRET=YOUR_DROPBOX_CLIENT_SECRET
DROPBOX_REDIRECT_URI="https://your-domain.com/auth/dropbox/callback"
DROPBOX_BACKUP_FOLDER=/shin-on_wiki-backup
BACKUP_TIMEZONE=Asia/Tokyo
BACKUP_RETENTION_DAYS=30

# メール設定（オプション）
MAIL_DRIVER=smtp
MAIL_FROM_NAME="shin·on Wiki by BookStack"
MAIL_FROM=noreply@your-domain.com
```

### 6. アプリケーションキーの生成

```bash
php artisan key:generate
```

### 7. データベースマイグレーションの実行

```bash
# マイグレーション実行
php artisan migrate --force

# 確認メッセージが表示されたら 'yes' と入力
```

### 8. ストレージリンクの作成

```bash
php artisan storage:link
```

### 9. ファイルパーミッションの設定

```bash
# 所有者をApacheユーザーに変更
sudo chown -R www-data:www-data /var/www/shin-on_wiki

# ディレクトリパーミッションの設定
sudo chmod -R 775 /var/www/shin-on_wiki/storage
sudo chmod -R 775 /var/www/shin-on_wiki/bootstrap/cache
sudo chmod -R 775 /var/www/shin-on_wiki/public/uploads

# .envファイルのパーミッション
sudo chmod 600 /var/www/shin-on_wiki/.env
```

### 10. キャッシュの最適化

```bash
# 設定キャッシュ
php artisan config:cache

# ルートキャッシュ
php artisan route:cache

# ビューキャッシュ
php artisan view:cache
```

---

## Apache設定

### 1. VirtualHost設定ファイルの作成

```bash
sudo nano /etc/apache2/sites-available/shin-on_wiki.conf
```

以下の内容を記述（`your-domain.com` を実際のドメインに置き換え）:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    ServerAdmin admin@your-domain.com
    DocumentRoot /var/www/shin-on_wiki/public

    <Directory /var/www/shin-on_wiki/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # ログファイル
    ErrorLog ${APACHE_LOG_DIR}/shin-on_wiki-error.log
    CustomLog ${APACHE_LOG_DIR}/shin-on_wiki-access.log combined

    # セキュリティヘッダー
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
</VirtualHost>
```

### 2. 必要なApacheモジュールの有効化

```bash
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod ssl
sudo a2enmod proxy
sudo a2enmod proxy_http
```

### 3. サイトの有効化

```bash
# デフォルトサイトを無効化
sudo a2dissite 000-default

# BookStackサイトを有効化
sudo a2ensite shin-on_wiki

# Apache設定のテスト
sudo apache2ctl configtest

# Apacheを再起動
sudo systemctl reload apache2
```

### 4. Apacheの自動起動設定

```bash
sudo systemctl enable apache2
```

---

## SSL証明書

**重要**: LINE WORKS SSOはHTTPSを必須とするため、SSL証明書の設定は必須です。

### Let's Encryptを使用したSSL証明書の取得

```bash
# Certbotを使用して自動設定
sudo certbot --apache -d your-domain.com

# プロンプトに従って入力:
# - メールアドレス
# - 利用規約への同意
# - HTTPSリダイレクトの設定（推奨: Yes）
```

### 証明書の自動更新設定

```bash
# 更新テスト
sudo certbot renew --dry-run

# 自動更新は自動的に設定されます（cron or systemd timer）
# 確認:
sudo systemctl status certbot.timer
```

### 証明書設定後のVirtualHost確認

Certbotによって自動的に作成された設定を確認:

```bash
sudo nano /etc/apache2/sites-available/shin-on_wiki-le-ssl.conf
```

以下のような内容が追加されているはずです:

```apache
<VirtualHost *:443>
    ServerName your-domain.com
    ServerAdmin admin@your-domain.com
    DocumentRoot /var/www/shin-on_wiki/public

    <Directory /var/www/shin-on_wiki/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # SSL設定
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/your-domain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/your-domain.com/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf

    # セキュリティヘッダー
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"

    # ログファイル
    ErrorLog ${APACHE_LOG_DIR}/shin-on_wiki-ssl-error.log
    CustomLog ${APACHE_LOG_DIR}/shin-on_wiki-ssl-access.log combined
</VirtualHost>
```

---

## OAuth設定更新

### LINE WORKS Developer Console

1. [LINE WORKS Developer Console](https://developers.worksmobile.com/jp/console/openapi/v2/app/list)にアクセス
2. 使用中のアプリケーションを選択
3. **OAuth 2.0 Redirect URI**に以下を追加:
   ```
   https://your-domain.com/oidc/callback
   ```
4. 開発環境のURLも残しておく:
   ```
   https://localhost:8443/oidc/callback
   ```
5. 変更を保存

### Dropbox App Console

1. [Dropbox App Console](https://www.dropbox.com/developers/apps)にアクセス
2. 使用中のアプリケーションを選択
3. **Settings** タブを開く
4. **OAuth 2 > Redirect URIs** セクションに以下を追加:
   ```
   https://your-domain.com/auth/dropbox/callback
   ```
5. 開発環境のURLも残しておく:
   ```
   https://localhost:8443/auth/dropbox/callback
   ```
6. **Add** をクリックして保存

---

## デプロイ後の確認

### 1. Webアクセステスト

ブラウザで以下のURLにアクセス:

```
https://your-domain.com
```

- ログインページが表示されることを確認
- SSL証明書が有効であることを確認（鍵マークが表示される）

### 2. LINE WORKS SSOログインテスト

1. ログインページで「LINE WORKSでログイン」をクリック
2. LINE WORKS認証画面にリダイレクトされることを確認
3. ドメイン `shin-on1981` のユーザーでログイン
4. BookStackにリダイレクトされ、ログインできることを確認

**確認ポイント**:
- 他のドメインのユーザーはログインできないこと
- ユーザー名が正しく表示されること
- ログイン後に管理画面にアクセスできること

### 3. Dropboxバックアップ認証

1. 管理者でログイン
2. 設定 > 機能 に移動
3. 「Dropboxと連携」ボタンをクリック
4. Dropbox認証画面にリダイレクトされることを確認
5. 認証を完了
6. BookStackに戻ることを確認

### 4. バックアップテスト

```bash
# Dockerコンテナではないので、直接実行
cd /var/www/shin-on_wiki
sudo -u www-data php artisan backup:dropbox --test
```

期待される出力:
```
Backup test started...
✓ mysqldump command found
✓ Database connection successful
✓ Dropbox token valid
✓ Dropbox folder accessible
✓ Test backup created successfully
Backup test completed successfully!
```

### 5. ファイルアップロードテスト

1. BookStackにログイン
2. 新しいページを作成
3. 画像をアップロード
4. 画像が正しく表示されることを確認

### 6. ログの確認

```bash
# アプリケーションログ
sudo tail -f /var/www/shin-on_wiki/storage/logs/laravel.log

# Apacheエラーログ
sudo tail -f /var/log/apache2/shin-on_wiki-ssl-error.log
```

エラーがないことを確認してください。

### 7. 自動バックアップの設定

```bash
# www-dataユーザーのcrontabを編集
sudo crontab -e -u www-data

# 以下の行を追加（毎日午前2時にバックアップ）
0 2 * * * cd /var/www/shin-on_wiki && php artisan backup:dropbox >> /dev/null 2>&1
```

保存して終了。

### 8. パフォーマンステスト

ブラウザの開発者ツールで:
- ページ読み込み時間を確認
- 画像の読み込みを確認
- JavaScriptエラーがないことを確認

---

## トラブルシューティング

### 問題: Apache 500エラー

**原因**: ファイルパーミッション不足

**解決策**:
```bash
sudo chown -R www-data:www-data /var/www/shin-on_wiki
sudo chmod -R 775 /var/www/shin-on_wiki/storage
sudo chmod -R 775 /var/www/shin-on_wiki/bootstrap/cache
```

### 問題: LINE WORKS認証エラー

**原因**: リダイレクトURIの不一致

**確認事項**:
1. `.env`の`APP_URL`が正しいか
2. LINE WORKS Developer Consoleのリダイレクト URIが正確か
3. HTTPSが有効か

**ログ確認**:
```bash
sudo tail -50 /var/www/shin-on_wiki/storage/logs/laravel.log | grep -i oidc
```

### 問題: Dropboxバックアップ失敗

**原因**: mysqldumpコマンドが見つからない

**解決策**:
```bash
# mysqldumpの確認
which mysqldump

# インストールされていない場合
sudo apt install mysql-client
```

**原因**: ファイルパーミッション不足

**解決策**:
```bash
sudo chmod 775 /var/www/shin-on_wiki/storage/app/backups
sudo chown www-data:www-data /var/www/shin-on_wiki/storage/app/backups
```

### 問題: カバー画像が表示されない

**原因**: サムネイルが生成されていない

**解決策**:
```bash
cd /var/www/shin-on_wiki
sudo -u www-data php artisan bookstack:regenerate-thumbnails
```

### 問題: アセット(CSS/JS)が読み込まれない

**原因**: ビルドが完了していない

**解決策**:
```bash
cd /var/www/shin-on_wiki
npm run production
```

### 問題: データベース接続エラー

**確認事項**:
```bash
# MySQLサービス確認
sudo systemctl status mysql

# データベース接続テスト
mysql -u bookstack -p shin_on_wiki
```

**ログ確認**:
```bash
sudo tail -50 /var/log/mysql/error.log
```

### 問題: キャッシュが更新されない

**解決策**:
```bash
cd /var/www/shin-on_wiki
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

## 更新デプロイ

アプリケーションを更新する際の手順:

```bash
cd /var/www/shin-on_wiki

# メンテナンスモードに入る
sudo -u www-data php artisan down

# 最新コードを取得
git pull origin main

# Composer依存関係を更新
composer install --no-dev --optimize-autoloader

# NPM依存関係を更新（必要な場合）
npm install
npm run production

# データベースマイグレーション（必要な場合）
php artisan migrate --force

# キャッシュをクリア
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# メンテナンスモードを解除
sudo -u www-data php artisan up
```

---

## セキュリティベストプラクティス

### 1. .envファイルの保護

```bash
# .envファイルは必ず非公開に
sudo chmod 600 /var/www/shin-on_wiki/.env
sudo chown www-data:www-data /var/www/shin-on_wiki/.env
```

### 2. ディレクトリリスティングの無効化

Apacheの設定に含まれています（`Options -Indexes`）

### 3. 不要なファイルの削除

```bash
cd /var/www/shin-on_wiki
rm -rf .git        # 本番環境ではGit履歴は不要（更新時は残す）
rm -rf tests       # テストファイルは不要
rm -rf node_modules  # ビルド後は不要
```

### 4. ログのローテーション

```bash
# Logrotateの設定
sudo nano /etc/logrotate.d/shin-on_wiki
```

内容:
```
/var/www/shin-on_wiki/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0775 www-data www-data
    sharedscripts
}
```

### 5. ファイアウォールの強化

```bash
# 必要なポートのみ開放
sudo ufw status numbered

# 不要なポートがあれば閉じる
```

### 6. 定期的なセキュリティアップデート

```bash
# 自動セキュリティアップデートの有効化
sudo apt install unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades
```

---

## 監視とメンテナンス

### ログ監視

```bash
# アプリケーションログ
sudo tail -f /var/www/shin-on_wiki/storage/logs/laravel.log

# Apacheログ
sudo tail -f /var/log/apache2/shin-on_wiki-ssl-error.log
sudo tail -f /var/log/apache2/shin-on_wiki-ssl-access.log
```

### ディスク容量監視

```bash
# ディスク使用量確認
df -h

# アップロードファイルのサイズ確認
du -sh /var/www/shin-on_wiki/public/uploads
du -sh /var/www/shin-on_wiki/storage/app/backups
```

### データベースバックアップ（Dropbox以外の方法）

```bash
# 手動バックアップ
mysqldump -u bookstack -p shin_on_wiki > backup_$(date +%Y%m%d_%H%M%S).sql

# cron設定（毎日午前3時）
sudo crontab -e
```

追加:
```
0 3 * * * mysqldump -u bookstack -pYOUR_PASSWORD shin_on_wiki | gzip > /var/backups/shin-on_wiki_$(date +\%Y\%m\%d).sql.gz
```

---

## 関連ドキュメント

- [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md) - デプロイチェックリスト
- [SYSTEM_REQUIREMENTS.md](./SYSTEM_REQUIREMENTS.md) - システム要件詳細
- [BACKUP_RESTORE.md](./BACKUP_RESTORE.md) - バックアップ・リストアガイド
- [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md) - LINE WORKS SSO設定詳細

---

## サポート

問題が発生した場合:

1. ログファイルを確認
2. [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md)を再確認
3. GitHubのIssuesを確認

---

**最終更新**: 2025年11月17日
**作成者**: Claude Code + satoshi
