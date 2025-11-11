# BookStack × LINE WORKS SSO 統合

このプロジェクトは、BookStackにLINE WORKS SSO認証を統合したものです。

## クイックスタート

### 前提条件

- PHP 8.1以上
- MySQL 5.7以上
- Apache 2.4以上（HTTPS対応）
- mkcert（HTTPS証明書生成用）

### セットアップ手順

1. **依存関係のインストール**
   ```bash
   composer install
   ```

2. **環境変数の設定**
   ```bash
   cp .env.example .env
   # .envファイルを編集してLINE WORKS設定を追加
   ```

3. **データベースのセットアップ**
   ```bash
   php artisan migrate
   ```

4. **HTTPS証明書の生成**
   ```bash
   mkcert localhost
   # 生成された証明書をApacheに設定
   ```

5. **Apacheの起動**
   ```bash
   sudo apachectl start
   ```

6. **PHPサーバーの起動**
   ```bash
   php artisan serve --host=0.0.0.0 --port=8083
   ```

7. **アクセス**
   ```
   https://localhost:8443
   ```

## 主な機能

- ✅ LINE WORKS OAuth 2.0/OpenID Connect 認証
- ✅ PKCE (Proof Key for Code Exchange) サポート
- ✅ ドメインベースのアクセス制御（@shin-on1981のみ）
- ✅ JWT署名検証スキップ（LINE WORKS非対応のため）
- ✅ セキュリティ補完（State/Nonce、HTTPS、ドメイン制限）

## 修正したファイル

### コアファイル

1. **app/Access/Oidc/OidcProviderSettings.php**
   - JWT公開鍵を任意に変更

2. **app/Access/Oidc/OidcJwtWithClaims.php**
   - JWT署名検証スキップ処理を追加

3. **app/Access/Oidc/OidcOAuthProvider.php**
   - LINE WORKS `domain` パラメータ対応
   - `getAccessTokenRequest()` オーバーライド

4. **app/Access/Oidc/OidcService.php**
   - `PostAuthOptionProvider` への変更
   - ドメイン検証機能の追加
   - LINE WORKS domain設定の追加

### 設定ファイル

5. **.env**
   - LINE WORKS認証情報
   - ドメイン設定
   - HTTPS URL設定

## 詳細ドキュメント

詳細な実装内容、セキュリティ考慮事項、トラブルシューティングについては、以下のドキュメントを参照してください：

**[LINEWORKS_SSO_SETUP.md](LINEWORKS_SSO_SETUP.md)**

## 環境変数

必要な環境変数（`.env`）:

```env
# Application
APP_URL=https://localhost:8443

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

## トラブルシューティング

### ログの確認

```bash
# 認証関連のログを表示
grep "OIDC" storage/logs/laravel.log | tail -20

# エラーログを表示
tail -f storage/logs/laravel.log
```

### よくある問題

1. **"invalid_request" エラー**
   - `.env` の `LINEWORKS_DOMAIN` が設定されているか確認
   - ログで `domain` パラメータが送信されているか確認

2. **ドメインアクセス拒否**
   - `@shin-on1981` ドメインのユーザーでログインしているか確認

3. **HTTPS証明書エラー**
   - `mkcert -install` を実行して認証局を追加

## セキュリティ

このプロジェクトでは、JWT署名検証をスキップしていますが、以下の方法でセキュリティを補完しています：

- ✅ State/Nonce検証（CSRF/リプレイ攻撃防止）
- ✅ HTTPS通信（盗聴防止）
- ✅ ドメイン制限（@shin-on1981のみ）
- ✅ PKCE（認証コード横取り防止）
- ✅ Issuer/Audience検証

詳細は [LINEWORKS_SSO_SETUP.md](LINEWORKS_SSO_SETUP.md) を参照してください。

## ライセンス

このプロジェクトは BookStack のライセンスに従います。

## 作成日

2025年11月11日
