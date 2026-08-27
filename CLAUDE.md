# shin-on Wiki - CLAUDE.md

## プロジェクト概要

**BookStack v25.11** をベースにした社内Wiki。認証部分を **shin-on Portal JWT SSO** にカスタマイズ済み。
自宅サーバー（Ubuntu）上で Docker (PHP + MySQL) により稼働。

- URL: `https://wiki.shin-on1981.com`
- ベース: [BookStack](https://www.bookstackapp.com/) (OSSのWikiプラットフォーム)
- 独自ドキュメント: `claude/docs/` 参照

## 技術スタック

| 分類 | 技術 |
|------|------|
| バックエンド | PHP 8.3+, Laravel 12 |
| フロントエンド | TypeScript, SCSS, ESBuild |
| データベース | MySQL 5.7+ |
| 認証 | shin-on Portal JWT SSO (RS256) |
| インフラ | Docker, Apache |
| CI/CD | GitHub Actions (`release` ブランチ → 自動デプロイ) |

## 開発環境セットアップ

```bash
# 1. 環境変数設定
cp .env.example .env
# .env を編集（DB設定、Portal JWT設定等）

# 2. Docker 起動
docker-compose up -d

# 3. 依存関係インストール（コンテナ内）
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate

# 4. フロントエンドビルド
npm install
npm run build        # 開発ビルド
npm run dev          # ウォッチモード (livereload)
npm run production   # 本番ビルド
```

## よく使うコマンド

```bash
# PHP
./vendor/bin/phpcs                  # コードスタイルチェック
./vendor/bin/phpcs --fix            # 自動修正
./vendor/bin/phpstan analyse        # 静的解析
./vendor/bin/phpunit                # PHPUnit テスト全実行
./vendor/bin/phpunit --testsuite=Auth  # 認証テストのみ

# JavaScript / TypeScript
npm run lint         # ESLint
npm run fix          # ESLint 自動修正
npm run ts:lint      # TypeScript 型チェック
npm test             # Jest テスト
```

## アーキテクチャ

BookStack のコアは変更せず、カスタムコードを専用ディレクトリに分離している。

### 重要ディレクトリ

```
app/
├── Access/              # 認証層全体 (BookStack標準 + カスタム)
│   ├── PortalJwt/       # カスタム: Portal JWT SSO実装
│   │   ├── PortalJwtService.php   # JWT検証・ユーザー作成
│   │   └── PortalJwtException.php
│   ├── Oidc/            # BookStack標準: OIDC/OpenID Connect
│   ├── Controllers/     # 認証コントローラー群
│   ├── LoginService.php # ログイン共通処理
│   └── ...
├── Config/              # アプリ設定 (※ config/ ではなくここ)
│   ├── portal_jwt.php   # Portal JWT設定
│   └── auth.php         # 認証メソッド設定
├── Entities/            # コンテンツエンティティ (Book, Page, Chapter等)
├── Http/Middleware/     # カスタムミドルウェア
│   ├── Authenticate.php # Portal JWT検証ロジック追加済み
│   └── EncryptCookies.php  # portal_jwt クッキーを暗号化除外
├── Services/            # カスタムサービス
│   ├── BackupService.php    # Dropboxバックアップ
│   └── DropboxService.php
└── Users/               # ユーザー管理

claude/docs/             # カスタム実装ドキュメント
resources/
├── js/                  # TypeScript ソースコード
├── sass/                # SCSS スタイルシート
└── views/               # Blade テンプレート
```

> **注意**: 設定ファイルは `config/` (Laravel標準) ではなく `app/Config/` に置かれている。

## 認証: Portal JWT SSO

### 認証フロー

```
ユーザーが /login にアクセス
  → LoginController が Portal ログインURLにリダイレクト
  → Portal でユーザー認証 → portal_jwt クッキーを付与して Wiki にリダイレクト
  → Authenticate ミドルウェアが portal_jwt クッキーの JWT (RS256) を検証
  → PortalJwtService が JWKS を取得・キャッシュ (TTL: 3600s) して署名検証
  → JWT の sub クレームで users.external_auth_id と照合、なければ自動作成
  → ログイン完了
```

### 環境変数

```env
AUTH_METHOD=portal_jwt

# Portal JWT設定
PORTAL_JWT_ISSUER=https://portal.shin-on1981.com
PORTAL_JWT_AUDIENCE=shin-on-apps
PORTAL_JWKS_URL=https://portal.shin-on1981.com/api/jwks/
PORTAL_LOGIN_URL=https://portal.shin-on1981.com/login/
PORTAL_LOGOUT_URL=https://portal.shin-on1981.com/logout/

# 開発環境用 (Dockerネットワーク経由)
# PORTAL_JWKS_URL=http://portal-app:8000/api/jwks/
# PORTAL_LOGIN_URL=http://localhost:8000/login/
```

### JWT クレーム

| クレーム | 用途 |
|---------|------|
| `sub` | ユーザー識別子 → `users.external_auth_id` |
| `email` | メールアドレス |
| `family_name` / `given_name` | 氏名 |
| `iss` | Issuer 検証 |
| `aud` | Audience 検証 |
| `is_active` | アカウント有効フラグ |

## BookStack カスタマイズ上の注意

1. **BookStack本体ファイルを変更する場合**: アップグレード時にコンフリクトが発生しやすい。変更箇所をコメントで明示し、`git diff`で追跡できるようにする。

2. **カスタムコードの配置**:
   - 新機能 → `app/Access/PortalJwt/`, `app/Services/` 等の専用ディレクトリに追加
   - BookStack標準ファイルへの変更は最小限に

3. **削除済みコード**: LINE WORKS OTP認証 (2FA) は削除済みだが、DBマイグレーション (`2025_11_26_000001_add_lineworks_otp_fields_to_users_table.php`) と言語ファイル (`lang/*/lineworks_otp.php`) は残存。

## テスト

```
tests/
├── Unit/       # ユニットテスト
├── Auth/       # 認証テスト (AuthTest, OidcTest, SocialAuthTest 等)
├── Entity/     # エンティティテスト
├── Api/        # REST API テスト
├── Permissions/ # 権限テスト
└── User/       # ユーザー管理テスト
```

テストDB: `mysql_testing` (phpunit.xml参照)

Portal JWT の認証テストは `tests/Auth/` に追加すること。

## デプロイ

- `release` ブランチへの push → GitHub Actions が自動デプロイ
- デプロイ先: 自宅サーバー `/var/www/shin-on_wiki` (SSH port 56834)
- デプロイ内容: git pull → composer install → npm production build → Docker再起動 → migrate
- 詳細: `claude/docs/DEPLOYMENT.md`, `.github/workflows/deploy.yml`

## Dropbox バックアップ

毎日午前2時 (Asia/Tokyo) に自動バックアップ。保持期間: 30日。

```env
DROPBOX_CLIENT_ID=...
DROPBOX_CLIENT_SECRET=...
DROPBOX_BACKUP_FOLDER=/shin-on-wiki-backups
BACKUP_RETENTION_DAYS=30
BACKUP_TIMEZONE=Asia/Tokyo
```
