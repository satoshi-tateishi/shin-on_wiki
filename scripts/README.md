# デプロイ自動化スクリプト

このディレクトリには、shin-on Wikiのデプロイを自動化するスクリプトが含まれています。

## 📋 スクリプト一覧

### `initial-deploy.sh`

初回デプロイを自動化するスクリプトです。以下の処理を自動的に実行します:

1. ✅ 依存関係の確認（PHP, Composer, Node.js, NPM）
2. ✅ .env ファイルの確認と修正
3. ✅ パーミッション設定
4. ✅ Composer 依存関係のインストール
5. ✅ NPM 依存関係のインストールとアセットビルド
6. ✅ ストレージリンク作成
7. ✅ Docker イメージのビルド
8. ✅ Docker コンテナの起動
9. ✅ データベースマイグレーション
10. ✅ Dockerコンテナ用パーミッション設定
11. ✅ キャッシュ最適化
12. ✅ 動作確認

## 🚀 使用方法

### 前提条件

以下がインストール済みであること:
- Git
- Docker & Docker Compose
- PHP 8.3以上（ホスト側）
- Composer（ホスト側）
- Node.js 20.x以上（ホスト側）

### 実行手順

1. **プロジェクトをクローン**

```bash
sudo mkdir -p /var/www
sudo chown $USER:$USER /var/www
cd /var/www
GIT_SSH_COMMAND='ssh -i ~/.ssh/id_ed25519_deploy' git clone git@github.com:satoshi-tateishi/shin-on_wiki.git
cd shin-on_wiki
git checkout release
```

2. **.env ファイルを設定**

```bash
cp .env.production.example .env
nano .env
```

必須設定項目:
- `APP_URL`: あなたのドメイン名
- `DB_PASSWORD`: データベースパスワード
- LINE WORKS設定
- Dropbox設定

3. **初回デプロイスクリプトを実行**

```bash
bash scripts/initial-deploy.sh
```

スクリプトが自動的に以下を実行します:
- 依存関係の確認
- パッケージのインストール
- Dockerコンテナの構築と起動
- データベースのセットアップ
- パーミッション設定
- 動作確認

4. **動作確認**

```bash
curl -I http://localhost:8083
```

HTTP 302（ログインページへのリダイレクト）が返ってくればOK！

5. **本番モードに切り替え**

```bash
sed -i 's/APP_DEBUG=true/APP_DEBUG=false/' .env
docker compose -f docker-compose.production.yml restart app
```

## ⚠️ 注意事項

### スクリプト実行時の要件

- スクリプトは `/var/www/shin-on_wiki` ディレクトリから実行されることを想定
- `sudo` 権限が必要な処理があります（パーミッション設定）
- エラーが発生した場合、スクリプトは即座に停止します（`set -e`）

### 手動で依存関係をインストールする場合

スクリプトを実行する前に、以下をインストールしてください:

```bash
# PHP 8.3とLaravel必須拡張機能
sudo apt update
sudo apt install -y php8.3-cli php8.3-fpm php8.3-mysql php8.3-xml php8.3-mbstring \
                    php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl php8.3-redis

# Composer
cd ~ && curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js 20.x
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs
```

## 🔧 トラブルシューティング

### スクリプトがエラーで停止した場合

1. **エラーメッセージを確認**
   - スクリプトは詳細なエラーメッセージを出力します

2. **ログを確認**
   ```bash
   docker compose -f docker-compose.production.yml logs app --tail=100
   ```

3. **コンテナの状態を確認**
   ```bash
   docker compose -f docker-compose.production.yml ps
   ```

4. **手動で修正後、スクリプトを再実行**
   - スクリプトは冪等性があるため、何度でも実行可能です

### よくあるエラー

#### PHP/Composer/Node.jsが見つからない
```
[ERROR] PHPがインストールされていません
```

→ 前提条件のセクションに従って依存関係をインストールしてください。

#### .envファイルが見つからない
```
[ERROR] .env ファイルが見つかりません
```

→ `.env.production.example` をコピーして `.env` を作成してください。

#### パーミッションエラー
```
Permission denied
```

→ `sudo` 権限があるユーザーで実行してください。

#### Dockerコンテナが起動しない
```
docker compose up -d fails
```

→ ログを確認: `docker compose -f docker-compose.production.yml logs`

## 📚 関連ドキュメント

詳細な手順は以下のドキュメントを参照してください:
- [DEPLOYMENT_HOME_SERVER.md](../claude/docs/DEPLOYMENT_HOME_SERVER.md) - 完全な手動デプロイ手順

## 🤝 貢献

スクリプトの改善提案や不具合報告は、GitHubのIssueまたはPull Requestでお願いします。
