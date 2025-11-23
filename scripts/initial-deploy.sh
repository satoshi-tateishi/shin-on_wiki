#!/bin/bash

################################################################################
# shin-on Wiki - 初回デプロイ自動化スクリプト
################################################################################
#
# このスクリプトは、ホームサーバーでの初回デプロイを自動化します。
#
# 使用方法:
#   1. サーバー側でこのスクリプトを実行
#      bash scripts/initial-deploy.sh
#
# 前提条件:
#   - Git, Docker, Docker Compose がインストール済み
#   - プロジェクトが /var/www/shin-on_wiki にクローン済み
#   - .env ファイルが設定済み
#
################################################################################

set -e  # エラーが発生したら即座に終了

# 色付き出力用
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ログ出力関数
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# プロジェクトディレクトリに移動
PROJECT_DIR="/var/www/shin-on_wiki"
cd "$PROJECT_DIR" || {
    log_error "プロジェクトディレクトリ $PROJECT_DIR が見つかりません"
    exit 1
}

log_info "プロジェクトディレクトリ: $PROJECT_DIR"

################################################################################
# ステップ1: 依存関係のインストール確認
################################################################################

log_info "========================================="
log_info "ステップ1: 依存関係の確認"
log_info "========================================="

# PHP確認
if ! command -v php &> /dev/null; then
    log_error "PHPがインストールされていません"
    log_info "以下のコマンドでインストールしてください:"
    echo "sudo apt update"
    echo "sudo apt install -y php8.3-cli php8.3-fpm php8.3-mysql php8.3-xml php8.3-mbstring \\"
    echo "                    php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl php8.3-redis"
    exit 1
fi
log_success "PHP: $(php --version | head -n 1)"

# Composer確認
if ! command -v composer &> /dev/null; then
    log_error "Composerがインストールされていません"
    log_info "以下のコマンドでインストールしてください:"
    echo "cd ~ && curl -sS https://getcomposer.org/installer | php"
    echo "sudo mv composer.phar /usr/local/bin/composer"
    exit 1
fi
log_success "Composer: $(composer --version | head -n 1)"

# Node.js確認
if ! command -v node &> /dev/null; then
    log_error "Node.jsがインストールされていません"
    log_info "以下のコマンドでインストールしてください:"
    echo "curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -"
    echo "sudo apt-get install -y nodejs"
    exit 1
fi
log_success "Node.js: $(node --version)"
log_success "NPM: $(npm --version)"

################################################################################
# ステップ2: .env ファイルの確認
################################################################################

log_info "========================================="
log_info "ステップ2: .env ファイルの確認"
log_info "========================================="

if [ ! -f .env ]; then
    log_error ".env ファイルが見つかりません"
    log_info ".env.production.example をコピーして編集してください"
    exit 1
fi

# DB_HOSTの確認
DB_HOST=$(grep "^DB_HOST=" .env | cut -d '=' -f2)
if [ "$DB_HOST" != "db" ]; then
    log_warning "DB_HOST が 'db' ではありません (現在: $DB_HOST)"
    log_info "DB_HOST を 'db' に修正します..."
    sed -i 's/^DB_HOST=.*/DB_HOST=db/' .env
    log_success "DB_HOST を 'db' に変更しました"
fi

# APP_KEYの確認
APP_KEY=$(grep "^APP_KEY=" .env | cut -d '=' -f2)
if [ -z "$APP_KEY" ]; then
    log_warning "APP_KEY が設定されていません"
    log_info "APP_KEY を生成します..."
    php artisan key:generate
    log_success "APP_KEY を生成しました"
fi

log_success ".env ファイルの確認完了"

################################################################################
# ステップ3: パーミッション設定（ホスト側作業用）
################################################################################

log_info "========================================="
log_info "ステップ3: パーミッション設定"
log_info "========================================="

log_info ".env ファイルのパーミッション設定..."
chmod 600 .env

log_info "ストレージディレクトリのパーミッション設定（ホスト側作業用）..."
sudo chown -R $USER:$USER storage bootstrap/cache public/uploads
chmod -R 775 storage bootstrap/cache public/uploads

log_success "パーミッション設定完了"

################################################################################
# ステップ4: Composer 依存関係のインストール
################################################################################

log_info "========================================="
log_info "ステップ4: Composer 依存関係のインストール"
log_info "========================================="

log_info "Composer 依存関係をインストール中..."
composer install --no-dev --optimize-autoloader

log_success "Composer 依存関係のインストール完了"

################################################################################
# ステップ5: NPM 依存関係のインストールとビルド
################################################################################

