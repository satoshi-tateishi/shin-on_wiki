# shin·on Wiki by BookStack - LINE WORKS SSO統合

BookStackをベースにLINE WORKS SSO認証を統合したWikiシステムです。

> 📖 **オリジナルのBookStack READMEは [README_BOOKSTACK_ORIGINAL.md](./README_BOOKSTACK_ORIGINAL.md) に保存されています。**

## 🌟 特徴

- 📚 **BookStack**: オープンソースのWikiシステム（v25.11）
- 🔐 **LINE WORKS SSO**: OAuth 2.0 + OpenID Connect認証
- 🛡️ **セキュアな認証**: PKCE、State/Nonce検証、ドメイン制限
- 🏢 **企業向け**: shin-on1981ドメインのユーザーのみアクセス可能

## 🚀 クイックスタート

```bash
# 依存関係のインストール
composer install

# 環境変数の設定
cp .env.example .env
# .envファイルを編集してLINE WORKS設定を追加

# データベースのセットアップ
php artisan migrate

# サーバーの起動
php artisan serve --host=0.0.0.0 --port=8083

# ブラウザでアクセス
# https://localhost:8443
```

## 📚 ドキュメント

詳細なドキュメントは **[claude/docs](./claude/docs)** ディレクトリを参照してください。

| ドキュメント | 内容 | 対象読者 |
|------------|------|---------|
| **[claude/docs/README.md](./claude/docs/README.md)** | ドキュメント目次・ナビゲーション | すべて |
| **[クイックスタートガイド](./claude/docs/README_LINEWORKS.md)** | セットアップ手順、環境変数設定 | 初めてセットアップする人 |
| **[詳細実装ドキュメント](./claude/docs/LINEWORKS_SSO_SETUP.md)** | 実装の詳細、認証フロー、セキュリティ | 開発者、技術担当者 |
| **[変更内容サマリー](./claude/docs/CHANGES.md)** | 変更箇所の一覧、技術的課題と解決策 | コードレビュー担当者 |
| **[バックアップ・復元ガイド](./claude/docs/BACKUP_RESTORE.md)** | Dropboxバックアップ・復元、サムネイル再生成 | システム運用担当者 |

### 📖 推奨される読む順番

1. **初めてセットアップする場合**
   - [クイックスタートガイド](./claude/docs/README_LINEWORKS.md) → [詳細実装ドキュメント](./claude/docs/LINEWORKS_SSO_SETUP.md)

2. **コードレビューの場合**
   - [変更内容サマリー](./claude/docs/CHANGES.md) → [詳細実装ドキュメント](./claude/docs/LINEWORKS_SSO_SETUP.md)

3. **トラブルシューティングの場合**
   - [クイックスタートガイド](./claude/docs/README_LINEWORKS.md) の「よくある問題」セクション

4. **バックアップ・復元の場合**
   - [バックアップ・復元ガイド](./claude/docs/BACKUP_RESTORE.md)

## 🎯 主な機能

### LINE WORKS認証
- ✅ OAuth 2.0 Authorization Code Flow
- ✅ OpenID Connect ID Token
- ✅ PKCE (Proof Key for Code Exchange)

### セキュリティ
- ✅ JWT署名検証スキップ（LINE WORKS非対応のため）
- ✅ State/Nonce検証（CSRF/リプレイ攻撃防止）
- ✅ HTTPS通信（盗聴防止）
- ✅ ドメイン制限（@shin-on1981のみ）
- ✅ Issuer/Audience検証

### アクセス制御
- ✅ メールドメインベースの認証
- ✅ shin-on1981ドメインのユーザーのみログイン可能
- ✅ 不正アクセスの自動拒否

### Dropboxバックアップ・復元
- ✅ 自動Dropboxバックアップ（データベース + ファイル）
- ✅ Dropboxからの復元
- ✅ 復元後の自動サムネイル再生成
- ✅ 手動サムネイル再生成コマンド

## 🔧 技術スタック

| カテゴリ | 技術 | バージョン |
|---------|------|----------|
| Backend | PHP | 8.4.3 |
| Framework | Laravel | - |
| Wiki Platform | BookStack | v25.11 |
| Database | MySQL | 5.7+ |
| Web Server | Apache | 2.4+ (HTTPS) |
| Authentication | OAuth 2.0 + OpenID Connect | - |
| Security | PKCE, State/Nonce, Domain Validation | - |

## 📁 プロジェクト構成

```
shin-on_wiki/
├── app/Access/Oidc/           # OIDC認証実装（修正済み）
│   ├── OidcProviderSettings.php    # JWT公開鍵を任意に変更
│   ├── OidcJwtWithClaims.php       # JWT署名検証スキップ処理
│   ├── OidcOAuthProvider.php       # LINE WORKS domain対応
│   └── OidcService.php             # PostAuthOptionProvider、ドメイン検証
├── claude/                     # Claude Code関連
│   └── docs/                   # ドキュメント
│       ├── README.md           # ドキュメント目次
│       ├── README_LINEWORKS.md # クイックスタートガイド
│       ├── LINEWORKS_SSO_SETUP.md # 詳細実装ドキュメント
│       ├── CHANGES.md          # 変更内容サマリー
│       └── BACKUP_RESTORE.md   # バックアップ・復元ガイド
├── .env                        # 環境変数（LINE WORKS設定含む）
├── README.md                   # このファイル
└── README_BOOKSTACK_ORIGINAL.md # オリジナルのBookStack README
```

## 🔐 環境変数

### 環境変数ファイルの構成

