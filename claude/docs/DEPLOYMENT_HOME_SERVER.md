# 自宅サーバーでのDocker公開ガイド

このドキュメントでは、自宅サーバーでshin-on_wikiをDocker環境で公開し、GitHubからの自動デプロイを実現する手順を説明します。

## 📋 目次

1. [概要](#概要)
2. [前提条件](#前提条件)
3. [サーバー環境構築](#サーバー環境構築)
4. [ネットワーク設定](#ネットワーク設定)
5. [GitHubリポジトリ設定](#githubリポジトリ設定)
6. [プロジェクトデプロイ](#プロジェクトデプロイ)
7. [SSL証明書設定](#ssl証明書設定)
8. [LINE WORKS & Dropbox設定更新](#line-works--dropbox設定更新)
9. [自動デプロイの仕組み](#自動デプロイの仕組み)
10. [運用・メンテナンス](#運用メンテナンス)
11. [トラブルシューティング](#トラブルシューティング)
12. [セキュリティチェックリスト](#セキュリティチェックリスト)

---

## 概要

### 構成図

```
┌─────────────────┐
│  インターネット  │
└────────┬────────┘
         │
    ┌────▼────────────────────────────┐
    │ ルーター（ポートフォワーディング）│
    │  80:80, 443:443                 │
    └────┬────────────────────────────┘
         │
    ┌────▼──────────────────┐
    │  自宅サーバー          │
    │  (Ubuntu/Debian)      │
    │                       │
    │  ┌─────────────────┐  │
    │  │ Apache          │  │
    │  │ (SSL終端)       │  │
    │  │ Port 80, 443   │  │
    │  └────┬────────────┘  │
    │       │ Reverse Proxy │
    │  ┌────▼────────────┐  │
    │  │ Docker          │  │
    │  │ ┌─────────────┐ │  │
    │  │ │ App:8083    │ │  │
    │  │ └─────────────┘ │  │
    │  │ ┌─────────────┐ │  │
    │  │ │ MySQL:3308  │ │  │
    │  │ └─────────────┘ │  │
    │  └─────────────────┘  │
    └───────────────────────┘
         ▲
         │
    ┌────┴──────────────┐
    │ GitHub Actions    │
    │ (SSH経由デプロイ) │
    └───────────────────┘
```

### デプロイフロー

```
開発環境 (Mac)
    │
    │ git push
    ▼
GitHub リポジトリ
    │
    │ GitHub Actions 起動
    ▼
自動デプロイスクリプト実行
    │
    ├─ SSH接続
    ├─ git pull
    ├─ Dockerコンテナ再構築
    ├─ データベースマイグレーション
    └─ キャッシュクリア
    │
    ▼
本番環境稼働
```

---

## 前提条件

### ハードウェア要件

- **CPU**: 2コア以上推奨
- **メモリ**: 4GB以上推奨（最小2GB）
- **ストレージ**: 20GB以上の空き容量
- **ネットワーク**: 固定グローバルIPまたはDDNS対応可能な環境

### ソフトウェア要件

- **OS**: Ubuntu 20.04 LTS以降 または Debian 11以降
- **Docker**: 20.10以降
- **Docker Compose**: v2.0以降
- **Apache**: 2.4以降
- **Git**: 2.25以降
- **Certbot**: 1.0以降

### 必要な知識

- 基本的なLinuxコマンド操作
- Docker/Docker Composeの基礎知識
- Apacheの基本的な設定
- SSHの基礎知識
- GitHubの基本的な使い方

---

## サーバー環境構築

### 3.1 基本環境セットアップ

#### システムアップデート

```bash
# パッケージリストを更新
sudo apt update

# インストール済みパッケージをアップグレード
sudo apt upgrade -y

# 再起動が必要な場合
sudo reboot
```

#### Docker & Docker Composeインストール

```bash
# 古いバージョンがある場合は削除
sudo apt remove docker docker-engine docker.io containerd runc

# 必要なパッケージをインストール
sudo apt install -y \
    ca-certificates \
    curl \
    gnupg \
    lsb-release

# Docker公式GPGキーを追加
sudo mkdir -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg

# Dockerリポジトリを追加
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Dockerをインストール
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Dockerサービスを起動・自動起動設定
sudo systemctl start docker
sudo systemctl enable docker

# 現在のユーザーをdockerグループに追加（sudoなしでdockerコマンド実行可能に）
sudo usermod -aG docker $USER

# グループ変更を反映（ログアウト→ログインでも可）
newgrp docker

# インストール確認
docker --version
docker compose version
```

#### Apacheインストール

```bash
# Apacheをインストール
sudo apt install -y apache2

# 必要なモジュールを有効化
sudo a2enmod proxy
sudo a2enmod proxy_http
sudo a2enmod headers
sudo a2enmod ssl
sudo a2enmod rewrite

# Apacheサービスを起動・自動起動設定
sudo systemctl start apache2
sudo systemctl enable apache2

# インストール確認
apache2 -v
```

#### Gitインストール

```bash
# Gitをインストール
sudo apt install -y git

# インストール確認
git --version
```

#### その他必要なパッケージ

```bash
# Certbot（Let's Encrypt）とApacheプラグイン
sudo apt install -y certbot python3-certbot-apache

# fail2ban（セキュリティ対策）
sudo apt install -y fail2ban

# UFW（ファイアウォール）
sudo apt install -y ufw

# cron（定期実行タスク用）- 通常はプリインストール済み
sudo apt install -y cron

# curl, wget（ダウンロード用）
sudo apt install -y curl wget

# htop（システム監視用）
sudo apt install -y htop
```

---

### 3.2 セキュリティ設定

#### ファイアウォール設定（UFW）

```bash
# UFWをインストール（まだの場合）
sudo apt install -y ufw

# デフォルトポリシー設定（受信拒否、送信許可）
sudo ufw default deny incoming
sudo ufw default allow outgoing

# SSH接続を許可（必ず先に設定！）
sudo ufw allow 22/tcp

# HTTP/HTTPS接続を許可
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# ルール確認
sudo ufw show added

# UFWを有効化（SSH接続が切れないことを確認してから実行）
sudo ufw enable

# ステータス確認
sudo ufw status verbose
```

⚠️ **重要**: UFWを有効化する前に、必ずSSHポート（22）を許可してください。許可せずに有効化すると、SSH接続ができなくなる可能性があります。

#### SSH設定（鍵認証）

```bash
# SSH設定ファイルを編集
sudo nano /etc/ssh/sshd_config
```

以下の設定を確認・変更:

```bash
# ポート変更（オプション：デフォルトポートを変更する場合）
# Port 22222

# パスワード認証を無効化（鍵認証のみ許可）
PasswordAuthentication no

# 公開鍵認証を有効化
PubkeyAuthentication yes

# rootログインを無効化
PermitRootLogin no

# 空のパスワードを無効化
PermitEmptyPasswords no
```

設定反映:

```bash
# SSH設定の構文チェック
sudo sshd -t

# SSHサービスを再起動
sudo systemctl restart ssh
```

⚠️ **重要**: パスワード認証を無効化する前に、必ず公開鍵認証でログインできることを別のターミナルで確認してください。

#### SSH公開鍵の登録（クライアント側で実施）

```bash
# 開発環境（Mac）でSSH鍵ペアを生成
ssh-keygen -t ed25519 -C "your_email@example.com" -f ~/.ssh/id_ed25519_homeserver

# 公開鍵をサーバーに転送
ssh-copy-id -i ~/.ssh/id_ed25519_homeserver.pub username@your-server-ip

# 接続テスト
ssh -i ~/.ssh/id_ed25519_homeserver username@your-server-ip
```

#### fail2banインストール・設定

```bash
# fail2banをインストール
sudo apt install -y fail2ban

# 設定ファイルをコピー
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local

# 設定ファイルを編集
sudo nano /etc/fail2ban/jail.local
```

最小限の設定例:

```ini
[DEFAULT]
# 禁止時間（秒）
bantime  = 3600

# 監視時間（秒）
findtime  = 600

# 最大試行回数
maxretry = 5

[sshd]
enabled = true
port = 22
logpath = /var/log/auth.log
```

設定反映:

```bash
# fail2banサービスを起動・自動起動設定
sudo systemctl start fail2ban
sudo systemctl enable fail2ban

# ステータス確認
sudo fail2ban-client status
sudo fail2ban-client status sshd
```

---

## ネットワーク設定

### 4.1 MyDNS.JP設定

#### アカウント作成

1. [MyDNS.JP](https://www.mydns.jp/)にアクセス
2. 「新規ユーザー登録」をクリック
3. メールアドレス、パスワードを入力して登録
4. 登録確認メールが届いたら、リンクをクリックして認証

#### DDNSホスト名登録

1. MyDNS.JPにログイン
2. 「DOMAIN INFO」→「新規ドメイン登録」
3. 希望するホスト名を入力（例: `shin-on-wiki.mydns.jp`）
4. IPアドレスは自動検出されるのでそのまま登録

#### 自動更新スクリプト設定

MyDNS.JPは、定期的にIPアドレスを報告する必要があります。

```bash
# 更新スクリプトを作成
sudo nano /usr/local/bin/mydns-update.sh
```

スクリプト内容:

```bash
#!/bin/bash

# MyDNS.JPの認証情報
MYDNS_ID="your-master-id"
MYDNS_PASSWORD="your-password"

# IPアドレスを更新
curl -u "${MYDNS_ID}:${MYDNS_PASSWORD}" https://www.mydns.jp/login.html

# ログに記録（オプション）
echo "$(date): MyDNS IP updated" >> /var/log/mydns-update.log
```

実行権限を付与:

```bash
sudo chmod +x /usr/local/bin/mydns-update.sh
```

Cronで定期実行:

```bash
# Crontabを編集
crontab -e
```

以下を追加（10分ごとに実行）:

```cron
*/10 * * * * /usr/local/bin/mydns-update.sh
```

動作確認:

```bash
# 手動実行してテスト
/usr/local/bin/mydns-update.sh

# ログ確認
cat /var/log/mydns-update.log
```

---

### 4.2 ルーター設定

#### ポートフォワーディング

自宅ルーターの管理画面にアクセスし、以下のポートフォワーディングを設定します。

| プロトコル | 外部ポート | 内部IPアドレス | 内部ポート | 説明 |
|----------|-----------|--------------|-----------|------|
| TCP | 80 | サーバーのローカルIP | 80 | HTTP |
| TCP | 443 | サーバーのローカルIP | 443 | HTTPS |
| TCP | 22 | サーバーのローカルIP | 22 | SSH（オプション）|

⚠️ **セキュリティ上の注意**:
- SSHポートを外部公開する場合は、強力な鍵認証とfail2banの設定を必ず行ってください
- 可能であればSSHポートは変更し、VPN経由でのアクセスを推奨します

#### サーバーの固定ローカルIP設定

Netplanを使用して固定IPを設定（Ubuntu 18.04以降）:

```bash
# 現在のネットワーク設定を確認
ip addr

# Netplan設定ファイルを編集
sudo nano /etc/netplan/00-installer-config.yaml
```

設定例:

```yaml
network:
  version: 2
  ethernets:
    enp0s3:  # ネットワークインターフェース名（環境により異なる）
      dhcp4: no
      addresses:
        - 192.168.1.100/24  # 固定IPアドレス/サブネットマスク
      gateway4: 192.168.1.1  # デフォルトゲートウェイ（ルーターのIP）
      nameservers:
        addresses:
          - 8.8.8.8  # Google DNS
          - 8.8.4.4
```

設定適用:

```bash
# 設定の構文チェック
sudo netplan try

# 問題なければ適用
sudo netplan apply

# 設定確認
ip addr
```

---

## GitHubリポジトリ設定

### 5.1 デプロイキー作成

#### サーバー側でSSH鍵ペア生成

```bash
# デプロイ用のSSH鍵を生成（パスフレーズなし）
ssh-keygen -t ed25519 -C "deploy@shin-on-wiki" -f ~/.ssh/id_ed25519_deploy -N ""

# 公開鍵の内容を表示（GitHubに登録するため）
cat ~/.ssh/id_ed25519_deploy.pub
```

#### GitHubへの公開鍵登録

1. GitHubリポジトリにアクセス: https://github.com/satoshi-tateishi/shin-on_wiki
2. 「Settings」→「Deploy keys」をクリック
3. 「Add deploy key」をクリック
4. 以下を入力:
   - **Title**: `Home Server Deploy Key`
   - **Key**: 上記で表示された公開鍵の内容をペースト
   - **Allow write access**: チェックを入れない（読み取り専用）
5. 「Add key」をクリック

#### 接続テスト

```bash
# GitHub接続テスト
ssh -T git@github.com -i ~/.ssh/id_ed25519_deploy

# 成功すると以下のようなメッセージが表示される
# Hi satoshi-tateishi/shin-on_wiki! You've successfully authenticated, but GitHub does not provide shell access.
```

---

### 5.2 GitHub Actions設定

GitHub Actionsを使用して、GitHubへのpush時に自動的に本番サーバーにデプロイします。

#### Secretsの設定

1. GitHubリポジトリの「Settings」→「Secrets and variables」→「Actions」をクリック
2. 「New repository secret」をクリックし、以下のSecretを追加:

| Secret名 | 値 | 説明 |
|---------|---|------|
| `DEPLOY_HOST` | `shin-on-wiki.mydns.jp` | サーバーのホスト名またはIPアドレス |
| `DEPLOY_USER` | `your-username` | サーバーのユーザー名 |
| `DEPLOY_KEY` | サーバーで生成した秘密鍵の内容 | SSH秘密鍵（`~/.ssh/id_ed25519_deploy`） |
| `DEPLOY_PATH` | `/var/www/shin-on_wiki` | サーバー上のプロジェクトパス |

**DEPLOY_KEYの取得方法**:

```bash
# サーバーで秘密鍵の内容を表示
cat ~/.ssh/id_ed25519_deploy
```

表示された内容（`-----BEGIN OPENSSH PRIVATE KEY-----`から`-----END OPENSSH PRIVATE KEY-----`まで）をすべてコピーし、GitHubのSecretに貼り付けます。

⚠️ **セキュリティ上の注意**:
- 秘密鍵は絶対に公開しないでください
- GitHub Secretsは暗号化されて保存されます
- 秘密鍵が漏洩した場合は直ちに無効化し、新しい鍵を生成してください

#### ワークフローファイル作成

プロジェクトルートに`.github/workflows/deploy.yml`を作成します。

```yaml
name: Deploy to Home Server

on:
  push:
    branches:
      - release  # releaseブランチへのpushでトリガー

jobs:
  deploy:
    name: Deploy to Production
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Deploy to server
        uses: appleboy/ssh-action@v1.0.0
        with:
          host: ${{ secrets.DEPLOY_HOST }}
          username: ${{ secrets.DEPLOY_USER }}
          key: ${{ secrets.DEPLOY_KEY }}
          script: |
            cd ${{ secrets.DEPLOY_PATH }}

            # 最新のコードを取得
            git fetch origin
            git reset --hard origin/release

            # 依存関係の更新
            docker compose -f docker-compose.production.yml exec -T app composer install --no-dev --optimize-autoloader
            docker compose -f docker-compose.production.yml exec -T app npm ci --omit=dev

            # アセットのビルド
            docker compose -f docker-compose.production.yml exec -T app npm run production

            # データベースマイグレーション
            docker compose -f docker-compose.production.yml exec -T app php artisan migrate --force

            # キャッシュクリア
            docker compose -f docker-compose.production.yml exec -T app php artisan config:clear
            docker compose -f docker-compose.production.yml exec -T app php artisan cache:clear
            docker compose -f docker-compose.production.yml exec -T app php artisan route:clear
            docker compose -f docker-compose.production.yml exec -T app php artisan view:clear

            # キャッシュ最適化
            docker compose -f docker-compose.production.yml exec -T app php artisan config:cache
            docker compose -f docker-compose.production.yml exec -T app php artisan route:cache
            docker compose -f docker-compose.production.yml exec -T app php artisan view:cache

            # コンテナの再起動（必要な場合）
            # docker compose -f docker-compose.production.yml restart app

            echo "Deployment completed successfully!"
```

このワークフローファイルを開発環境で作成し、GitHubにpushします。

```bash
# 開発環境（Mac）で実行
cd /Users/satoshi/Laravel/shin-on_wiki

# ディレクトリ作成
mkdir -p .github/workflows

# ファイル作成（上記の内容をコピー）
nano .github/workflows/deploy.yml

# GitHubにpush
git add .github/workflows/deploy.yml
git commit -m "Add GitHub Actions deployment workflow"
git push origin release
```

---

## プロジェクトデプロイ

### 6.1 初回デプロイ

初回デプロイには、**自動化スクリプト**を使用する方法と、**手動**で行う方法があります。

#### 🚀 オプションA: 自動化スクリプトを使用（推奨）

初回デプロイを自動化するスクリプトが用意されています。このスクリプトは以下の全ての手順を自動的に実行します。

**前提条件**:
- PHP 8.3以上、Composer、Node.js 20.x以上がホスト側にインストール済み
- プロジェクトがクローン済み
- `.env`ファイルが設定済み

**実行手順**:

```bash
# 1. プロジェクトディレクトリに移動
cd /var/www/shin-on_wiki

# 2. .envファイルを設定（まだの場合）
cp .env.production.example .env
nano .env  # APP_URL、DB_PASSWORD等を設定

# 3. 自動化スクリプトを実行
bash scripts/initial-deploy.sh
```

スクリプトは以下を自動実行します:
- ✅ 依存関係の確認（PHP, Composer, Node.js, NPM）
- ✅ .env ファイルの検証と修正（DB_HOST、APP_KEY）
- ✅ パーミッション設定
- ✅ Composer/NPM 依存関係のインストール
- ✅ アセットのビルド
- ✅ ストレージリンク作成
- ✅ Docker イメージのビルドとコンテナ起動
- ✅ データベースマイグレーション
- ✅ キャッシュ最適化
- ✅ 動作確認

> **💡 メリット**
> - 時間短縮: 30以上の手動ステップが1コマンドに
> - エラー防止: 各ステップで自動検証
> - 冪等性: 何度実行しても安全

詳細は [`scripts/README.md`](../../scripts/README.md) を参照してください。

---

#### 📝 オプションB: 手動デプロイ

自動化スクリプトを使わず、手動で各ステップを実行する場合は、以下の手順に従ってください。

##### プロジェクトディレクトリの作成

```bash
# プロジェクトを配置するディレクトリを作成
sudo mkdir -p /var/www
sudo chown $USER:$USER /var/www
cd /var/www
```

##### リポジトリのクローン

```bash
# SSH経由でクローン（デプロイキーを使用）
GIT_SSH_COMMAND='ssh -i ~/.ssh/id_ed25519_deploy' git clone git@github.com:satoshi-tateishi/shin-on_wiki.git

# プロジェクトディレクトリに移動
cd shin-on_wiki

# releaseブランチに切り替え
git checkout release
```

##### .env設定

```bash
# 本番環境用の.envファイルをコピー
cp .env.production.example .env

# .envファイルを編集
nano .env
```

**必須設定項目**:

```bash
# アプリケーション設定
APP_ENV=production
APP_DEBUG=false
APP_URL=https://shin-on-wiki.mydns.jp  # あなたのドメイン名

# アプリケーションキー（後で生成）
APP_KEY=

# Docker設定
APP_PORT=8083
FORWARD_DB_PORT=3308

# データベース設定
DB_HOST=db     # Docker Composeのサービス名（docker-compose.production.ymlのサービス名）
DB_PORT=3306   # コンテナ内部のポート
DB_DATABASE=shin_on_wiki
DB_USERNAME=bookstack
DB_PASSWORD=強力なパスワードを設定  # 必ず変更！

# LINE WORKS OIDC設定
AUTH_METHOD=oidc
OIDC_NAME="LINE WORKS"
OIDC_CLIENT_ID=your_lineworks_client_id  # LINE WORKS Developer Consoleから取得
OIDC_CLIENT_SECRET=your_lineworks_client_secret  # LINE WORKS Developer Consoleから取得
OIDC_ISSUER=https://auth.worksmobile.com
LINEWORKS_DOMAIN=shin-on1981  # あなたのLINE WORKSドメイン

# Dropbox Backup設定
DROPBOX_CLIENT_ID=your_dropbox_app_key  # Dropbox App Consoleから取得
DROPBOX_CLIENT_SECRET=your_dropbox_app_secret  # Dropbox App Consoleから取得
DROPBOX_BACKUP_FOLDER=/shin-on_wiki-backup
BACKUP_RETENTION_DAYS=30

# メール設定（必要に応じて）
MAIL_DRIVER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_FROM=noreply@shin-on-wiki.mydns.jp
MAIL_FROM_NAME="Shin-on Wiki"
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password

# セッション設定
SESSION_LIFETIME=120
SESSION_DRIVER=file

# キャッシュ設定
CACHE_DRIVER=file
QUEUE_CONNECTION=sync

# ログ設定
LOG_CHANNEL=stack
LOG_LEVEL=warning
```

##### パーミッション設定

```bash
# .envファイルのパーミッションを制限
chmod 600 .env

# ストレージディレクトリの初期パーミッション設定（ホスト側での作業用）
sudo chown -R $USER:$USER storage bootstrap/cache
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# public/uploadsのパーミッション設定
sudo chown -R $USER:$USER public/uploads
chmod -R 775 public/uploads
```

##### ホスト側の依存関係インストール

本番環境では、`docker-compose.production.yml`がアプリケーションディレクトリを読み取り専用（`:ro`）でマウントするため、**依存関係をホスト側で事前にインストール**する必要があります。

> **📝 重要な設計変更**
> `docker-compose.production.yml`では、セキュリティ強化のためアプリケーションコードを読み取り専用でマウントしています。ただし、`vendor`と`node_modules`ディレクトリは個別に読み書き可能でマウントされるため、ホスト側で事前にインストールした依存関係がコンテナ内でも利用できます。
>
> これにより、以下のメリットがあります:
> - コンテナ内でのファイル変更を最小限に抑える
> - デプロイ時のビルド時間短縮（依存関係は事前にインストール済み）
> - セキュリティリスクの低減

###### PHP と Composer のインストール

```bash
# PHP 8.3とLaravel必須拡張機能をインストール
sudo apt update
sudo apt install -y php8.3-cli php8.3-fpm php8.3-mysql php8.3-xml php8.3-mbstring \
                    php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl php8.3-redis

# PHPバージョン確認
php --version

# Composerをインストール
cd ~
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Composerバージョン確認
composer --version
```

###### Composer 依存関係のインストール

```bash
# プロジェクトディレクトリに移動
cd /var/www/shin-on_wiki

# 本番環境用の依存関係をインストール（開発用パッケージは除外）
composer install --no-dev --optimize-autoloader

# vendor ディレクトリが作成されたことを確認
ls -la vendor/
```

###### Node.js と NPM のインストール

```bash
# Node.js 20.x をインストール
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# バージョン確認
node --version
npm --version
```

###### NPM 依存関係のインストールとアセットビルド

```bash
# NPMの依存関係をインストール（開発用も含む - ビルドツールが必要）
npm ci

# アセットをビルド（本番環境用）
npm run production

# ビルド後、開発用依存関係を削除（ディスク容量節約）
npm prune --omit=dev

# node_modules と public/dist ディレクトリが作成されたことを確認
ls -la node_modules/
ls -la public/dist/
```

> **💡 ビルドに関する注意**
> `npm run production`を実行するには、`npm-run-all`、`sass`、`esbuild`などのビルドツールが必要です。これらは`devDependencies`に含まれているため、`npm ci`で開発用依存関係も含めてインストールします。ビルド完了後、`npm prune --omit=dev`で開発用依存関係を削除することで、ディスク容量を節約できます。

##### Docker Composeビルド・起動

```bash
# 本番環境用のDocker Composeファイルを使用してビルド
docker compose -f docker-compose.production.yml build

# コンテナを起動（バックグラウンド実行）
docker compose -f docker-compose.production.yml up -d

# コンテナの起動確認
docker compose -f docker-compose.production.yml ps

# ログ確認
docker compose -f docker-compose.production.yml logs -f app
```

##### アプリケーションキー生成

```bash
# APP_KEYを生成（ホスト側で実行）
php artisan key:generate

# .envファイルにAPP_KEYが自動的に設定されます
```

> **⚠️ 重要**
> `.env`ファイルは読み取り専用マウントのため、コンテナ内から書き込みできません。ホスト側でコマンドを実行してください。

##### データベースマイグレーション

```bash
# データベースマイグレーションを実行（コンテナ内で実行）
docker compose -f docker-compose.production.yml exec app php artisan migrate --force

# 初期データのシード（必要に応じて）
# docker compose -f docker-compose.production.yml exec app php artisan db:seed --force
```

##### ストレージリンク作成

```bash
# publicディレクトリにストレージへのシンボリックリンクを作成（ホスト側で実行）
php artisan storage:link
```

> **⚠️ 重要**
> `public`ディレクトリも読み取り専用マウントのため、シンボリックリンクの作成はホスト側で実行してください。

##### Dockerコンテナ用のパーミッション設定

コンテナを起動すると、Apache（www-data, UID=33）がストレージディレクトリに書き込む必要があります。以下のコマンドでパーミッションを修正します。

```bash
# Dockerコンテナ内のApache（www-data）ユーザー用にパーミッション設定
sudo chown -R 33:33 storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# コンテナを再起動してパーミッション変更を反映
docker compose -f docker-compose.production.yml restart app
```

> **⚠️ 重要**
> この設定により、コンテナ内のwww-dataユーザー（UID=33）がログやキャッシュファイルを書き込めるようになります。この手順を忘れると、HTTP 500エラーが発生します。

##### キャッシュ最適化

```bash
# キャッシュクリア（コンテナ内で実行）
docker compose -f docker-compose.production.yml exec app php artisan config:clear
docker compose -f docker-compose.production.yml exec app php artisan cache:clear
docker compose -f docker-compose.production.yml exec app php artisan route:clear
docker compose -f docker-compose.production.yml exec app php artisan view:clear

# キャッシュ最適化（コンテナ内で実行）
docker compose -f docker-compose.production.yml exec app php artisan config:cache
docker compose -f docker-compose.production.yml exec app php artisan route:cache
docker compose -f docker-compose.production.yml exec app php artisan view:cache
```

##### 動作確認

```bash
# コンテナ内部からアクセステスト
curl -I http://localhost:8083

# HTTP 302 Found（ログインページへのリダイレクト）が返ってくればOK
```

##### APP_DEBUGを本番モードに戻す

動作確認が完了したら、デバッグモードを無効にします。

```bash
# APP_DEBUGをfalseに変更
sed -i 's/APP_DEBUG=true/APP_DEBUG=false/' .env

# コンテナを再起動
docker compose -f docker-compose.production.yml restart app
```

---

### 6.2 Apache設定

#### VirtualHost設定ファイルの作成

プロジェクトに既に用意されている`apache-vhost.conf.example`を使用します。

```bash
# 設定ファイルをApacheのsites-availableにコピー
sudo cp /var/www/shin-on_wiki/apache-vhost.conf.example /etc/apache2/sites-available/shin-on_wiki.conf

# 設定ファイルを編集
sudo nano /etc/apache2/sites-available/shin-on_wiki.conf
```

**編集内容**:

`apache-vhost.conf.example`には2つの設定パターンがあります。**パターンB（Dockerリバースプロキシ）** を使用します。

以下の箇所を編集:

```apache
<VirtualHost *:443>
    ServerName shin-on-wiki.mydns.jp  # あなたのドメイン名に変更
    ServerAlias www.shin-on-wiki.mydns.jp  # 必要に応じて

    # SSL証明書（Let's Encryptで取得後に自動設定される）
    # SSLCertificateFile /etc/letsencrypt/live/shin-on-wiki.mydns.jp/fullchain.pem
    # SSLCertificateKeyFile /etc/letsencrypt/live/shin-on-wiki.mydns.jp/privkey.pem

    # プロキシ設定
    ProxyPreserveHost On
    ProxyPass / http://localhost:8083/
    ProxyPassReverse / http://localhost:8083/

    # セキュリティヘッダー（既に設定済み）
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"

    # ログファイル
    ErrorLog ${APACHE_LOG_DIR}/wiki-ssl-error.log
    CustomLog ${APACHE_LOG_DIR}/wiki-ssl-access.log combined
</VirtualHost>

# HTTP → HTTPS リダイレクト
<VirtualHost *:80>
    ServerName shin-on-wiki.mydns.jp  # あなたのドメイン名に変更
    ServerAlias www.shin-on-wiki.mydns.jp  # 必要に応じて

    # Let's Encrypt証明書取得用（/.well-known/acme-challenge/は通す）
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/.well-known/acme-challenge/
    RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [R=301,L]

    ErrorLog ${APACHE_LOG_DIR}/wiki-error.log
    CustomLog ${APACHE_LOG_DIR}/wiki-access.log combined
</VirtualHost>
```

#### サイトの有効化

```bash
# デフォルトサイトを無効化（オプション）
sudo a2dissite 000-default.conf

# shin-on_wikiサイトを有効化
sudo a2ensite shin-on_wiki.conf

# 設定の構文チェック
sudo apache2ctl configtest

# 出力: Syntax OK であればOK

# Apacheを再起動
sudo systemctl reload apache2
```

#### 設定確認

```bash
# 有効なサイトを確認
ls -l /etc/apache2/sites-enabled/

# Apacheのステータス確認
sudo systemctl status apache2
```

---

## SSL証明書設定

### 7.1 Certbotインストール

Certbotは「サーバー環境構築」の段階で既にインストール済みです。未インストールの場合:

```bash
sudo apt install -y certbot python3-certbot-apache
```

---

### 7.2 証明書取得

#### Let's Encrypt証明書の取得

```bash
# Certbotを使用してSSL証明書を取得（Apache設定も自動更新）
sudo certbot --apache -d shin-on-wiki.mydns.jp

# サブドメインも含める場合
# sudo certbot --apache -d shin-on-wiki.mydns.jp -d www.shin-on-wiki.mydns.jp
```

**対話形式のプロンプトに回答**:

1. **メールアドレス入力**: 証明書の有効期限通知用メールアドレス
2. **利用規約への同意**: `Yes`
3. **メールマガジンの購読**: `No`（任意）
4. **HTTPSリダイレクト**: `2` (すべてのHTTPリクエストをHTTPSにリダイレクト)

証明書の取得が成功すると、以下のように表示されます:

```
Successfully received certificate.
Certificate is saved at: /etc/letsencrypt/live/shin-on-wiki.mydns.jp/fullchain.pem
Key is saved at:         /etc/letsencrypt/live/shin-on-wiki.mydns.jp/privkey.pem
```

#### 証明書の確認

```bash
# 証明書の有効期限を確認
sudo certbot certificates

# SSL証明書の詳細を確認
sudo openssl x509 -in /etc/letsencrypt/live/shin-on-wiki.mydns.jp/fullchain.pem -text -noout
```

#### 自動更新の設定確認

Certbotは自動的に証明書の更新を行うタイマーを設定します。

```bash
# 自動更新タイマーの状態を確認
sudo systemctl status certbot.timer

# 自動更新のテスト（実際には更新しない）
sudo certbot renew --dry-run
```

`--dry-run`が成功すれば、自動更新も正常に動作します。

#### Apache設定の確認

Certbotが自動的にApache設定ファイルを更新します。確認してみましょう:

```bash
# 設定ファイルを確認
sudo nano /etc/apache2/sites-available/shin-on_wiki.conf
```

以下のような記述が追加されています:

```apache
SSLCertificateFile /etc/letsencrypt/live/shin-on-wiki.mydns.jp/fullchain.pem
SSLCertificateKeyFile /etc/letsencrypt/live/shin-on-wiki.mydns.jp/privkey.pem
Include /etc/letsencrypt/options-ssl-apache.conf
```

---

### 7.3 セキュリティヘッダー設定

`apache-vhost.conf.example`には既にセキュリティヘッダーが設定されていますが、追加で設定を強化することもできます。

```bash
# 設定ファイルを編集
sudo nano /etc/apache2/sites-available/shin-on_wiki.conf
```

以下を`<VirtualHost *:443>`内に追加（既に設定されている場合は不要）:

```apache
# セキュリティヘッダー
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"

# Content Security Policy（必要に応じて調整）
# Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self';"
```

設定を反映:

```bash
# 構文チェック
sudo apache2ctl configtest

# Apacheを再起動
sudo systemctl reload apache2
```

#### セキュリティヘッダーの確認

```bash
# HTTPSでアクセスしてヘッダーを確認
curl -I https://shin-on-wiki.mydns.jp

# 以下のヘッダーが含まれていることを確認
# Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
# X-Frame-Options: SAMEORIGIN
# X-Content-Type-Options: nosniff
```

---

## LINE WORKS & Dropbox設定更新

本番環境のドメインに合わせて、LINE WORKSとDropboxのOAuth設定を更新します。

### 8.1 LINE WORKS設定

#### LINE WORKS Developer Console

1. [LINE WORKS Developer Console](https://developers.worksmobile.com/)にアクセス
2. ログイン後、該当するアプリを選択
3. 「OAuth 2.0」セクションを開く
4. 「リダイレクトURI」に以下を**追加**:

```
https://shin-on-wiki.mydns.jp/oidc/callback
```

⚠️ **注意**: 既存の開発環境用URI（`https://localhost:8443/oidc/callback`）は削除せず、追加してください。

5. 「保存」をクリック

#### .envファイルの確認

サーバー上の`.env`ファイルで、LINE WORKS設定が正しいことを確認:

```bash
cd /var/www/shin-on_wiki
nano .env
```

```bash
AUTH_METHOD=oidc
OIDC_NAME="LINE WORKS"
OIDC_CLIENT_ID=your_lineworks_client_id
OIDC_CLIENT_SECRET=your_lineworks_client_secret
OIDC_ISSUER=https://auth.worksmobile.com
LINEWORKS_DOMAIN=shin-on1981
```

---

### 8.2 Dropbox設定

#### Dropbox App Console

1. [Dropbox App Console](https://www.dropbox.com/developers/apps)にアクセス
2. ログイン後、該当するアプリを選択
3. 「Settings」タブを開く
4. 「OAuth 2」セクションの「Redirect URIs」に以下を**追加**:

```
https://shin-on-wiki.mydns.jp/auth/dropbox/callback
```

⚠️ **注意**: 既存の開発環境用URI（`https://localhost:8443/auth/dropbox/callback`）は削除せず、追加してください。

5. 「Add」をクリック後、「Save」をクリック

#### .envファイルの確認

サーバー上の`.env`ファイルで、Dropbox設定が正しいことを確認:

```bash
cd /var/www/shin-on_wiki
nano .env
```

```bash
DROPBOX_CLIENT_ID=your_dropbox_app_key
DROPBOX_CLIENT_SECRET=your_dropbox_app_secret
DROPBOX_BACKUP_FOLDER=/shin-on_wiki-backup
BACKUP_RETENTION_DAYS=30
```

#### Dropbox OAuth認証

初回のみ、Dropbox OAuth認証を行う必要があります。

```bash
# Dropbox認証URLを取得
docker compose -f docker-compose.production.yml exec app php artisan backup:dropbox

# 表示されたURLにブラウザでアクセス
# 認証後、リダイレクトURIが正しく機能することを確認
```

---

## 自動デプロイの仕組み

### 9.1 GitHub Actionsワークフロー詳細

「5.2 GitHub Actions設定」で作成した`.github/workflows/deploy.yml`の詳細を解説します。

#### ワークフローのトリガー

```yaml
on:
  push:
    branches:
      - release  # releaseブランチへのpushでトリガー
```

- `release`ブランチにpushされた時のみ、デプロイが実行されます
- 他のブランチ（`main`や`develop`等）へのpushでは実行されません

#### デプロイステップの詳細

1. **コードのチェックアウト**:
   ```yaml
   - name: Checkout code
     uses: actions/checkout@v4
   ```
   最新のコードをGitHub Actionsランナーにチェックアウトします。

2. **SSHでサーバーに接続してスクリプト実行**:
   ```yaml
   - name: Deploy to server
     uses: appleboy/ssh-action@v1.0.0
     with:
       host: ${{ secrets.DEPLOY_HOST }}
       username: ${{ secrets.DEPLOY_USER }}
       key: ${{ secrets.DEPLOY_KEY }}
       script: |
         # デプロイスクリプト
   ```

3. **最新コードの取得**:
   ```bash
   git fetch origin
   git reset --hard origin/release
   ```
   サーバー上のコードを最新のreleaseブランチに同期します。

4. **依存関係の更新**:
   ```bash
   docker compose -f docker-compose.production.yml exec -T app composer install --no-dev --optimize-autoloader
   docker compose -f docker-compose.production.yml exec -T app npm ci --omit=dev
   ```
   Composerパッケージ（PHPライブラリ）とNPMパッケージ（JavaScriptライブラリ）を更新します。

5. **アセットのビルド**:
   ```bash
   docker compose -f docker-compose.production.yml exec -T app npm run production
   ```
   JavaScript/CSSファイルを本番環境用に最適化してビルドします。

6. **データベースマイグレーション**:
   ```bash
   docker compose -f docker-compose.production.yml exec -T app php artisan migrate --force
   ```
   データベーススキーマを最新の状態に更新します。

7. **キャッシュクリア**:
   ```bash
   docker compose -f docker-compose.production.yml exec -T app php artisan config:clear
   docker compose -f docker-compose.production.yml exec -T app php artisan cache:clear
   docker compose -f docker-compose.production.yml exec -T app php artisan route:clear
   docker compose -f docker-compose.production.yml exec -T app php artisan view:clear
   ```
   古いキャッシュをクリアします。

8. **キャッシュ最適化**:
   ```bash
   docker compose -f docker-compose.production.yml exec -T app php artisan config:cache
   docker compose -f docker-compose.production.yml exec -T app php artisan route:cache
   docker compose -f docker-compose.production.yml exec -T app php artisan view:cache
   ```
   新しいキャッシュを生成してパフォーマンスを最適化します。

#### ロールバック方法

デプロイに失敗した場合、以前のバージョンに戻すことができます。

```bash
# サーバーにSSH接続
ssh -i ~/.ssh/id_ed25519_homeserver username@shin-on-wiki.mydns.jp

# プロジェクトディレクトリに移動
cd /var/www/shin-on_wiki

# 前のコミットに戻す
git log --oneline  # コミット履歴を確認
git reset --hard <前のコミットハッシュ>

# 依存関係を再インストール
docker compose -f docker-compose.production.yml exec -T app composer install --no-dev --optimize-autoloader
docker compose -f docker-compose.production.yml exec -T app npm ci --omit=dev

# キャッシュクリア
docker compose -f docker-compose.production.yml exec -T app php artisan config:clear
docker compose -f docker-compose.production.yml exec -T app php artisan cache:clear

# コンテナ再起動
docker compose -f docker-compose.production.yml restart app
```

---

### 9.2 デプロイスクリプト

プロジェクトには既に`deploy.sh`スクリプトが用意されています。GitHub Actionsを使わず、手動でデプロイする場合にも使用できます。

#### deploy.shの使い方

```bash
# 初回デプロイ
./deploy.sh --initial

# 更新デプロイ
./deploy.sh --update

# ロールバック
./deploy.sh --rollback
```

#### deploy.shの主な機能

1. **前提条件チェック**: PHP 8.2以上、必要な拡張機能の確認
2. **.env検証**: 必須設定項目の確認
3. **データベース接続確認**: DB接続テスト
4. **バックアップ作成**: デプロイ前にデータベースとファイルのバックアップ
5. **依存関係インストール**: Composer/NPMパッケージのインストール
6. **データベースマイグレーション**: スキーマ更新
7. **キャッシュ最適化**: 設定/ルート/ビューのキャッシュ生成
8. **パーミッション設定**: ストレージディレクトリのパーミッション修正
9. **デプロイ後チェック**: デプロイの成功確認

---

## 運用・メンテナンス

### 10.1 監視

#### ログ確認コマンド

**Apacheログ**:

```bash
# エラーログをリアルタイム表示
sudo tail -f /var/log/apache2/wiki-ssl-error.log

# アクセスログをリアルタイム表示
sudo tail -f /var/log/apache2/wiki-ssl-access.log

# エラーログの最後の100行を表示
sudo tail -n 100 /var/log/apache2/wiki-ssl-error.log
```

**Dockerログ**:

```bash
# アプリケーションコンテナのログをリアルタイム表示
docker compose -f docker-compose.production.yml logs -f app

# MySQLコンテナのログをリアルタイム表示
docker compose -f docker-compose.production.yml logs -f mysql

# 全コンテナのログをリアルタイム表示
docker compose -f docker-compose.production.yml logs -f

# 最新の100行を表示
docker compose -f docker-compose.production.yml logs --tail=100 app
```

**Laravelログ**:

```bash
# Laravelアプリケーションログをリアルタイム表示
docker compose -f docker-compose.production.yml exec app tail -f storage/logs/laravel.log

# または、サーバー上で直接確認
tail -f /var/www/shin-on_wiki/storage/logs/laravel.log
```

#### ヘルスチェック

**コンテナの状態確認**:

```bash
# 実行中のコンテナを確認
docker compose -f docker-compose.production.yml ps

# コンテナの詳細情報を確認
docker compose -f docker-compose.production.yml ps --format json | jq .

# コンテナのリソース使用状況を確認
docker stats
```

**アプリケーションの動作確認**:

```bash
# HTTPSでアクセステスト
curl -I https://shin-on-wiki.mydns.jp

# レスポンスタイム測定
time curl -so /dev/null https://shin-on-wiki.mydns.jp

# データベース接続確認
docker compose -f docker-compose.production.yml exec app php artisan tinker
# >>> DB::connection()->getPdo();
# >>> exit
```

**ディスク使用量確認**:

```bash
# ディスク使用量を確認
df -h

# プロジェクトディレクトリのサイズ確認
du -sh /var/www/shin-on_wiki

# Dockerのディスク使用量確認
docker system df

# 不要なDockerリソースの削除（慎重に実行）
# docker system prune -a
```

---

### 10.2 バックアップ

#### 自動Dropboxバックアップ設定

プロジェクトには既にDropboxバックアップ機能が実装されています。

**バックアップコマンド**:

```bash
# データベースとファイルをバックアップ
docker compose -f docker-compose.production.yml exec app php artisan backup:dropbox

# バックアップテスト（実際にはアップロードしない）
docker compose -f docker-compose.production.yml exec app php artisan backup:test
```

#### Cronで定期バックアップ設定

```bash
# Crontabを編集
crontab -e
```

以下を追加（毎日午前2時にバックアップ実行）:

```cron
0 2 * * * cd /var/www/shin-on_wiki && docker compose -f docker-compose.production.yml exec -T app php artisan backup:dropbox >> /var/log/backup.log 2>&1
```

バックアップログの確認:

```bash
# バックアップログを確認
tail -f /var/log/backup.log
```

#### 手動バックアップ

**データベースのバックアップ**:

```bash
# データベースをダンプ
docker compose -f docker-compose.production.yml exec -T mysql mysqldump -u bookstack -p shin_on_wiki > backup_$(date +%Y%m%d_%H%M%S).sql

# パスワードプロンプトが表示されたら、DB_PASSWORDを入力
```

**ファイルのバックアップ**:

```bash
# プロジェクト全体をアーカイブ（.envは含めない）
tar --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='.env' \
    -czf backup_$(date +%Y%m%d_%H%M%S).tar.gz \
    /var/www/shin-on_wiki

# 特定のディレクトリのみバックアップ（ユーザーアップロードファイル等）
tar -czf storage_backup_$(date +%Y%m%d_%H%M%S).tar.gz \
    /var/www/shin-on_wiki/storage/app
```

#### バックアップからのリストア

**Dropboxからリストア**:

```bash
# Dropbox上のバックアップ一覧を表示
docker compose -f docker-compose.production.yml exec app php artisan backup:dropbox:list

# 特定のバックアップをリストア
docker compose -f docker-compose.production.yml exec app php artisan restore:dropbox backup_20250115_020000.sql

# 最新のバックアップをリストア
docker compose -f docker-compose.production.yml exec app php artisan restore:dropbox
```

詳細は`claude/docs/BACKUP_RESTORE.md`を参照してください。

---

### 10.3 更新手順

#### 開発環境から本番環境への更新フロー

1. **開発環境で機能開発・テスト**:
   ```bash
   # 開発環境（Mac）
   cd /Users/satoshi/Laravel/shin-on_wiki

   # 機能開発
   # テスト実行
   # 動作確認
   ```

2. **releaseブランチにマージ**:
   ```bash
   # developブランチで開発した機能をreleaseにマージ
   git checkout release
   git merge develop
   ```

3. **GitHubにpush**:
   ```bash
   # releaseブランチをpush
   git push origin release

   # GitHub Actionsが自動的にトリガーされます
   ```

4. **GitHub Actionsの進行状況確認**:
   - GitHubリポジトリの「Actions」タブを開く
   - 最新のワークフロー実行を確認
   - 緑色のチェックマーク：成功
   - 赤色のXマーク：失敗（ログを確認）

5. **本番環境での動作確認**:
   ```bash
   # ブラウザでアクセス
   https://shin-on-wiki.mydns.jp

   # 機能が正しく動作することを確認
   ```

#### 手動デプロイ方法

GitHub Actionsを使わず、手動でデプロイする場合:

```bash
# サーバーにSSH接続
ssh -i ~/.ssh/id_ed25519_homeserver username@shin-on-wiki.mydns.jp

# プロジェクトディレクトリに移動
cd /var/www/shin-on_wiki

# 最新コードを取得
git fetch origin
git reset --hard origin/release

# deploy.shスクリプトを使用
./deploy.sh --update

# または、手動で各ステップを実行
docker compose -f docker-compose.production.yml exec -T app composer install --no-dev --optimize-autoloader
docker compose -f docker-compose.production.yml exec -T app npm ci --omit=dev
docker compose -f docker-compose.production.yml exec -T app npm run production
docker compose -f docker-compose.production.yml exec -T app php artisan migrate --force
docker compose -f docker-compose.production.yml exec -T app php artisan config:clear
docker compose -f docker-compose.production.yml exec -T app php artisan cache:clear
docker compose -f docker-compose.production.yml exec -T app php artisan config:cache
docker compose -f docker-compose.production.yml exec -T app php artisan route:cache
docker compose -f docker-compose.production.yml exec -T app php artisan view:cache
```

---

## トラブルシューティング

### よくある問題と解決方法

#### 1. Apacheプロキシ接続エラー

**症状**:
```
Service Unavailable
The server is temporarily unable to service your request
```

**原因**: Dockerコンテナが起動していない

**解決方法**:
```bash
# コンテナの状態確認
docker compose -f docker-compose.production.yml ps

# コンテナが停止している場合は起動
docker compose -f docker-compose.production.yml up -d

# ログ確認
docker compose -f docker-compose.production.yml logs app
```

---

#### 2. HTTPSリダイレクトループ

**症状**: ブラウザでアクセスすると「リダイレクトが繰り返し行われました」エラー

**原因**:
- .envの`APP_URL`がHTTPになっている
- Apache設定の`ProxyPreserveHost`が無効

**解決方法**:
```bash
# .envファイルを確認
nano /var/www/shin-on_wiki/.env

# APP_URLがHTTPSになっているか確認
# APP_URL=https://shin-on-wiki.mydns.jp

# Apache設定を確認
sudo nano /etc/apache2/sites-available/shin-on_wiki.conf

# ProxyPreserveHost Onが設定されているか確認

# 設定変更後はApacheを再起動
sudo systemctl reload apache2

# キャッシュクリア
docker compose -f docker-compose.production.yml exec app php artisan config:clear
docker compose -f docker-compose.production.yml exec app php artisan cache:clear
```

---

#### 3. データベース接続エラー

**症状**:
```
SQLSTATE[HY000] [2002] Connection refused
```

**原因**:
- MySQLコンテナが起動していない
- .envのDB_HOSTが間違っている

**解決方法**:
```bash
# MySQLコンテナの状態確認
docker compose -f docker-compose.production.yml ps mysql

# MySQLコンテナが停止している場合は起動
docker compose -f docker-compose.production.yml up -d mysql

# .envのDB_HOST確認（コンテナ名 "mysql" になっているか）
nano /var/www/shin-on_wiki/.env
# DB_HOST=mysql

# MySQLコンテナのログ確認
docker compose -f docker-compose.production.yml logs mysql

# データベース接続テスト
docker compose -f docker-compose.production.yml exec app php artisan tinker
# >>> DB::connection()->getPdo();
```

---

#### 4. LINE WORKS SSOログインエラー

**症状**: LINE WORKSでログインしようとすると「リダイレクトURIが一致しません」エラー

**原因**: LINE WORKS Developer Consoleのリダイレクトuri設定が間違っている

**解決方法**:
1. LINE WORKS Developer Consoleにアクセス
2. OAuth 2.0設定を開く
3. リダイレクトURIに以下が正しく設定されているか確認:
   ```
   https://shin-on-wiki.mydns.jp/oidc/callback
   ```
4. .envファイルのOIDC設定を確認:
   ```bash
   nano /var/www/shin-on_wiki/.env
   # OIDC_CLIENT_ID、OIDC_CLIENT_SECRETが正しいか確認
   ```

---

#### 5. Dropboxバックアップエラー

**症状**:
```
Error: DROPBOX_CLIENT_ID or DROPBOX_CLIENT_SECRET is not set
```

**原因**:
- .envにDropbox設定が未設定
- Dropbox OAuth認証が未完了

**解決方法**:
```bash
# .envファイルを確認
nano /var/www/shin-on_wiki/.env
# DROPBOX_CLIENT_ID、DROPBOX_CLIENT_SECRETが設定されているか確認

# Dropbox OAuth認証を実行
docker compose -f docker-compose.production.yml exec app php artisan backup:dropbox

# 表示されたURLにブラウザでアクセスして認証
```

---

#### 6. ストレージパーミッションエラー

**症状**:
```
The stream or file "/var/www/html/storage/logs/laravel.log" could not be opened
```

**原因**: ストレージディレクトリのパーミッションが正しくない

**解決方法**:
```bash
# パーミッション修正
cd /var/www/shin-on_wiki
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# コンテナ内部でも実行
docker compose -f docker-compose.production.yml exec app chmod -R 775 storage
docker compose -f docker-compose.production.yml exec app chmod -R 775 bootstrap/cache
```

---

#### 7. GitHub Actions デプロイ失敗

**症状**: GitHub Actionsのワークフローが赤色（失敗）

**原因**:
- SSH接続失敗
- 秘密鍵の設定ミス
- サーバー上のパーミッション問題

**解決方法**:

1. **GitHub Actionsのログを確認**:
   - GitHubリポジトリの「Actions」タブを開く
   - 失敗したワークフローをクリック
   - エラーメッセージを確認

2. **SSH接続テスト**:
   ```bash
   # 開発環境から本番サーバーにSSH接続できるか確認
   ssh username@shin-on-wiki.mydns.jp
   ```

3. **Secretsの再確認**:
   - GitHub Secretsの設定を確認
   - 特に`DEPLOY_KEY`が正しい秘密鍵であることを確認

4. **サーバー側のSSH設定確認**:
   ```bash
   # サーバー上でSSHログを確認
   sudo tail -f /var/log/auth.log
   ```

---

#### 8. SSL証明書エラー

**症状**: ブラウザで「接続がプライベートではありません」エラー

**原因**:
- SSL証明書の取得失敗
- 証明書の有効期限切れ

**解決方法**:
```bash
# 証明書の状態確認
sudo certbot certificates

# 証明書の有効期限確認
sudo openssl x509 -in /etc/letsencrypt/live/shin-on-wiki.mydns.jp/fullchain.pem -noout -dates

# 証明書の手動更新
sudo certbot renew

# Apacheを再起動
sudo systemctl reload apache2
```

---

#### ログ確認方法まとめ

```bash
# Apacheエラーログ
sudo tail -f /var/log/apache2/wiki-ssl-error.log

# Dockerアプリケーションログ
docker compose -f docker-compose.production.yml logs -f app

# Laravelアプリケーションログ
tail -f /var/www/shin-on_wiki/storage/logs/laravel.log

# MySQLログ
docker compose -f docker-compose.production.yml logs -f mysql

# システムログ
sudo journalctl -u apache2 -f
sudo journalctl -u docker -f

# SSH認証ログ
sudo tail -f /var/log/auth.log
```

---

## セキュリティチェックリスト

デプロイ完了後、以下の項目を必ず確認してください。

### 必須チェック項目

- [ ] **.envファイルのパーミッション**: `chmod 600 .env`で600に設定されているか
- [ ] **.envファイルの機密情報**: 強力なパスワード、本番用のAPIキーが設定されているか
- [ ] **APP_DEBUG**: `APP_DEBUG=false`になっているか
- [ ] **APP_ENV**: `APP_ENV=production`になっているか
- [ ] **Dockerポート**: 8083番ポートが外部公開されていないか（127.0.0.1:8083のみ）
- [ ] **ファイアウォール**: UFWが有効で、80/443/22のみ許可されているか
- [ ] **SSH設定**: パスワード認証が無効化され、鍵認証のみになっているか
- [ ] **fail2ban**: 正常に動作しているか
- [ ] **SSL証明書**: Let's Encryptの証明書が正しく取得され、HTTPSでアクセスできるか
- [ ] **HSTS**: Strict-Transport-Securityヘッダーが設定されているか
- [ ] **セキュリティヘッダー**: X-Frame-Options、X-Content-Type-Options等が設定されているか
- [ ] **データベースパスワード**: デフォルトのパスワードから変更されているか
- [ ] **Gitの.env**: .envファイルがGitリポジトリにコミットされていないか
- [ ] **バックアップ**: 自動バックアップが設定され、正常に動作するか
- [ ] **証明書自動更新**: Certbotの自動更新タイマーが有効になっているか

### 推奨チェック項目

- [ ] **ログ監視**: ログローテーションが設定されているか
- [ ] **ディスク容量監視**: ディスク容量不足の通知設定があるか
- [ ] **定期的なアップデート**: OSパッケージの自動更新が有効か
- [ ] **外部からのアクセステスト**: HTTPS、リダイレクト、セキュリティヘッダーが正しく機能するか
- [ ] **LINE WORKSログイン**: SSOログインが正常に動作するか
- [ ] **Dropboxバックアップ**: バックアップが正常に完了するか
- [ ] **ロールバックテスト**: 緊急時のロールバック手順を確認したか

### セキュリティツールによるスキャン

```bash
# SSL設定の確認（外部サービス）
# https://www.ssllabs.com/ssltest/
# にアクセスして、ドメイン名を入力してスキャン

# セキュリティヘッダーの確認
curl -I https://shin-on-wiki.mydns.jp | grep -E "(X-Frame|X-Content|Strict-Transport|X-XSS|Referrer)"

# ポートスキャン（外部から）
# nmap -sV -sC shin-on-wiki.mydns.jp
```

---

## 参考リンク

### プロジェクト内ドキュメント

- [プロジェクトルートREADME](../../README.md) - プロジェクト全体の概要
- [claude/README.md](../README.md) - claudeディレクトリの概要
- [claude/docs/README.md](./README.md) - ドキュメント目次
- [DEPLOYMENT_DOCKER.md](./DEPLOYMENT_DOCKER.md) - Docker本番環境デプロイ
- [BACKUP_RESTORE.md](./BACKUP_RESTORE.md) - バックアップ/リストアガイド
- [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md) - LINE WORKS SSO詳細設定
- [SYSTEM_REQUIREMENTS.md](./SYSTEM_REQUIREMENTS.md) - システム要件

### 外部リソース

#### Docker
- [Docker公式ドキュメント](https://docs.docker.com/)
- [Docker Compose公式ドキュメント](https://docs.docker.com/compose/)

#### Apache
- [Apache HTTP Server公式ドキュメント](https://httpd.apache.org/docs/)
- [mod_proxyドキュメント](https://httpd.apache.org/docs/2.4/mod/mod_proxy.html)
- [mod_sslドキュメント](https://httpd.apache.org/docs/2.4/mod/mod_ssl.html)

#### Let's Encrypt / Certbot
- [Let's Encrypt公式サイト](https://letsencrypt.org/)
- [Certbot公式ドキュメント](https://certbot.eff.org/)

#### MyDNS.JP
- [MyDNS.JP公式サイト](https://www.mydns.jp/)

#### GitHub Actions
- [GitHub Actions公式ドキュメント](https://docs.github.com/ja/actions)
- [appleboy/ssh-action](https://github.com/appleboy/ssh-action)

#### セキュリティ
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Mozilla SSL Configuration Generator](https://ssl-config.mozilla.org/)
- [Security Headers](https://securityheaders.com/)

#### Laravel
- [Laravel公式ドキュメント](https://laravel.com/docs)
- [Laravel Deployment](https://laravel.com/docs/deployment)

---

## まとめ

このドキュメントでは、自宅サーバーでshin-on_wikiをDocker環境で公開し、GitHubからの自動デプロイを実現する手順を説明しました。

### 主要なポイント

1. **セキュリティ第一**: ファイアウォール、SSH鍵認証、fail2ban等のセキュリティ対策を必ず実施
2. **Docker + Apache構成**: Dockerでアプリケーションを分離し、ApacheでSSL終端とリバースプロキシ
3. **Let's Encrypt**: 無料でSSL証明書を取得し、自動更新
4. **MyDNS.JP**: 自宅サーバーのIPアドレスを動的に更新
5. **GitHub Actions**: GitHubへのpushで自動的に本番環境にデプロイ
6. **バックアップ**: Dropboxへの自動バックアップで万が一に備える

### 次のステップ

- [BACKUP_RESTORE.md](./BACKUP_RESTORE.md)を参照して、バックアップとリストアの詳細を確認
- [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md)を参照して、LINE WORKS SSOの詳細設定を確認
- 定期的なメンテナンス（OSアップデート、ログ確認、バックアップ確認）を実施

---

## 📅 最終更新日

2025年1月23日

## 👥 作成者

Claude Code + satoshi

---

## フィードバック

このドキュメントに関する質問や改善提案がある場合は、GitHubのIssuesでお知らせください。

[GitHubリポジトリ](https://github.com/satoshi-tateishi/shin-on_wiki)
