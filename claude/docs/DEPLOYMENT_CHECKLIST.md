# デプロイメントチェックリスト

shin·on Wiki by BookStack のデプロイ時に確認する項目です。

**詳細な手順は各ドキュメントを参照してください。**

---

## デプロイ方法の選択

| 方法 | ドキュメント | 用途 |
|------|--------------|------|
| **Docker（推奨）** | [DEPLOYMENT_DOCKER.md](./DEPLOYMENT_DOCKER.md) | 同一サーバーで複数アプリ運用可能 |
| **直接デプロイ** | [DEPLOYMENT.md](./DEPLOYMENT.md) | Ubuntu上で直接実行 |
| **自宅サーバー** | [DEPLOYMENT_HOME_SERVER.md](./DEPLOYMENT_HOME_SERVER.md) | MyDNS + GitHub Actions 連携 |

---

## 1. デプロイ前準備

### 必須情報

- [ ] ドメイン名を取得済み
- [ ] DNSレコードがサーバーIPを指している
- [ ] LINE WORKS Developer Console へのアクセス権限
- [ ] Dropbox App Console へのアクセス権限
- [ ] サーバーへのSSHアクセス

### LINE WORKS OAuth設定

1. [Developer Console](https://developers.worksmobile.com/jp/console/openapi/v2/app/list) にアクセス
2. Redirect URIに追加:
   - [ ] `https://your-domain.com/oidc/callback`
   - [ ] 開発環境URL（残しておく）: `https://localhost:8443/oidc/callback`
3. 設定をメモ:
   - [ ] `OIDC_CLIENT_ID`
   - [ ] `OIDC_CLIENT_SECRET`

### Dropbox OAuth設定

1. [App Console](https://www.dropbox.com/developers/apps) にアクセス
2. Redirect URIに追加:
   - [ ] `https://your-domain.com/auth/dropbox/callback`
   - [ ] 開発環境URL（残しておく）: `https://localhost:8443/auth/dropbox/callback`
3. 設定をメモ:
   - [ ] `DROPBOX_CLIENT_ID`
   - [ ] `DROPBOX_CLIENT_SECRET`

---

## 2. サーバー環境準備

### システム要件

詳細: [SYSTEM_REQUIREMENTS.md](./SYSTEM_REQUIREMENTS.md)

- [ ] Ubuntu 22.04 LTS または 24.04 LTS
- [ ] RAM 2GB以上（推奨4GB以上）
- [ ] ストレージ 20GB以上

### 必須パッケージ

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y git curl wget unzip ca-certificates certbot
```

### Dockerデプロイの場合

```bash
# Docker インストール
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER

# リバースプロキシ（Apache）
sudo apt install -y apache2 python3-certbot-apache
sudo a2enmod ssl proxy proxy_http headers rewrite
```

### 直接デプロイの場合

```bash
sudo apt install -y apache2 mysql-server \
  php8.3 php8.3-cli php8.3-fpm \
  php8.3-mysql php8.3-curl php8.3-gd \
  php8.3-mbstring php8.3-xml php8.3-zip \
  composer nodejs npm
```

### ファイアウォール

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

---

## 3. アプリケーションデプロイ

### 共通手順

```bash
# クローン
cd /var/www
git clone https://github.com/satoshi-tateishi/shin-on_wiki.git
cd shin-on_wiki

# 環境変数設定
cp .env.production.example .env
nano .env  # 必要な項目を設定
```

### 環境変数（必須項目）

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL=https://your-domain.com`
- [ ] `APP_KEY` (生成する)
- [ ] `DB_PASSWORD`
- [ ] `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET`
- [ ] `LINEWORKS_DOMAIN`
- [ ] `DROPBOX_CLIENT_ID` / `DROPBOX_CLIENT_SECRET`

### Dockerデプロイ

```bash
# ビルド・起動
docker compose -f docker-compose.production.yml build
docker compose -f docker-compose.production.yml up -d

# マイグレーション
docker compose -f docker-compose.production.yml exec app php artisan migrate --force

# 最適化
docker compose -f docker-compose.production.yml exec app php artisan config:cache
docker compose -f docker-compose.production.yml exec app php artisan route:cache
docker compose -f docker-compose.production.yml exec app php artisan view:cache
```

### 直接デプロイ

```bash
# 依存関係
composer install --no-dev --optimize-autoloader
npm install && npm run production

# マイグレーション
php artisan migrate --force

# パーミッション
sudo chown -R www-data:www-data .
sudo chmod -R 775 storage bootstrap/cache

# 最適化
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 4. SSL証明書

```bash
# Let's Encrypt
sudo certbot --apache -d your-domain.com
```

- [ ] HTTPSでアクセス可能
- [ ] HTTPがHTTPSにリダイレクトされる

---

## 5. デプロイ後確認

### 機能テスト

- [ ] サイトが正常に表示される
- [ ] LINE WORKS SSOでログインできる
- [ ] OTP二段階認証が動作する（設定している場合）
- [ ] ページの作成・編集ができる
- [ ] 画像アップロードができる

### Dropboxバックアップ

1. 設定 > 機能 > Dropboxと連携
2. - [ ] 認証が完了する
3. - [ ] テストバックアップが成功する

### 自動バックアップ設定

```bash
# cron設定（毎日午前2時）
sudo crontab -e -u www-data
# 追加: 0 2 * * * cd /var/www/shin-on_wiki && php artisan backup:dropbox
```

---

## 6. 更新デプロイ

```bash
cd /var/www/shin-on_wiki

# メンテナンスモード
php artisan down  # または docker compose exec app php artisan down

# 更新
git pull origin main
composer install --no-dev --optimize-autoloader  # 直接デプロイのみ
docker compose -f docker-compose.production.yml build  # Dockerのみ
docker compose -f docker-compose.production.yml up -d  # Dockerのみ

# マイグレーション（必要な場合）
php artisan migrate --force

# キャッシュクリア・再生成
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# メンテナンスモード解除
php artisan up
```

---

## トラブルシューティング

### よくある問題

| 問題 | 確認・対処 |
|------|-----------|
| 500エラー | `storage/logs/laravel.log` を確認、パーミッション修正 |
| LINE WORKS認証エラー | Redirect URI設定、APP_URL確認 |
| DB接続エラー | `.env`のDB設定、MySQLサービス確認 |
| カバー画像が表示されない | `php artisan bookstack:regenerate-thumbnails` |

### ログ確認

```bash
# アプリケーションログ
tail -f storage/logs/laravel.log

# Apacheログ
tail -f /var/log/apache2/error.log
```

---

## 関連ドキュメント

- [DEPLOYMENT.md](./DEPLOYMENT.md) - 直接デプロイ詳細
- [DEPLOYMENT_DOCKER.md](./DEPLOYMENT_DOCKER.md) - Dockerデプロイ詳細
- [DEPLOYMENT_HOME_SERVER.md](./DEPLOYMENT_HOME_SERVER.md) - 自宅サーバー設定
- [SYSTEM_REQUIREMENTS.md](./SYSTEM_REQUIREMENTS.md) - システム要件
- [BACKUP_RESTORE.md](./BACKUP_RESTORE.md) - バックアップ・復元
- [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md) - LINE WORKS SSO詳細

---

**最終更新**: 2025年11月26日
