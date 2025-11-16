#!/bin/bash

# ==============================================================
# shin·on Wiki by BookStack - デプロイメントスクリプト
# ==============================================================
#
# このスクリプトは、BookStackのデプロイプロセスを自動化します。
# 初回デプロイと更新デプロイの両方に対応しています。
#
# 使用方法:
#   chmod +x deploy.sh
#   ./deploy.sh [--initial|--update|--rollback]
#
# オプション:
#   --initial   : 初回デプロイ（オプション未指定時のデフォルト）
#   --update    : 既存デプロイの更新
#   --rollback  : 前のバージョンにロールバック
#
# ==============================================================

set -e  # エラー時に終了

# 出力用の色
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 設定
APP_DIR=$(cd "$(dirname "$0")" && pwd)
WEB_USER="www-data"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="${APP_DIR}/deployments/${TIMESTAMP}"

# ==============================================================
# ヘルパー関数
# ==============================================================

print_header() {
    echo -e "${BLUE}==============================================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}==============================================================${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

check_command() {
    if ! command -v $1 &> /dev/null; then
        print_error "$1 がインストールされていません。先にインストールしてください。"
        exit 1
    fi
    print_success "$1 が見つかりました"
}

# ==============================================================
# デプロイ前チェック
# ==============================================================

pre_deployment_checks() {
    print_header "デプロイ前チェック"

    # 必要なコマンドを確認
    print_info "必要なコマンドを確認中..."
    check_command "php"
    check_command "composer"
    check_command "npm"
    check_command "mysql"
    check_command "git"

    # PHPバージョンを確認
    PHP_VERSION=$(php -r "echo PHP_VERSION;" | cut -d. -f1,2)
    if (( $(echo "$PHP_VERSION < 8.2" | bc -l) )); then
        print_error "PHPバージョンは8.2以上である必要があります。現在: $PHP_VERSION"
        exit 1
    fi
    print_success "PHPバージョン: $PHP_VERSION"

    # PHP拡張機能を確認
    print_info "PHP拡張機能を確認中..."
    REQUIRED_EXTENSIONS=("curl" "dom" "fileinfo" "gd" "json" "mbstring" "xml" "zip" "pdo" "pdo_mysql")
    for ext in "${REQUIRED_EXTENSIONS[@]}"; do
        if ! php -m | grep -qi "^$ext$"; then
            print_error "PHP拡張機能 $ext がインストールされていません"
            exit 1
        fi
    done
    print_success "必要なPHP拡張機能がすべて見つかりました"

    # .envファイルを確認
    if [ ! -f "${APP_DIR}/.env" ]; then
        print_warning ".envファイルが見つかりません"
        if [ -f "${APP_DIR}/.env.production.example" ]; then
            print_info ".env.production.exampleを.envにコピーしています"
            cp "${APP_DIR}/.env.production.example" "${APP_DIR}/.env"
            print_warning "続行する前に.envファイルを本番環境の設定に編集してください"
            exit 1
        else
            print_error ".envまたは.env.production.exampleが見つかりません"
            exit 1
        fi
    fi
    print_success ".envファイルが存在します"

    # APP_KEYを確認
    if grep -q "^APP_KEY=$" "${APP_DIR}/.env" || ! grep -q "^APP_KEY=" "${APP_DIR}/.env"; then
        print_warning "APP_KEYが設定されていません。生成中..."
        php artisan key:generate --force
        print_success "APP_KEYを生成しました"
    else
        print_success "APP_KEYが設定されています"
    fi

    # APP_ENVを確認
    if ! grep -q "^APP_ENV=production" "${APP_DIR}/.env"; then
        print_warning "APP_ENVが'production'に設定されていません"
        print_info "現在の環境: $(grep "^APP_ENV=" "${APP_DIR}/.env" | cut -d= -f2)"
    else
        print_success "APP_ENVがproductionに設定されています"
    fi

    # データベース接続を確認
    print_info "データベース接続を確認中..."
    if php artisan db:show &> /dev/null; then
        print_success "データベース接続成功"
    else
        print_error "データベース接続に失敗しました。.envの設定を確認してください"
        exit 1
    fi

    echo ""
}

# ==============================================================
# メンテナンスモード
# ==============================================================

enable_maintenance_mode() {
    print_header "メンテナンスモード有効化"
    php artisan down --render="errors::503" --retry=60
    print_success "メンテナンスモードを有効にしました"
    echo ""
}

disable_maintenance_mode() {
    print_header "メンテナンスモード無効化"
    php artisan up
    print_success "メンテナンスモードを無効にしました"
    echo ""
}

# ==============================================================
# バックアップ
# ==============================================================

create_backup() {
    print_header "バックアップ作成"

    mkdir -p "$BACKUP_DIR"

    # .envをバックアップ
    print_info ".envをバックアップ中..."
    cp "${APP_DIR}/.env" "${BACKUP_DIR}/.env.backup"
    print_success ".envをバックアップしました"

    # データベースをバックアップ
    print_info "データベースをバックアップ中..."
    DB_NAME=$(grep "^DB_DATABASE=" "${APP_DIR}/.env" | cut -d= -f2)
    DB_USER=$(grep "^DB_USERNAME=" "${APP_DIR}/.env" | cut -d= -f2)
    DB_PASS=$(grep "^DB_PASSWORD=" "${APP_DIR}/.env" | cut -d= -f2)

    mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "${BACKUP_DIR}/database.sql"
    print_success "データベースを ${BACKUP_DIR}/database.sql にバックアップしました"

    # アップロードファイルをバックアップ
    if [ -d "${APP_DIR}/public/uploads" ]; then
        print_info "アップロードファイルをバックアップ中..."
        cp -r "${APP_DIR}/public/uploads" "${BACKUP_DIR}/uploads"
        print_success "アップロードファイルをバックアップしました"
    fi

    print_success "バックアップ完了: ${BACKUP_DIR}"
    echo ""
}

# ==============================================================
# Git操作
# ==============================================================

pull_latest_code() {
    print_header "最新コードの取得"

    # 現在のコミットを保存
    CURRENT_COMMIT=$(git rev-parse HEAD)
    echo "$CURRENT_COMMIT" > "${BACKUP_DIR}/commit.txt"

    # 最新の変更を取得
    print_info "最新の変更をフェッチ中..."
    git fetch origin

    print_info "mainブランチからプル中..."
    git pull origin main

    NEW_COMMIT=$(git rev-parse HEAD)

    if [ "$CURRENT_COMMIT" == "$NEW_COMMIT" ]; then
        print_info "すでに最新です"
    else
        print_success "$CURRENT_COMMIT から $NEW_COMMIT に更新しました"
    fi

    echo ""
}

# ==============================================================
# 依存関係
# ==============================================================

install_composer_dependencies() {
    print_header "Composer依存関係のインストール"

    print_info "composer installを実行中..."
    composer install --no-dev --optimize-autoloader --no-interaction

    print_success "Composer依存関係をインストールしました"
    echo ""
}

install_npm_dependencies() {
    print_header "NPM依存関係のインストールとアセットビルド"

    print_info "npm installを実行中..."
    npm install --production=false

    print_info "本番環境用アセットをビルド中..."
    npm run production

    print_success "NPM依存関係をインストールし、アセットをビルドしました"
    echo ""
}

# ==============================================================
# データベース
# ==============================================================

run_migrations() {
    print_header "データベースマイグレーション実行"

    print_info "マイグレーションを実行中..."
    php artisan migrate --force

    print_success "マイグレーション完了"
    echo ""
}

# ==============================================================
# 最適化
# ==============================================================

optimize_application() {
    print_header "アプリケーション最適化"

    # すべてのキャッシュをクリア
    print_info "キャッシュをクリア中..."
    php artisan cache:clear
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    print_success "キャッシュをクリアしました"

    # 設定をキャッシュ
    print_info "設定をキャッシュ中..."
    php artisan config:cache
    print_success "設定をキャッシュしました"

    # ルートをキャッシュ
    print_info "ルートをキャッシュ中..."
    php artisan route:cache
    print_success "ルートをキャッシュしました"

    # ビューをキャッシュ
    print_info "ビューをキャッシュ中..."
    php artisan view:cache
    print_success "ビューをキャッシュしました"

    # ストレージリンクを作成
    if [ ! -L "${APP_DIR}/public/storage" ]; then
        print_info "ストレージリンクを作成中..."
        php artisan storage:link
        print_success "ストレージリンクを作成しました"
    fi

    echo ""
}

# ==============================================================
# パーミッション
# ==============================================================

set_permissions() {
    print_header "ファイルパーミッション設定"

    print_info "所有者を ${WEB_USER} に設定中..."
    sudo chown -R ${WEB_USER}:${WEB_USER} "${APP_DIR}"

    print_info "ディレクトリパーミッションを設定中..."
    sudo chmod -R 775 "${APP_DIR}/storage"
    sudo chmod -R 775 "${APP_DIR}/bootstrap/cache"

    if [ -d "${APP_DIR}/public/uploads" ]; then
        sudo chmod -R 775 "${APP_DIR}/public/uploads"
    fi

    # .envファイルを保護
    sudo chmod 600 "${APP_DIR}/.env"

    print_success "パーミッションを設定しました"
    echo ""
}

# ==============================================================
# デプロイ後チェック
# ==============================================================

post_deployment_checks() {
    print_header "デプロイ後チェック"

    # アプリケーションの状態を確認
    print_info "アプリケーションの状態を確認中..."
    if curl -s -o /dev/null -w "%{http_code}" http://localhost | grep -q "200\|302"; then
        print_success "アプリケーションが応答しています"
    else
        print_warning "アプリケーションが正しく応答していない可能性があります"
    fi

    # 書き込み可能なディレクトリを確認
    print_info "書き込み可能なディレクトリを確認中..."
    WRITABLE_DIRS=("storage" "storage/logs" "storage/framework" "bootstrap/cache")
    for dir in "${WRITABLE_DIRS[@]}"; do
        if [ -w "${APP_DIR}/${dir}" ]; then
            print_success "${dir} は書き込み可能です"
        else
            print_error "${dir} は書き込み可能ではありません"
        fi
    done

    # データベース接続を確認
    print_info "データベース接続を確認中..."
    if php artisan db:show &> /dev/null; then
        print_success "データベース接続OK"
    else
        print_error "データベース接続に失敗しました"
    fi

    echo ""
}

# ==============================================================
# ロールバック
# ==============================================================

rollback_deployment() {
    print_header "デプロイのロールバック"

    if [ ! -d "$BACKUP_DIR" ]; then
        print_error "バックアップディレクトリが見つかりません: $BACKUP_DIR"
        print_info "利用可能なバックアップ:"
        ls -1 "${APP_DIR}/deployments" 2>/dev/null || print_warning "バックアップが見つかりません"
        exit 1
    fi

    # .envを復元
    if [ -f "${BACKUP_DIR}/.env.backup" ]; then
        print_info ".envを復元中..."
        cp "${BACKUP_DIR}/.env.backup" "${APP_DIR}/.env"
        print_success ".envを復元しました"
    fi

    # データベースを復元
    if [ -f "${BACKUP_DIR}/database.sql" ]; then
        print_info "データベースを復元中..."
        DB_NAME=$(grep "^DB_DATABASE=" "${APP_DIR}/.env" | cut -d= -f2)
        DB_USER=$(grep "^DB_USERNAME=" "${APP_DIR}/.env" | cut -d= -f2)
        DB_PASS=$(grep "^DB_PASSWORD=" "${APP_DIR}/.env" | cut -d= -f2)

        mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "${BACKUP_DIR}/database.sql"
        print_success "データベースを復元しました"
    fi

    # アップロードファイルを復元
    if [ -d "${BACKUP_DIR}/uploads" ]; then
        print_info "アップロードファイルを復元中..."
        rm -rf "${APP_DIR}/public/uploads"
        cp -r "${BACKUP_DIR}/uploads" "${APP_DIR}/public/uploads"
        print_success "アップロードファイルを復元しました"
    fi

    # gitコミットを復元
    if [ -f "${BACKUP_DIR}/commit.txt" ]; then
        COMMIT=$(cat "${BACKUP_DIR}/commit.txt")
        print_info "gitコミットを復元中: $COMMIT..."
        git reset --hard "$COMMIT"
        print_success "gitコミットを復元しました"
    fi

    # 最適化を再実行
    optimize_application
    set_permissions

    print_success "ロールバック完了"
    echo ""
}

# ==============================================================
# メインデプロイ関数
# ==============================================================

initial_deployment() {
    print_header "初回デプロイ開始"

    pre_deployment_checks
    create_backup
    install_composer_dependencies
    install_npm_dependencies
    run_migrations
    optimize_application
    set_permissions
    post_deployment_checks

    print_header "初回デプロイ完了"
    print_success "アプリケーションの準備ができました！"
    print_info "バックアップ保存先: ${BACKUP_DIR}"
    echo ""
}

update_deployment() {
    print_header "更新デプロイ開始"

    pre_deployment_checks
    enable_maintenance_mode
    create_backup
    pull_latest_code
    install_composer_dependencies
    install_npm_dependencies
    run_migrations
    optimize_application
    set_permissions
    disable_maintenance_mode
    post_deployment_checks

    print_header "更新デプロイ完了"
    print_success "アプリケーションが正常に更新されました！"
    print_info "バックアップ保存先: ${BACKUP_DIR}"
    echo ""
}

# ==============================================================
# メインスクリプト
# ==============================================================

main() {
    cd "$APP_DIR"

    case "${1:-}" in
        --initial)
            initial_deployment
            ;;
        --update)
            update_deployment
            ;;
        --rollback)
            if [ -z "${2:-}" ]; then
                print_error "バックアップディレクトリを指定してください"
                print_info "使用方法: $0 --rollback /path/to/backup"
                exit 1
            fi
            BACKUP_DIR="$2"
            enable_maintenance_mode
            rollback_deployment
            disable_maintenance_mode
            ;;
        --help)
            echo "使用方法: $0 [--initial|--update|--rollback]"
            echo ""
            echo "オプション:"
            echo "  --initial   : 初回デプロイ"
            echo "  --update    : 既存デプロイの更新"
            echo "  --rollback  : 前のバージョンにロールバック"
            echo "  --help      : このヘルプメッセージを表示"
            exit 0
            ;;
        *)
            print_info "オプションが指定されていません。初回デプロイを実行します..."
            print_info "利用可能なオプションを確認するには --help を使用してください"
            echo ""
            initial_deployment
            ;;
    esac
}

# すべての引数でメイン関数を実行
main "$@"