| ファイル | 用途 | 説明 |
|---------|------|------|
| `.env` | 実際の設定ファイル | gitignore対象、本番/開発環境の実際の値を設定 |
| `.env.example` | LINE WORKS SSO用テンプレート | このプロジェクト用にカスタマイズ済み |
| `.env.example.complete` | BookStack全機能リファレンス | 公式の完全な環境変数一覧（413行） |

> 💡 **セットアップ時**: `.env.example`をコピーして`.env`を作成してください
> ```bash
> cp .env.example .env
> ```

### 主要な環境変数（`.env`）:

```env
# Application
APP_URL=https://localhost:8443
APP_DEBUG=true

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3308
DB_DATABASE=shin_on_wiki

# LINE WORKS OIDC
OIDC_NAME="LINE WORKS"
OIDC_CLIENT_ID=your_client_id
OIDC_CLIENT_SECRET=your_client_secret
OIDC_ISSUER=https://auth.worksmobile.com
OIDC_ISSUER_DISCOVER=false
OIDC_AUTH_ENDPOINT=https://auth.worksmobile.com/oauth2/v2.0/authorize
OIDC_TOKEN_ENDPOINT=https://auth.worksmobile.com/oauth2/v2.0/token

# LINE WORKS Domain
LINEWORKS_DOMAIN=shin-on1981
```

詳細は [クイックスタートガイド](./claude/docs/README_LINEWORKS.md) を参照してください。

## 🐛 トラブルシューティング

### よくある問題

**1. "invalid_request" エラー**
```bash
# ログを確認
grep "OIDC" storage/logs/laravel.log | tail -20

# LINEWORKS_DOMAINが設定されているか確認
grep "LINEWORKS_DOMAIN" .env
```

**2. ドメインアクセス拒否**
- @shin-on1981 ドメインのユーザーでログインしているか確認

**3. HTTPS証明書エラー**
```bash
# ローカル認証局をインストール
mkcert -install
```

詳細なトラブルシューティングは [詳細実装ドキュメント](./claude/docs/LINEWORKS_SSO_SETUP.md) を参照してください。

## 📊 動作確認

### 認証ログの確認

```bash
# 最新の認証ログを表示
grep "OIDC" storage/logs/laravel.log | tail -20

# 成功時のログ例:
# [INFO] OIDC: Setting LINE WORKS domain {"domain":"shin-on1981"}
# [INFO] OIDC: Adding domain to token request options
# [WARNING] OIDC: JWT signature validation skipped - no keys provided
# [INFO] OIDC: Email domain validation {"email":"user@shin-on1981"...}
# [INFO] OIDC: Domain validation passed
```

### ログイン動作確認

1. `https://localhost:8443/login` にアクセス
2. 「LINE WORKSでログイン」をクリック
3. LINE WORKSでログイン
4. BookStackホーム画面が表示される

## 🔒 セキュリティ

### 実装されているセキュリティ対策

| 対策 | 実装状況 | 説明 |
|------|---------|------|
| State検証 | ✅ | CSRF攻撃防止 |
| Nonce検証 | ✅ | リプレイ攻撃防止 |
| PKCE | ✅ | 認証コード横取り防止 |
| HTTPS通信 | ✅ | 盗聴防止 |
| ドメイン制限 | ✅ | 不正アクセス防止 |
| Issuer検証 | ✅ | トークン発行元検証 |
| Audience検証 | ✅ | トークン対象検証 |

### JWT署名検証について

LINE WORKSはJWKSエンドポイントを提供していないため、JWT署名検証をスキップしています。
セキュリティは上記の他の対策で補完されています。

詳細なセキュリティレビューは [変更内容サマリー](./claude/docs/CHANGES.md) を参照してください。

## 🚦 稼働状況

### アクセスURL
- **本番**: https://shin-on.mydns.jp
- **ローカル開発**: https://localhost:8443
- **PHP開発サーバー**: http://localhost:8083

### 現在のステータス
- ✅ LINE WORKS SSO認証: 正常動作
- ✅ ドメイン検証: 正常動作
- ✅ セキュリティ対策: 実装済み
- ✅ 自動デプロイ: GitHub Actions連携

## 📝 修正したファイル一覧

1. **app/Access/Oidc/OidcProviderSettings.php** - JWT公開鍵を任意に変更
2. **app/Access/Oidc/OidcJwtWithClaims.php** - JWT署名検証スキップ処理
3. **app/Access/Oidc/OidcOAuthProvider.php** - LINE WORKS domain対応
4. **app/Access/Oidc/OidcService.php** - PostAuthOptionProvider、ドメイン検証
5. **.env** - LINE WORKS設定追加

詳細は [変更内容サマリー](./claude/docs/CHANGES.md) を参照してください。

## 🔗 関連リンク

- **[GitHubリポジトリ](https://github.com/satoshi-tateishi/shin-on_wiki)** - このプロジェクトのソースコード
- [BookStack公式サイト](https://www.bookstackapp.com/)
- [LINE WORKS API ドキュメント](https://developers.worksmobile.com/jp/docs/auth)
- [OAuth 2.0 RFC 6749](https://datatracker.ietf.org/doc/html/rfc6749)
- [OpenID Connect Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html)

## 📅 バージョン情報

- **プロジェクト名**: shin-on_wiki
- **BookStack バージョン**: v25.11
- **PHP バージョン**: 8.4.3
- **最終更新日**: 2025年11月24日

## 👥 作成者

Claude Code + satoshi

## 📄 ライセンス

このプロジェクトは BookStack のライセンス（MIT）に従います。

---

**📖 詳細なドキュメントは [claude/docs](./claude/docs) ディレクトリを参照してください。**

**🔍 トラブルシューティングが必要な場合は [クイックスタートガイド](./claude/docs/README_LINEWORKS.md) を確認してください。**