log_info "========================================="
log_info "ステップ5: NPM 依存関係とアセットビルド"
log_info "========================================="

log_info "NPM 依存関係をインストール中..."
npm ci

log_info "アセットをビルド中（本番環境用）..."
npm run production

log_info "開発用依存関係を削除中..."
npm prune --omit=dev

log_success "NPM 依存関係とアセットビルド完了"

################################################################################
# ステップ6: ストレージリンク作成
################################################################################

log_info "========================================="
log_info "ステップ6: ストレージリンク作成"
log_info "========================================="

log_info "ストレージリンクを作成中..."
php artisan storage:link

log_success "ストレージリンク作成完了"

################################################################################
# ステップ7: Docker イメージのビルド
################################################################################

log_info "========================================="
log_info "ステップ7: Docker イメージのビルド"
log_info "========================================="

log_info "Docker イメージをビルド中..."
docker compose -f docker-compose.production.yml build

log_success "Docker イメージのビルド完了"

################################################################################
# ステップ8: Docker コンテナの起動
################################################################################

log_info "========================================="
log_info "ステップ8: Docker コンテナの起動"
log_info "========================================="

log_info "Docker コンテナを起動中..."
docker compose -f docker-compose.production.yml up -d

log_info "コンテナの起動を待機中..."
sleep 10

log_success "Docker コンテナの起動完了"

################################################################################
# ステップ9: データベースマイグレーション
################################################################################

log_info "========================================="
log_info "ステップ9: データベースマイグレーション"
log_info "========================================="

log_info "データベースマイグレーションを実行中..."
docker compose -f docker-compose.production.yml exec -T app php artisan migrate --force

log_success "データベースマイグレーション完了"

################################################################################
# ステップ10: Dockerコンテナ用パーミッション設定
################################################################################

log_info "========================================="
log_info "ステップ10: Dockerコンテナ用パーミッション設定"
log_info "========================================="

log_info "Dockerコンテナ内のApache（www-data）用にパーミッション設定..."
sudo chown -R 33:33 storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

log_info "コンテナを再起動してパーミッション変更を反映..."
docker compose -f docker-compose.production.yml restart app

log_info "再起動を待機中..."
sleep 5

log_success "Dockerコンテナ用パーミッション設定完了"

################################################################################
# ステップ11: キャッシュ最適化
################################################################################

log_info "========================================="
log_info "ステップ11: キャッシュ最適化"
log_info "========================================="

log_info "キャッシュをクリア中..."
docker compose -f docker-compose.production.yml exec -T app php artisan config:clear
docker compose -f docker-compose.production.yml exec -T app php artisan cache:clear
docker compose -f docker-compose.production.yml exec -T app php artisan route:clear
docker compose -f docker-compose.production.yml exec -T app php artisan view:clear

log_info "キャッシュを最適化中..."
docker compose -f docker-compose.production.yml exec -T app php artisan config:cache
docker compose -f docker-compose.production.yml exec -T app php artisan route:cache
docker compose -f docker-compose.production.yml exec -T app php artisan view:cache

log_success "キャッシュ最適化完了"

################################################################################
# ステップ12: 動作確認
################################################################################

log_info "========================================="
log_info "ステップ12: 動作確認"
log_info "========================================="

log_info "アプリケーションにアクセステスト中..."
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8083)

if [ "$HTTP_STATUS" = "302" ] || [ "$HTTP_STATUS" = "200" ]; then
    log_success "アプリケーションが正常に動作しています (HTTP $HTTP_STATUS)"
else
    log_error "アプリケーションが正常に動作していません (HTTP $HTTP_STATUS)"
    log_info "ログを確認してください:"
    echo "docker compose -f docker-compose.production.yml logs app --tail=50"
    exit 1
fi

################################################################################
# 完了
################################################################################

log_success "========================================="
log_success "初回デプロイが完了しました！"
log_success "========================================="

echo ""
log_info "次のステップ:"
echo "  1. ブラウザで http://localhost:8083 にアクセス"
echo "  2. 初期セットアップを実行"
echo "  3. APP_DEBUG を本番モードに戻す:"
echo "     sed -i 's/APP_DEBUG=true/APP_DEBUG=false/' .env"
echo "     docker compose -f docker-compose.production.yml restart app"
echo ""
log_info "コンテナの状態確認:"
echo "  docker compose -f docker-compose.production.yml ps"
echo ""
log_info "ログの確認:"
echo "  docker compose -f docker-compose.production.yml logs -f app"
echo ""
