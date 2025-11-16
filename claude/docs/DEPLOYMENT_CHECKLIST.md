# デプロイメントチェックリスト

shin·on Wiki by BookStack を Ubuntu 本番環境にデプロイする際の完全チェックリストです。

---

## 📋 目次

1. [デプロイメント方法の選択](#デプロイメント方法の選択)
2. [デプロイ前準備](#デプロイ前準備)
3. [OAuth設定更新](#oauth設定更新)
4. [サーバー環境準備](#サーバー環境準備)
5. [初回デプロイ](#初回デプロイ)
   - [A. 直接デプロイ（従来型）](#a-直接デプロイ従来型)
   - [B. Dockerデプロイ（推奨）](#b-dockerデプロイ推奨)
6. [デプロイ後確認](#デプロイ後確認)
7. [運用開始準備](#運用開始準備)
8. [更新デプロイ](#更新デプロイ)

---

## デプロイメント方法の選択

このプロジェクトは2つのデプロイ方法をサポートしています:

### A. 直接デプロイ（従来型）

- Apache + PHP-FPM を直接Ubuntu上で動作
- シンプルな構成
- 単一アプリケーション運用に適している
- 参考: `claude/docs/DEPLOYMENT.md`

### B. Dockerデプロイ（推奨）⭐

- Docker + Apache/Nginxリバースプロキシ構成
- **同一サーバーで複数のWEBアプリケーション運用が可能**
- コンテナによる環境の一貫性
- ポートベースでの簡単な複数アプリ管理
- 参考: `claude/docs/DEPLOYMENT_DOCKER.md`

### 推奨: Dockerデプロイ

以下の場合はDockerデプロイを推奨します:

- [ ] 同じサーバーで複数のWEBアプリケーションを運用したい
- [ ] 環境の一貫性を保ちたい
- [ ] ポート分離でアプリケーションを管理したい
- [ ] 将来的にアプリケーションを追加する可能性がある

**このチェックリストではDockerデプロイを中心に解説し、必要に応じて従来型の手順も記載します。**

---

## デプロイ前準備

### ドメインとDNS

- [ ] 本番環境用のドメイン名を取得済み
- [ ] DNSレコードがサーバーIPアドレスを指している
- [ ] DNS伝播が完了している（`nslookup your-domain.com` で確認）
- [ ] サブドメインを使用する場合、適切に設定されている

### アクセス権限

- [ ] GitHubリポジトリへのアクセス権限がある
- [ ] LINE WORKS Developer Consoleへのアクセス権限がある
- [ ] Dropbox App Consoleへのアクセス権限がある
- [ ] サーバーへのSSHアクセスが可能（rootまたはsudo権限）

### ローカル環境での最終確認

- [ ] 開発環境でLINE WORKS SSOログインが正常に動作している
- [ ] 開発環境でDropboxバックアップが正常に動作している
- [ ] すべてのテストがパスしている
- [ ] データベースマイグレーションに問題がない
- [ ] Gitリポジトリがクリーンな状態（コミットされていない変更がない）
- [ ] すべての変更がmainブランチにマージされている

### ドキュメントの確認

**Dockerデプロイの場合:**
- [ ] `claude/docs/DEPLOYMENT_DOCKER.md` を読んだ（推奨）
- [ ] `docker-compose.production.yml` の内容を確認した
- [ ] `apache-vhost.conf.example` のDockerリバースプロキシ設定を確認した

**直接デプロイの場合:**
- [ ] `claude/docs/DEPLOYMENT.md` を読んだ
- [ ] `apache-vhost.conf.example` の直接デプロイ設定を確認した

**共通:**
- [ ] `claude/docs/SYSTEM_REQUIREMENTS.md` を読んだ
- [ ] `.env.production.example` の内容を確認した

---

## OAuth設定更新

### LINE WORKS Developer Console

1. [LINE WORKS Developer Console](https://developers.worksmobile.com/jp/console/openapi/v2/app/list) にアクセス

2. アプリケーションを選択

3. OAuth 2.0 設定を更新:
   - [ ] **Redirect URI** に本番環境URLを追加:
     ```
     https://your-domain.com/oidc/callback
     ```
   - [ ] 開発環境URLは残しておく:
     ```
     https://localhost:8443/oidc/callback
     ```

4. Client IDとClient Secretをメモ:
   - [ ] `OIDC_CLIENT_ID` を記録
   - [ ] `OIDC_CLIENT_SECRET` を記録

5. 変更を保存:
   - [ ] 「保存」ボタンをクリック
   - [ ] 設定が反映されたことを確認

### Dropbox App Console

1. [Dropbox App Console](https://www.dropbox.com/developers/apps) にアクセス

2. アプリケーションを選択

3. **Settings** タブを開く

4. OAuth 2 設定を更新:
   - [ ] **Redirect URIs** に本番環境URLを追加:
     ```
     https://your-domain.com/auth/dropbox/callback
     ```
   - [ ] 開発環境URLは残しておく:
     ```
     https://localhost:8443/auth/dropbox/callback
     ```

5. App key と App secret をメモ:
   - [ ] `DROPBOX_CLIENT_ID` を記録
   - [ ] `DROPBOX_CLIENT_SECRET` を記録

6. 変更を保存:
   - [ ] 「Add」ボタンをクリック
   - [ ] 設定が反映されたことを確認

---

## サーバー環境準備

### システム要件確認

- [ ] Ubuntu 22.04 LTS または 24.04 LTS がインストールされている
- [ ] RAM が 2GB 以上（推奨 4GB 以上、複数アプリ運用時は 8GB 以上）
- [ ] ストレージが 20GB 以上の空き容量（推奨 50GB 以上）
- [ ] インターネット接続が安定している

### 基本パッケージのインストール

```bash
# パッケージリストを更新
sudo apt update && sudo apt upgrade -y

# 基本パッケージをインストール
sudo apt install -y \
  git \
  curl \
  wget \
  unzip \
  ca-certificates \
  gnupg \
  lsb-release \
  certbot
```

- [ ] システムパッケージが最新
- [ ] 基本パッケージがインストールされた

### A. Dockerデプロイ用の環境準備（推奨）

#### Dockerのインストール

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

# ログアウト後、再ログインして確認
docker --version
docker compose version
```

- [ ] Dockerがインストールされた
- [ ] Docker Composeがインストールされた
- [ ] Dockerサービスが起動している
- [ ] 現在のユーザーがdockerグループに追加された

#### Apache/Nginxのインストール（リバースプロキシ用）

**Apacheの場合:**
```bash
# Apacheのインストール
sudo apt install -y apache2 python3-certbot-apache

# 必要なモジュールを有効化
sudo a2enmod ssl proxy proxy_http headers rewrite

# Apacheを起動
sudo systemctl enable apache2
sudo systemctl start apache2
```

- [ ] Apacheがインストールされた
- [ ] 必要なモジュールが有効化された
- [ ] Apacheが起動している

**Nginxの場合:**
```bash
# Nginxのインストール
sudo apt install -y nginx python3-certbot-nginx

# Nginxを起動
sudo systemctl enable nginx
sudo systemctl start nginx
```

- [ ] Nginxがインストールされた
- [ ] Nginxが起動している

### B. 直接デプロイ用の環境準備（従来型）

#### PHP・MySQL・Composerのインストール

```bash
# 必須パッケージをインストール
sudo apt install -y \
  apache2 \
  mysql-server \
  php8.3 php8.3-cli php8.3-fpm \
  php8.3-mysql php8.3-curl php8.3-gd \
  php8.3-mbstring php8.3-xml php8.3-zip \
  php8.3-ldap php8.3-dom php8.3-fileinfo \
  libapache2-mod-php8.3 \
  composer \
  nodejs npm \
  mysql-client \
  certbot \
  python3-certbot-apache
```

- [ ] すべてのパッケージがインストールされた

#### バージョン確認

```bash
# PHP バージョン
php --version  # 8.3.x 以上

# Composer バージョン
composer --version  # 2.x.x

# Node.js バージョン
node --version  # v18.x.x 以上

# MySQL バージョン
mysql --version  # 8.0.x 以上
```

- [ ] PHP 8.3 以上
- [ ] Composer 2.x
- [ ] Node.js 18 以上
- [ ] MySQL 8.0 以上

#### MySQL初期設定

```bash
# MySQLセキュリティ設定
sudo mysql_secure_installation
```

- [ ] rootパスワードを設定
- [ ] 匿名ユーザーを削除
- [ ] リモートrootログインを無効化
- [ ] testデータベースを削除
- [ ] 権限テーブルをリロード

#### データベースとユーザーの作成

```bash
sudo mysql << EOF
CREATE DATABASE shin_on_wiki CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bookstack'@'localhost' IDENTIFIED BY 'SECURE_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON shin_on_wiki.* TO 'bookstack'@'localhost';
FLUSH PRIVILEGES;
EOF
```

- [ ] データベース `shin_on_wiki` 作成完了
- [ ] ユーザー `bookstack` 作成完了
- [ ] 強力なパスワードを使用した
- [ ] パスワードを安全に記録した

### ファイアウォール設定（共通）

```bash
sudo ufw allow 22/tcp   # SSH
sudo ufw allow 80/tcp   # HTTP
sudo ufw allow 443/tcp  # HTTPS
sudo ufw enable
sudo ufw status
```

- [ ] SSH ポート(22)が開放されている
- [ ] HTTP ポート(80)が開放されている
- [ ] HTTPS ポート(443)が開放されている
- [ ] ファイアウォールが有効化されている

**注意 (Dockerデプロイの場合):**
- Dockerポート (8083, 8084, 8085等) は外部公開しない
- リバースプロキシ経由でのみアクセス

---

## 初回デプロイ

以下のいずれかの方法を選択してください:
- **A. 直接デプロイ（従来型）**: Apache + PHP-FPMで直接実行
- **B. Dockerデプロイ（推奨）**: Docker + リバースプロキシ構成

---

## A. 直接デプロイ（従来型）

### アプリケーションディレクトリの作成

```bash
sudo mkdir -p /var/www/shin-on_wiki
sudo chown $USER:$USER /var/www/shin-on_wiki
```

- [ ] ディレクトリが作成された
- [ ] 適切な所有者が設定された

### Gitリポジトリのクローン

```bash
cd /var/www
git clone https://github.com/satoshi-tateishi/shin-on_wiki.git
cd shin-on_wiki
```

- [ ] リポジトリがクローンされた
- [ ] すべてのファイルが存在する

### 環境設定ファイルの作成

```bash
cp .env.production.example .env
nano .env
```

以下の項目を更新:

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL=https://your-domain.com` (実際のドメインに置換)
- [ ] `DB_PASSWORD` (MySQLで設定したパスワード)
- [ ] `OIDC_CLIENT_ID` (LINE WORKS)
- [ ] `OIDC_CLIENT_SECRET` (LINE WORKS)
- [ ] `LINEWORKS_DOMAIN` (例: shin-on1981)
- [ ] `DROPBOX_CLIENT_ID`
- [ ] `DROPBOX_CLIENT_SECRET`
- [ ] `DROPBOX_REDIRECT_URI` (APP_URLを使用)
- [ ] `MAIL_*` 設定 (オプション)

### アプリケーションキーの生成

```bash
php artisan key:generate
```

- [ ] アプリケーションキーが生成された
- [ ] `.env` に `APP_KEY` が設定された

### 依存関係のインストール

```bash
# Composer依存関係
composer install --no-dev --optimize-autoloader

# NPM依存関係とビルド
npm install
npm run production
```

- [ ] Composer依存関係がインストールされた
- [ ] NPM依存関係がインストールされた
- [ ] アセットがビルドされた (CSS/JS)

### データベースマイグレーション

```bash
php artisan migrate --force
```

- [ ] マイグレーションが成功
- [ ] エラーがない

### ストレージリンクの作成

```bash
php artisan storage:link
```

- [ ] ストレージリンクが作成された

### ファイルパーミッションの設定

```bash
sudo chown -R www-data:www-data /var/www/shin-on_wiki
sudo chmod -R 775 /var/www/shin-on_wiki/storage
sudo chmod -R 775 /var/www/shin-on_wiki/bootstrap/cache
sudo chmod -R 775 /var/www/shin-on_wiki/public/uploads
sudo chmod 600 /var/www/shin-on_wiki/.env
```

- [ ] 所有者が `www-data` に設定された
- [ ] `storage/` が書き込み可能
- [ ] `bootstrap/cache/` が書き込み可能
- [ ] `public/uploads/` が書き込み可能
- [ ] `.env` が保護されている (600)

### Apache設定

```bash
# VirtualHost設定ファイルをコピー
sudo cp /var/www/shin-on_wiki/apache-vhost.conf.example /etc/apache2/sites-available/shin-on_wiki.conf

# ファイルを編集
sudo nano /etc/apache2/sites-available/shin-on_wiki.conf
```

- [ ] `your-domain.com` を実際のドメインに置換
- [ ] `admin@your-domain.com` を実際のメールアドレスに置換
- [ ] DocumentRoot パスが正しい

```bash
# 必要なモジュールを有効化
sudo a2enmod rewrite headers ssl

# デフォルトサイトを無効化
sudo a2dissite 000-default

# 新しいサイトを有効化
sudo a2ensite shin-on_wiki

# 設定をテスト
sudo apache2ctl configtest

# Apacheを再起動
sudo systemctl reload apache2
```

- [ ] 必要なモジュールが有効化された
- [ ] サイトが有効化された
- [ ] 設定テストがOK (`Syntax OK`)
- [ ] Apacheが再起動された

### SSL証明書のセットアップ

```bash
sudo certbot --apache -d your-domain.com
```

プロンプトに従って入力:
- [ ] メールアドレスを入力
- [ ] 利用規約に同意
- [ ] HTTPSリダイレクトを選択 (推奨: Yes)

確認:
- [ ] 証明書が正常にインストールされた
- [ ] HTTPS でアクセス可能
- [ ] HTTP が HTTPS にリダイレクトされる

### キャッシュの最適化

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

- [ ] 設定がキャッシュされた
- [ ] ルートがキャッシュされた
- [ ] ビューがキャッシュされた

---

## B. Dockerデプロイ（推奨）

### アプリケーションディレクトリの作成

```bash
sudo mkdir -p /var/www/shin-on_wiki
sudo chown $USER:$USER /var/www/shin-on_wiki
```

- [ ] ディレクトリが作成された
- [ ] 適切な所有者が設定された

### Gitリポジトリのクローン

```bash
cd /var/www
git clone https://github.com/satoshi-tateishi/shin-on_wiki.git
cd shin-on_wiki
```

- [ ] リポジトリがクローンされた
- [ ] すべてのファイルが存在する

### 環境設定ファイルの作成

```bash
cp .env.production.example .env
nano .env
```

以下の項目を更新:

**Docker設定:**
- [ ] `COMPOSE_PROJECT_NAME=shin-on_wiki`
- [ ] `APP_PORT=8083` (複数アプリの場合: 8083, 8084, 8085等)

**アプリケーション設定:**
- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL=https://wiki.example.com` (実際のドメインに置換)

**データベース設定 (Dockerコンテナ内):**
- [ ] `DB_CONNECTION=mysql`
- [ ] `DB_HOST=db` (docker-compose内部ネットワーク)
- [ ] `DB_PORT=3306`
- [ ] `DB_DATABASE=shin_on_wiki`
- [ ] `DB_USERNAME=bookstack`
- [ ] `DB_PASSWORD` (強力なパスワードを生成)

**OAuth設定:**
- [ ] `OIDC_CLIENT_ID` (LINE WORKS)
- [ ] `OIDC_CLIENT_SECRET` (LINE WORKS)
- [ ] `LINEWORKS_DOMAIN` (例: shin-on1981)
- [ ] `DROPBOX_CLIENT_ID`
- [ ] `DROPBOX_CLIENT_SECRET`
- [ ] `DROPBOX_REDIRECT_URI` (APP_URLを使用)

**メール設定 (オプション):**
- [ ] `MAIL_*` 設定

### アプリケーションキーの生成

```bash
# APP_KEYを生成
docker compose -f docker-compose.production.yml run --rm app php artisan key:generate --show

# 生成されたキーを.envのAPP_KEYに設定
nano .env
```

- [ ] アプリケーションキーが生成された
- [ ] `.env` に `APP_KEY` が設定された

### Dockerイメージのビルド

```bash
# イメージをビルド
docker compose -f docker-compose.production.yml build

# ビルド確認
docker images | grep shin-on_wiki
```

- [ ] Dockerイメージがビルドされた
- [ ] イメージが表示される

### コンテナの起動

```bash
# コンテナをバックグラウンドで起動
docker compose -f docker-compose.production.yml up -d

# 起動確認
docker compose -f docker-compose.production.yml ps

# ログ確認
docker compose -f docker-compose.production.yml logs -f app
```

- [ ] コンテナが起動した
- [ ] appコンテナが`Up`状態
- [ ] dbコンテナが`Up (healthy)`状態
- [ ] ログにエラーがない

### データベースマイグレーション

```bash
# マイグレーション実行
docker compose -f docker-compose.production.yml exec app php artisan migrate --force

# 確認
docker compose -f docker-compose.production.yml exec app php artisan migrate:status
```

- [ ] マイグレーションが成功
- [ ] エラーがない

### ストレージパーミッションの設定

```bash
# コンテナ内のwww-dataユーザーの権限を設定
sudo chown -R www-data:www-data storage bootstrap/cache public/uploads
sudo chmod -R 775 storage bootstrap/cache public/uploads
```

- [ ] `storage/` が書き込み可能
- [ ] `bootstrap/cache/` が書き込み可能
- [ ] `public/uploads/` が書き込み可能

### アプリケーションの最適化

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

- [ ] キャッシュがクリアされた
- [ ] 本番環境用に最適化された

### Apache/Nginxリバースプロキシ設定

#### Apacheの場合

```bash
# VirtualHost設定ファイルを作成
sudo nano /etc/apache2/sites-available/shin-on_wiki.conf
```

`apache-vhost.conf.example` の**Dockerリバースプロキシ設定**をコピーして貼り付け:

- [ ] `wiki.example.com` を実際のドメインに置換
- [ ] `ProxyPass / http://localhost:8083/` のポート番号が`.env`のAPP_PORTと一致
- [ ] `admin@example.com` を実際のメールアドレスに置換

```bash
# サイトを有効化
sudo a2ensite shin-on_wiki

# 設定をテスト
sudo apache2ctl configtest

# Apacheを再起動
sudo systemctl reload apache2
```

- [ ] サイトが有効化された
- [ ] 設定テストがOK (`Syntax OK`)
- [ ] Apacheが再起動された

#### Nginxの場合

```bash
# サイト設定ファイルを作成
sudo nano /etc/nginx/sites-available/shin-on_wiki
```

`apache-vhost.conf.example` のNginx設定例を参考に設定:

```bash
# シンボリックリンクを作成
sudo ln -s /etc/nginx/sites-available/shin-on_wiki /etc/nginx/sites-enabled/

# 設定をテスト
sudo nginx -t

# Nginxを再起動
sudo systemctl reload nginx
```

- [ ] サイト設定が作成された
- [ ] シンボリックリンクが作成された
- [ ] 設定テストがOK
- [ ] Nginxが再起動された

### SSL証明書のセットアップ

#### Apacheの場合

```bash
sudo certbot --apache -d wiki.example.com
```

#### Nginxの場合

```bash
sudo certbot --nginx -d wiki.example.com
```

プロンプトに従って入力:
- [ ] メールアドレスを入力
- [ ] 利用規約に同意
- [ ] HTTPSリダイレクトを選択 (推奨: Yes)

確認:
- [ ] 証明書が正常にインストールされた
- [ ] HTTPS でアクセス可能
- [ ] HTTP が HTTPS にリダイレクトされる

### 複数アプリケーション運用の場合

2つ目以降のアプリケーションを追加する場合:

1. **別ディレクトリにクローン:**
   ```bash
   cd /var/www
   git clone <app2-repository> app2-name
   cd app2-name
   ```

2. **.env設定:**
   ```bash
   cp .env.production.example .env
   nano .env
   ```
   - `COMPOSE_PROJECT_NAME=app2-name` (重複しないこと)
   - `APP_PORT=8084` (重複しないポート)
   - `APP_URL=https://app2.example.com`

3. **コンテナ起動:**
   ```bash
   docker compose -f docker-compose.production.yml up -d
   ```

4. **Apache/Nginx設定:**
   - 新しいVirtualHostを追加
   - `ProxyPass / http://localhost:8084/`

5. **SSL証明書取得:**
   ```bash
   sudo certbot --apache -d app2.example.com
   ```

- [ ] 複数アプリの設定が完了 (該当する場合)

---

## デプロイ後確認

### Webアクセステスト

ブラウザで `https://your-domain.com` にアクセス:

- [ ] サイトが表示される
- [ ] SSL証明書が有効（鍵マークが表示される）
- [ ] HTTPからHTTPSにリダイレクトされる

### LINE WORKS SSOテスト

1. ログインページにアクセス

2. 「LINE WORKSでログイン」をクリック

3. LINE WORKS認証画面で以下を確認:
   - [ ] LINE WORKS認証画面にリダイレクトされる
   - [ ] ドメイン `shin-on1981` のユーザーでログイン
   - [ ] BookStackにリダイレクトされる
   - [ ] ログインが成功する
   - [ ] ユーザー名が正しく表示される

4. 他のドメインのユーザーで試す:
   - [ ] 他のドメインのユーザーがログインできないことを確認

### Dropboxバックアップテスト

1. 管理者でログイン

2. 設定 > 機能 に移動

3. Dropbox認証:
   - [ ] 「Dropboxと連携」ボタンが表示される
   - [ ] ボタンをクリック
   - [ ] Dropbox認証画面にリダイレクトされる
   - [ ] 認証を完了
   - [ ] BookStackに戻る
   - [ ] 「連携済み」と表示される

4. バックアップテスト:

   **直接デプロイの場合:**
   ```bash
   cd /var/www/shin-on_wiki
   sudo -u www-data php artisan backup:dropbox --test
   ```

   **Dockerデプロイの場合:**
   ```bash
   cd /var/www/shin-on_wiki
   docker compose -f docker-compose.production.yml exec app php artisan backup:test
   ```

   - [ ] テストが成功
   - [ ] すべてのチェックマークが表示される

### 機能テスト

- [ ] 新しいページを作成できる
- [ ] 画像をアップロードできる
- [ ] 画像が正しく表示される
- [ ] PDFエクスポートが動作する
- [ ] 検索機能が動作する

### ログ確認

**直接デプロイの場合:**
```bash
# アプリケーションログ
sudo tail -50 /var/www/shin-on_wiki/storage/logs/laravel.log

# Apacheエラーログ
sudo tail -50 /var/log/apache2/shin-on_wiki-ssl-error.log
```

**Dockerデプロイの場合:**
```bash
# アプリケーションログ (Docker)
docker compose -f docker-compose.production.yml logs --tail=50 app

# Apacheエラーログ (リバースプロキシ)
sudo tail -50 /var/log/apache2/wiki-ssl-error.log
```

- [ ] 重大なエラーがない
- [ ] 警告があれば対処済み

### パフォーマンステスト

ブラウザの開発者ツールで確認:
- [ ] ページ読み込み時間が適切 (< 3秒)
- [ ] 画像が正しく読み込まれる
- [ ] JavaScriptエラーがない
- [ ] CSSが正しく適用されている

---

## 運用開始準備

### 自動バックアップの設定

**直接デプロイの場合:**
```bash
# www-dataユーザーのcrontabを編集
sudo crontab -e -u www-data

# 以下の行を追加 (毎日午前2時にバックアップ)
0 2 * * * cd /var/www/shin-on_wiki && php artisan backup:dropbox >> /dev/null 2>&1
```

**Dockerデプロイの場合:**
```bash
# rootユーザーのcrontabを編集
sudo crontab -e

# 以下の行を追加 (毎日午前3時にバックアップ)
0 3 * * * cd /var/www/shin-on_wiki && docker compose -f docker-compose.production.yml exec -T app php artisan backup:dropbox >> /var/log/backup-shin-on-wiki.log 2>&1
```

- [ ] cronジョブが設定された
- [ ] バックアップ時刻が適切
- [ ] テストバックアップを実行してDropboxに保存されることを確認

### SSL証明書の自動更新確認

```bash
# 更新テスト
sudo certbot renew --dry-run

# 自動更新サービスの確認
sudo systemctl status certbot.timer
```

- [ ] 更新テストが成功
- [ ] 自動更新サービスが有効

### 監視設定（推奨）

- [ ] アップタイム監視を設定 (UptimeRobot, Pingdom等)
- [ ] ディスク容量アラートを設定
- [ ] ログ監視を設定 (Logwatch等)

### セキュリティ設定

```bash
# 自動セキュリティアップデートの有効化
sudo apt install unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades
```

- [ ] 自動セキュリティアップデートが有効化された

### ドキュメント整備

- [ ] 本番環境の設定を記録した
- [ ] パスワードを安全な場所に保管した
- [ ] OAuth認証情報を安全な場所に保管した
- [ ] 運用手順書を作成した (オプション)

---

## 更新デプロイ

アプリケーションを更新する際のチェックリスト:

### デプロイ前確認（共通）

- [ ] 開発環境でテスト済み
- [ ] データベースマイグレーションの内容を確認
- [ ] 破壊的変更がないか確認
- [ ] バックアップが最新であることを確認

### A. 直接デプロイの更新手順

```bash
cd /var/www/shin-on_wiki

# メンテナンスモードに入る
sudo -u www-data php artisan down

# 最新コードを取得
git pull origin main

# 依存関係を更新
composer install --no-dev --optimize-autoloader

# アセットを再ビルド (必要な場合)
npm install
npm run production

# マイグレーション実行 (必要な場合)
php artisan migrate --force

# キャッシュをクリアして最適化
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# メンテナンスモードを解除
sudo -u www-data php artisan up
```

- [ ] メンテナンスモードに入った
- [ ] コードが更新された
- [ ] 依存関係が更新された
- [ ] マイグレーションが実行された (必要な場合)
- [ ] キャッシュが最適化された
- [ ] メンテナンスモードが解除された

### B. Dockerデプロイの更新手順

```bash
cd /var/www/shin-on_wiki

# メンテナンスモードに入る
docker compose -f docker-compose.production.yml exec app php artisan down

# バックアップ（念のため）
docker compose -f docker-compose.production.yml exec app php artisan backup:dropbox

# 最新コードを取得
git pull origin main

# イメージを再ビルド（Dockerfileやcomposer.jsonが変更された場合）
docker compose -f docker-compose.production.yml build

# コンテナを再起動
docker compose -f docker-compose.production.yml up -d

# マイグレーション実行 (必要な場合)
docker compose -f docker-compose.production.yml exec app php artisan migrate --force

# キャッシュをクリアして最適化
docker compose -f docker-compose.production.yml exec app php artisan cache:clear
docker compose -f docker-compose.production.yml exec app php artisan config:cache
docker compose -f docker-compose.production.yml exec app php artisan route:cache
docker compose -f docker-compose.production.yml exec app php artisan view:cache

# メンテナンスモードを解除
docker compose -f docker-compose.production.yml exec app php artisan up
```

- [ ] メンテナンスモードに入った
- [ ] バックアップが作成された
- [ ] コードが更新された
- [ ] イメージが再ビルドされた (必要な場合)
- [ ] コンテナが再起動された
- [ ] マイグレーションが実行された (必要な場合)
- [ ] キャッシュが最適化された
- [ ] メンテナンスモードが解除された

### デプロイ後確認（共通）

- [ ] サイトが正常に表示される
- [ ] LINE WORKSログインが動作する
- [ ] 既存の機能が正常に動作する
- [ ] 新機能が正常に動作する
- [ ] ログにエラーがない

**Dockerデプロイの追加確認:**
- [ ] コンテナが正常に動作している (`docker compose ps`)
- [ ] コンテナログにエラーがない (`docker compose logs`)

---

## トラブルシューティング

### 一般的な問題

**直接デプロイの場合:**

1. **ログを確認**
   ```bash
   sudo tail -100 /var/www/shin-on_wiki/storage/logs/laravel.log
   sudo tail -100 /var/log/apache2/shin-on_wiki-ssl-error.log
   ```

2. **パーミッションを確認**
   ```bash
   ls -la /var/www/shin-on_wiki/storage
   ls -la /var/www/shin-on_wiki/bootstrap/cache
   ```

3. **キャッシュをクリア**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   ```

4. **データベース接続を確認**
   ```bash
   php artisan db:show
   ```

5. **Apache設定を確認**
   ```bash
   sudo apache2ctl configtest
   ```

**Dockerデプロイの場合:**

1. **コンテナ状態を確認**
   ```bash
   docker compose -f docker-compose.production.yml ps
   docker compose -f docker-compose.production.yml logs app
   docker compose -f docker-compose.production.yml logs db
   ```

2. **ログを確認**
   ```bash
   # アプリケーションログ
   docker compose -f docker-compose.production.yml logs --tail=100 app

   # Apacheリバースプロキシログ
   sudo tail -100 /var/log/apache2/wiki-ssl-error.log
   ```

3. **コンテナのリソース確認**
   ```bash
   docker stats
   ```

4. **キャッシュをクリア**
   ```bash
   docker compose -f docker-compose.production.yml exec app php artisan cache:clear
   docker compose -f docker-compose.production.yml exec app php artisan config:clear
   docker compose -f docker-compose.production.yml exec app php artisan route:clear
   docker compose -f docker-compose.production.yml exec app php artisan view:clear
   ```

5. **データベース接続を確認**
   ```bash
   docker compose -f docker-compose.production.yml exec app php artisan db:show
   ```

6. **コンテナを再起動**
   ```bash
   docker compose -f docker-compose.production.yml restart app
   docker compose -f docker-compose.production.yml restart db
   ```

7. **Apache/Nginx設定を確認**
   ```bash
   sudo apache2ctl configtest  # Apache
   sudo nginx -t               # Nginx
   ```

### ロールバック手順

問題が解決しない場合、バックアップからロールバック:

1. 最新のバックアップを確認:
   ```bash
   ls -lh /var/www/shin-on_wiki/deployments/
   ```

2. ロールバックスクリプトを実行:
   ```bash
   cd /var/www/shin-on_wiki
   ./deploy.sh --rollback /var/www/shin-on_wiki/deployments/YYYYMMDD_HHMMSS
   ```

---

## 完了

すべてのチェックリスト項目が完了したら、デプロイ成功です！

### 次のステップ

- [ ] チーム全員に本番環境URLを共有
- [ ] ユーザーにLINE WORKS SSOでのログイン方法を案内
- [ ] 定期的なバックアップを確認
- [ ] 監視ダッシュボードを確認
- [ ] 運用ドキュメントを更新

---

**最終更新**: 2025年11月17日
**作成者**: Claude Code + satoshi
