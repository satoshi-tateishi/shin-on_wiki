# Docker本番環境デプロイメントガイド

## 📋 目次

1. [概要](#概要)
2. [アーキテクチャ](#アーキテクチャ)
3. [前提条件](#前提条件)
4. [サーバー環境構築](#サーバー環境構築)
5. [アプリケーションのデプロイ](#アプリケーションのデプロイ)
6. [複数アプリケーション運用](#複数アプリケーション運用)
7. [SSL証明書の設定](#ssl証明書の設定)
8. [バックアップとリストア](#バックアップとリストア)
9. [監視とメンテナンス](#監視とメンテナンス)
10. [トラブルシューティング](#トラブルシューティング)

---

## 概要

このドキュメントは、shin·on Wiki (BookStack)をDockerを使用した本番環境にデプロイする手順を説明します。

### 🎯 対象環境

- **OS**: Ubuntu 22.04 LTS / 24.04 LTS (推奨)
- **Docker**: 最新安定版
- **リバースプロキシ**: Apache 2.4 または Nginx (ホストOS上)
- **SSL**: Let's Encrypt (Certbot使用)
- **認証**: LINE WORKS OpenID Connect
- **バックアップ**: Dropbox

### ✅ このガイドで実現できること

- Docker Composeを使用した本番環境デプロイ
- 同一サーバーでの複数アプリケーション運用
- Apache/NginxリバースプロキシによるSSL終端
- 自動SSL証明書取得と更新
- コンテナの自動起動とヘルスチェック
- リソース制限とログ管理

---

## アーキテクチャ

### 基本構成

```
インターネット
    ↓
[ホストOS Ubuntu]
    ↓
[Apache/Nginx (SSL終端)]
 - ポート 80 (HTTP)
 - ポート 443 (HTTPS)
    ↓
[Docker Network]
    ↓
┌─────────────────────────────┐
│ App Container (BookStack)   │
│ - ポート 8083 → 80          │
└─────────────────────────────┘
    ↓
┌─────────────────────────────┐
│ DB Container (MySQL 8.4)    │
│ - ポート 3306 (内部のみ)    │
└─────────────────────────────┘
```

### 複数アプリケーション運用時

```
[Apache/Nginx (SSL終端)]
    ├─ wiki.example.com → localhost:8083 (App1)
    ├─ app2.example.com → localhost:8084 (App2)
    └─ app3.example.com → localhost:8085 (App3)

[Docker Networks]
    ├─ shin-on_wiki_network (App1専用)
    ├─ app2_network (App2専用)
    └─ app3_network (App3専用)
```

### データフロー

1. **HTTPSリクエスト** → Apache/Nginx (443)
2. **SSL終端** → Apache/Nginxで証明書処理
3. **プロキシ** → Dockerコンテナ (8083等)
4. **アプリケーション処理** → BookStack (Laravel)
5. **データベースアクセス** → MySQL (Docker内部ネットワーク)

---

## 前提条件

### ハードウェア要件

詳細は `SYSTEM_REQUIREMENTS.md` を参照してください。

**最小要件**:
- CPU: 2コア
- メモリ: 4GB
- ストレージ: 20GB (SSD推奨)

**推奨構成**:
- CPU: 4コア以上
- メモリ: 8GB以上
- ストレージ: 50GB以上 (SSD)

### 必要な情報

デプロイ前に以下の情報を準備してください:

#### 1. ドメイン名
- 本番環境のドメイン (例: `wiki.example.com`)
- DNSレコードの設定権限

#### 2. LINE WORKS OAuth認証情報
- Client ID
- Client Secret
- リダイレクトURI: `https://your-domain.com/oidc/callback`

#### 3. Dropbox OAuth認証情報
- Client ID (App key)
- Client Secret (App secret)
- リダイレクトURI: `https://your-domain.com/auth/dropbox/callback`

#### 4. データベースパスワード
- 安全な強力なパスワードを生成

---

## サーバー環境構築

### 1. 初期セットアップ

#### システム更新

```bash
# システムパッケージの更新
sudo apt update
sudo apt upgrade -y

# 必要なパッケージのインストール
sudo apt install -y \
    git \
    curl \
    wget \
    unzip \
    ca-certificates \
    gnupg \
    lsb-release
```

#### タイムゾーン設定

```bash
# タイムゾーンを日本に設定
sudo timedatectl set-timezone Asia/Tokyo

# 確認
timedatectl
```

### 2. Dockerのインストール

```bash
# Dockerの公式GPGキーを追加
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
    sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

# Dockerリポジトリを追加
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Dockerをインストール
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Dockerサービスを有効化
sudo systemctl enable docker
sudo systemctl start docker

# 現在のユーザーをdockerグループに追加
sudo usermod -aG docker $USER

# 再ログイン後、確認
docker --version
docker compose version
```

### 3. Apache/Nginxのインストール

#### Apacheの場合

```bash
# Apacheのインストール
sudo apt install -y apache2

# 必要なモジュールを有効化
sudo a2enmod ssl
sudo a2enmod proxy
sudo a2enmod proxy_http
sudo a2enmod headers
sudo a2enmod rewrite

# Apacheを起動
sudo systemctl enable apache2
sudo systemctl start apache2
```

#### Nginxの場合

```bash
# Nginxのインストール
sudo apt install -y nginx

# Nginxを起動
sudo systemctl enable nginx
sudo systemctl start nginx
```

### 4. Certbot (Let's Encrypt) のインストール

```bash
# Certbotのインストール
sudo apt install -y certbot

# Apache用プラグイン
sudo apt install -y python3-certbot-apache

# または Nginx用プラグイン
sudo apt install -y python3-certbot-nginx
```

---

## アプリケーションのデプロイ

### 1. アプリケーションの配置

```bash
# デプロイ用ディレクトリを作成
sudo mkdir -p /var/www
cd /var/www

# Gitリポジトリをクローン
sudo git clone <your-repository-url> shin-on_wiki
sudo chown -R $USER:$USER shin-on_wiki
cd shin-on_wiki
```

### 2. 環境変数ファイルの作成

```bash
# .env.production.example をコピー
cp .env.production.example .env

# .env ファイルを編集
nano .env
```

#### 必須設定項目

```bash
# ====================
# アプリケーション基本設定
# ====================
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wiki.example.com

# セキュリティ: 32文字のランダム文字列を生成
# 生成方法: openssl rand -base64 32
APP_KEY=base64:YOUR_GENERATED_KEY_HERE

# ====================
# データベース設定
# ====================
DB_HOST=db
DB_PORT=3306
DB_DATABASE=shin_on_wiki
DB_USERNAME=bookstack
# セキュリティ: 強力なパスワードを生成
# 生成方法: openssl rand -base64 24
DB_PASSWORD=YOUR_STRONG_DB_PASSWORD

# ====================
# LINE WORKS認証設定
# ====================
AUTH_METHOD=oidc
OIDC_NAME=LINE_WORKS
OIDC_DISPLAY_NAME_CLAIMS=name
OIDC_CLIENT_ID=YOUR_LINEWORKS_CLIENT_ID
OIDC_CLIENT_SECRET=YOUR_LINEWORKS_CLIENT_SECRET
OIDC_ISSUER=https://auth.worksmobile.com/YOUR_TENANT_ID
OIDC_ISSUER_DISCOVER=true

# ====================
# Dropbox設定
# ====================
DROPBOX_CLIENT_ID=YOUR_DROPBOX_CLIENT_ID
DROPBOX_CLIENT_SECRET=YOUR_DROPBOX_CLIENT_SECRET
DROPBOX_REDIRECT_URI=https://wiki.example.com/auth/dropbox/callback

# ====================
# Docker設定
# ====================
COMPOSE_PROJECT_NAME=shin-on_wiki
APP_PORT=8083
```

### 3. アプリケーションキーの生成

```bash
# APP_KEYを生成（初回デプロイ時のみ）
docker compose -f docker-compose.production.yml run --rm app php artisan key:generate --show

# 生成されたキーを.envファイルのAPP_KEYに設定
```

### 4. Dockerイメージのビルド

```bash
# イメージをビルド
docker compose -f docker-compose.production.yml build

# ビルド確認
docker images | grep shin-on_wiki
```

### 5. コンテナの起動

```bash
# コンテナをバックグラウンドで起動
docker compose -f docker-compose.production.yml up -d

# 起動確認
docker compose -f docker-compose.production.yml ps

# ログ確認
docker compose -f docker-compose.production.yml logs -f app
```

### 6. データベースの初期化

```bash
# マイグレーション実行
docker compose -f docker-compose.production.yml exec app php artisan migrate --force

# 確認
docker compose -f docker-compose.production.yml exec app php artisan migrate:status
```

### 7. ストレージディレクトリの権限設定

```bash
# コンテナ内のwww-dataユーザーの権限を設定
sudo chown -R www-data:www-data storage bootstrap/cache public/uploads
sudo chmod -R 775 storage bootstrap/cache public/uploads
```

### 8. アプリケーションの最適化

```bash
# キャッシュクリア
docker compose -f docker-compose.production.yml exec app php artisan cache:clear
docker compose -f docker-compose.production.yml exec app php artisan config:clear
docker compose -f docker-compose.production.yml exec app php artisan route:clear
docker compose -f docker-compose.production.yml exec app php artisan view:clear

# 本番環境用の最適化
docker compose -f docker-compose.production.yml exec app php artisan config:cache
docker compose -f docker-compose.production.yml exec app php artisan route:cache
docker compose -f docker-compose.production.yml exec app php artisan view:cache
```

---

## 複数アプリケーション運用

同じサーバーで複数のDockerアプリケーションを運用する手順です。

### 1. アプリケーションごとの設定

各アプリケーションで以下を設定します:

#### App1 (shin-on_wiki) の .env

```bash
COMPOSE_PROJECT_NAME=shin-on_wiki
APP_PORT=8083
APP_URL=https://wiki.example.com
```

#### App2 (example-app2) の .env

```bash
COMPOSE_PROJECT_NAME=example-app2
APP_PORT=8084
APP_URL=https://app2.example.com
```

#### App3 (example-app3) の .env

```bash
COMPOSE_PROJECT_NAME=example-app3
APP_PORT=8085
APP_URL=https://app3.example.com
```

### 2. ディレクトリ構成例

```
/var/www/
├── shin-on_wiki/           # App1
│   ├── docker-compose.production.yml
│   ├── .env (APP_PORT=8083)
│   └── ...
├── example-app2/           # App2
│   ├── docker-compose.production.yml
│   ├── .env (APP_PORT=8084)
│   └── ...
└── example-app3/           # App3
    ├── docker-compose.production.yml
    ├── .env (APP_PORT=8085)
    └── ...
```

### 3. 各アプリケーションの起動

```bash
# App1
cd /var/www/shin-on_wiki
docker compose -f docker-compose.production.yml up -d

# App2
cd /var/www/example-app2
docker compose -f docker-compose.production.yml up -d

# App3
cd /var/www/example-app3
docker compose -f docker-compose.production.yml up -d
```

### 4. リバースプロキシの設定

#### Apache VirtualHost設定例

`/etc/apache2/sites-available/multi-apps.conf`:

```apache
# ====================
# App1: wiki.example.com
# ====================
<VirtualHost *:443>
    ServerName wiki.example.com

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/wiki.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/wiki.example.com/privkey.pem

    ProxyPreserveHost On
    ProxyPass / http://localhost:8083/
    ProxyPassReverse / http://localhost:8083/

    ErrorLog ${APACHE_LOG_DIR}/wiki-error.log
    CustomLog ${APACHE_LOG_DIR}/wiki-access.log combined
</VirtualHost>

# ====================
# App2: app2.example.com
# ====================
<VirtualHost *:443>
    ServerName app2.example.com

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/app2.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/app2.example.com/privkey.pem

    ProxyPreserveHost On
    ProxyPass / http://localhost:8084/
    ProxyPassReverse / http://localhost:8084/

    ErrorLog ${APACHE_LOG_DIR}/app2-error.log
    CustomLog ${APACHE_LOG_DIR}/app2-access.log combined
</VirtualHost>

# ====================
# App3: app3.example.com
# ====================
<VirtualHost *:443>
    ServerName app3.example.com

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/app3.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/app3.example.com/privkey.pem

    ProxyPreserveHost On
    ProxyPass / http://localhost:8085/
    ProxyPassReverse / http://localhost:8085/

    ErrorLog ${APACHE_LOG_DIR}/app3-error.log
    CustomLog ${APACHE_LOG_DIR}/app3-access.log combined
</VirtualHost>

# HTTP → HTTPS リダイレクト
<VirtualHost *:80>
    ServerName wiki.example.com
    Redirect permanent / https://wiki.example.com/
</VirtualHost>

<VirtualHost *:80>
    ServerName app2.example.com
    Redirect permanent / https://app2.example.com/
</VirtualHost>

<VirtualHost *:80>
    ServerName app3.example.com
    Redirect permanent / https://app3.example.com/
</VirtualHost>
```

#### Nginx設定例

`/etc/nginx/sites-available/multi-apps`:

```nginx
# ====================
# App1: wiki.example.com
# ====================
server {
    listen 443 ssl http2;
    server_name wiki.example.com;

    ssl_certificate /etc/letsencrypt/live/wiki.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/wiki.example.com/privkey.pem;

    location / {
        proxy_pass http://localhost:8083;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    access_log /var/log/nginx/wiki-access.log;
    error_log /var/log/nginx/wiki-error.log;
}

# ====================
# App2: app2.example.com
# ====================
server {
    listen 443 ssl http2;
    server_name app2.example.com;

    ssl_certificate /etc/letsencrypt/live/app2.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app2.example.com/privkey.pem;

    location / {
        proxy_pass http://localhost:8084;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    access_log /var/log/nginx/app2-access.log;
    error_log /var/log/nginx/app2-error.log;
}

# HTTP → HTTPS リダイレクト
server {
    listen 80;
    server_name wiki.example.com app2.example.com app3.example.com;
    return 301 https://$server_name$request_uri;
}
```

### 5. 設定の有効化

#### Apache

```bash
# サイト設定を有効化
sudo a2ensite multi-apps

# 設定をテスト
sudo apache2ctl configtest

# Apacheを再起動
sudo systemctl restart apache2
```

#### Nginx

```bash
# シンボリックリンクを作成
sudo ln -s /etc/nginx/sites-available/multi-apps /etc/nginx/sites-enabled/

# 設定をテスト
sudo nginx -t

# Nginxを再起動
sudo systemctl restart nginx
```

---

## SSL証明書の設定

### 1. 証明書の取得

各ドメインごとにSSL証明書を取得します。

#### Apache + Certbot

```bash
# App1の証明書取得
sudo certbot --apache -d wiki.example.com

# App2の証明書取得
sudo certbot --apache -d app2.example.com

# App3の証明書取得
sudo certbot --apache -d app3.example.com
```

#### Nginx + Certbot

```bash
# App1の証明書取得
sudo certbot --nginx -d wiki.example.com

# App2の証明書取得
sudo certbot --nginx -d app2.example.com

# App3の証明書取得
sudo certbot --nginx -d app3.example.com
```

### 2. 自動更新の設定

Certbotは自動的に証明書の更新タスクをcronに登録しますが、確認しておきます。

```bash
# 自動更新のテスト
sudo certbot renew --dry-run

# cronジョブの確認
sudo systemctl status certbot.timer

# 手動更新（必要な場合）
sudo certbot renew
```

### 3. OAuth リダイレクトURIの更新

SSL証明書取得後、LINE WORKSとDropboxの設定を更新します。

#### LINE WORKS Developer Console

- リダイレクトURI: `https://wiki.example.com/oidc/callback`

#### Dropbox App Console

- リダイレクトURI: `https://wiki.example.com/auth/dropbox/callback`

---

## バックアップとリストア

詳細は `BACKUP_RESTORE.md` を参照してください。

### 自動バックアップの設定

```bash
# バックアップスクリプトを作成
sudo nano /usr/local/bin/backup-shin-on-wiki.sh
```

スクリプト内容:

```bash
#!/bin/bash
cd /var/www/shin-on_wiki
docker compose -f docker-compose.production.yml exec -T app php artisan backup:dropbox
```

```bash
# 実行権限を付与
sudo chmod +x /usr/local/bin/backup-shin-on-wiki.sh

# cronジョブに追加（毎日午前3時）
(crontab -l 2>/dev/null; echo "0 3 * * * /usr/local/bin/backup-shin-on-wiki.sh >> /var/log/backup-shin-on-wiki.log 2>&1") | crontab -
```

### バックアップの実行

```bash
# 手動バックアップ
docker compose -f docker-compose.production.yml exec app php artisan backup:dropbox

# バックアップ状況確認
docker compose -f docker-compose.production.yml exec app php artisan backup:test
```

### リストア

```bash
# 利用可能なバックアップを確認
docker compose -f docker-compose.production.yml exec app php artisan restore:dropbox:list

# 特定のバックアップをリストア
docker compose -f docker-compose.production.yml exec app php artisan restore:dropbox:latest
```

---

## 監視とメンテナンス

### コンテナの状態確認

```bash
# すべてのコンテナ状態を確認
docker compose -f docker-compose.production.yml ps

# 特定アプリのコンテナ確認
docker ps | grep shin-on_wiki

# リソース使用状況
docker stats
```

### ログの確認

```bash
# アプリケーションログ
docker compose -f docker-compose.production.yml logs -f app

# データベースログ
docker compose -f docker-compose.production.yml logs -f db

# 最新100行のみ表示
docker compose -f docker-compose.production.yml logs --tail=100 app

# Apacheログ
sudo tail -f /var/log/apache2/wiki-access.log
sudo tail -f /var/log/apache2/wiki-error.log

# Nginxログ
sudo tail -f /var/log/nginx/wiki-access.log
sudo tail -f /var/log/nginx/wiki-error.log
```

### ディスク容量の監視

```bash
# ディスク使用状況
df -h

# Dockerのディスク使用状況
docker system df

# 未使用リソースのクリーンアップ（注意して実行）
docker system prune -a --volumes
```

### 定期メンテナンス

#### 週次メンテナンス

```bash
# ログローテーションの確認
sudo logrotate -f /etc/logrotate.d/apache2  # または nginx

# Dockerリソースのクリーンアップ
docker image prune -f
docker volume prune -f
```

#### 月次メンテナンス

```bash
# システムパッケージの更新
sudo apt update && sudo apt upgrade -y

# Dockerイメージの更新
cd /var/www/shin-on_wiki
docker compose -f docker-compose.production.yml pull
docker compose -f docker-compose.production.yml up -d --build
```

---

## アップデート手順

### アプリケーションのアップデート

```bash
cd /var/www/shin-on_wiki

# 最新のコードを取得
git pull origin main

# バックアップ（念のため）
docker compose -f docker-compose.production.yml exec app php artisan backup:dropbox

# イメージを再ビルド
docker compose -f docker-compose.production.yml build

# コンテナを再起動
docker compose -f docker-compose.production.yml up -d

# マイグレーション実行（必要な場合）
docker compose -f docker-compose.production.yml exec app php artisan migrate --force

# キャッシュクリア
docker compose -f docker-compose.production.yml exec app php artisan cache:clear
docker compose -f docker-compose.production.yml exec app php artisan config:cache
docker compose -f docker-compose.production.yml exec app php artisan route:cache
docker compose -f docker-compose.production.yml exec app php artisan view:cache
```

### ゼロダウンタイムデプロイ（応用）

複数コンテナを使用したブルーグリーンデプロイも可能です。

---

## トラブルシューティング

### コンテナが起動しない

```bash
# ログを確認
docker compose -f docker-compose.production.yml logs app

# コンテナを再作成
docker compose -f docker-compose.production.yml down
docker compose -f docker-compose.production.yml up -d --force-recreate

# イメージを再ビルド
docker compose -f docker-compose.production.yml build --no-cache
```

### データベース接続エラー

```bash
# データベースのヘルスチェック
docker compose -f docker-compose.production.yml exec db mysqladmin ping -h localhost

# データベース接続テスト
docker compose -f docker-compose.production.yml exec app php artisan db:show

# .envファイルのDB設定を確認
cat .env | grep DB_
```

### ポート競合エラー

```bash
# ポート使用状況を確認
sudo lsof -i :8083
sudo lsof -i :8084

# .envファイルのAPP_PORTを変更
nano .env
# APP_PORT=8086 など別のポートに変更

# コンテナを再起動
docker compose -f docker-compose.production.yml up -d
```

### パーミッションエラー

```bash
# ストレージディレクトリの権限修正
sudo chown -R www-data:www-data storage bootstrap/cache public/uploads
sudo chmod -R 775 storage bootstrap/cache public/uploads

# SELinuxが有効な場合
sudo setenforce 0  # 一時的に無効化（テスト用）
```

### SSL証明書エラー

```bash
# 証明書の有効期限確認
sudo certbot certificates

# 証明書の更新
sudo certbot renew

# Apache/Nginxの設定テスト
sudo apache2ctl configtest  # Apache
sudo nginx -t               # Nginx

# サービスの再起動
sudo systemctl restart apache2  # Apache
sudo systemctl restart nginx    # Nginx
```

### メモリ不足エラー

```bash
# コンテナのリソース制限を確認
docker stats

# docker-compose.production.yml のメモリ制限を調整
nano docker-compose.production.yml

# services.app.deploy.resources.limits.memory を増やす
# 例: 2G → 3G

# コンテナを再起動
docker compose -f docker-compose.production.yml up -d
```

### ネットワークエラー

```bash
# Dockerネットワークの確認
docker network ls
docker network inspect shin-on_wiki_network

# ネットワークの再作成
docker compose -f docker-compose.production.yml down
docker network prune
docker compose -f docker-compose.production.yml up -d
```

### バックアップエラー

#### エラー: `mysqldump: not found`

バックアップ実行時に以下のエラーが発生する場合：

```
失敗: Database backup failed: sh: 1: mysqldump: not found
```

**原因**: Dockerコンテナ内に `mysqldump` がインストールされていない

**解決方法**:

このプロジェクトでは、`dev/docker/Dockerfile`に既に`default-mysql-client`が含まれているため、イメージを再ビルドすることで解決します：

```bash
# コンテナを停止
docker compose -f docker-compose.production.yml down

# イメージを再ビルド
docker compose -f docker-compose.production.yml build

# コンテナを起動
docker compose -f docker-compose.production.yml up -d

# mysqldump が利用可能か確認
docker compose -f docker-compose.production.yml exec app which mysqldump
# /usr/bin/mysqldump

docker compose -f docker-compose.production.yml exec app mysqldump --version
# mysqldump from 11.8.3-MariaDB
```

**参考**: 別のDockerプロジェクトで同じエラーが発生する場合は、`Dockerfile`に以下を追加：

```dockerfile
RUN apt-get update && \
    apt-get install -y default-mysql-client && \
    rm -rf /var/lib/apt/lists/*
```

#### その他のバックアップエラー

```bash
# ディスク容量を確認
df -h

# バックアップログを確認
docker compose -f docker-compose.production.yml exec app tail -f storage/logs/laravel.log

# ストレージディレクトリの権限を確認
docker compose -f docker-compose.production.yml exec app ls -la storage/app/backups
```

---

## セキュリティ推奨事項

### ファイアウォール設定

```bash
# UFWをインストール（Ubuntu）
sudo apt install -y ufw

# SSH、HTTP、HTTPSを許可
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Dockerポートは外部公開しない
# （リバースプロキシ経由でのみアクセス）

# ファイアウォールを有効化
sudo ufw enable
sudo ufw status
```

### 定期的なセキュリティアップデート

```bash
# 自動セキュリティアップデートの設定
sudo apt install -y unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades
```

### ログ監視

```bash
# fail2banのインストール（ブルートフォース攻撃対策）
sudo apt install -y fail2ban

# fail2ban設定
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local
sudo nano /etc/fail2ban/jail.local

# fail2banを有効化
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

---

## まとめ

このガイドでは、Dockerを使用したshin·on Wikiの本番環境デプロイ手順を説明しました。

### ✅ 完了したこと

- Docker環境のセットアップ
- リバースプロキシの設定
- SSL証明書の取得
- アプリケーションのデプロイ
- 複数アプリケーション運用の準備
- バックアップの自動化
- 監視とメンテナンス体制

### 📚 関連ドキュメント

- `SYSTEM_REQUIREMENTS.md` - システム要件
- `DEPLOYMENT_CHECKLIST.md` - デプロイチェックリスト
- `BACKUP_RESTORE.md` - バックアップとリストア
- `docker-compose.production.yml` - Docker Compose設定
- `apache-vhost.conf.example` - Apache設定例

### 🔧 サポート

問題が発生した場合は、以下を確認してください:

1. ログファイル (`docker compose logs`)
2. 設定ファイル (`.env`, `docker-compose.production.yml`)
3. Apache/Nginxの設定とログ
4. ファイアウォール設定

---

**最終更新**: 2025年11月17日
**バージョン**: 1.0.0
**対象BookStackバージョン**: v25.11
