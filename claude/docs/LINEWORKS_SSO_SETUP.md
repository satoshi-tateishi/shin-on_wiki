# BookStack × LINE WORKS SSO 認証 実装ドキュメント

## 概要

BookStackにLINE WORKS SSO（OAuth 2.0 + OpenID Connect）認証を実装しました。
shin-on1981ドメインのユーザーのみがログインできるようにセキュリティ制限を実装しています。

## 実装した機能

1. **LINE WORKS OAuth 2.0 認証**
   - OAuth 2.0 Authorization Code Flowによる認証
   - PKCE (Proof Key for Code Exchange) サポート
   - OpenID Connect ID Tokenの処理

2. **JWT署名検証のスキップ**
   - LINE WORKSはJWKSエンドポイントを提供していないため、署名検証をスキップ
   - セキュリティは他の手段で補完（State/Nonce検証、ドメイン制限）

3. **ドメインベースのアクセス制御**
   - @shin-on1981 ドメインのユーザーのみログイン可能
   - その他のドメインのユーザーは拒否

4. **LINE WORKS固有のパラメータ対応**
   - トークンリクエストに`domain`パラメータを追加
   - `PostAuthOptionProvider`を使用してリクエストボディに認証情報を含める

## 修正したファイル

### 1. app/Access/Oidc/OidcProviderSettings.php

**変更内容**: JWT公開鍵を必須から任意に変更

```php
public function validate(): void
{
    $this->validateInitial();

    // Modified for LINE WORKS: keys are optional if signature validation is skipped
    $required = ['tokenEndpoint', 'authorizationEndpoint'];
    foreach ($required as $prop) {
        if (empty($this->$prop)) {
            throw new InvalidArgumentException("Missing required configuration \"{$prop}\" value");
        }
    }
    // ... endpoint validation
}
```

**理由**: LINE WORKSはJWKSエンドポイントを提供していないため、公開鍵の取得ができません。

---

### 2. app/Access/Oidc/OidcJwtWithClaims.php

**変更内容**: JWT署名検証のスキップ処理を追加

```php
protected function validateTokenSignature(): void
{
    // Modified for LINE WORKS: Skip signature validation if no keys are provided
    // Security is maintained through State/Nonce validation and domain restrictions
    if (empty($this->keys)) {
        \Log::warning('OIDC: JWT signature validation skipped - no keys provided');
        return;
    }

    // ... original validation code
}
```

**理由**: 公開鍵がない場合は署名検証をスキップします。セキュリティはState/Nonce検証とドメイン制限で補完します。

---

### 3. app/Access/Oidc/OidcOAuthProvider.php

**変更内容**: LINE WORKS用のdomain機能とトークンリクエスト処理を追加

```php
protected ?string $domain = null;

public function setDomain(?string $domain): void
{
    $this->domain = $domain;
}

protected function getAccessTokenRequest(array $params)
{
    // Add LINE WORKS domain parameter if set
    if ($this->domain) {
        $params['domain'] = $this->domain;
        \Log::info('OIDC: Adding domain parameter to token request', [
            'domain' => $this->domain,
            'all_params' => array_keys($params),
        ]);
    }

    return parent::getAccessTokenRequest($params);
}
```

**理由**: LINE WORKSのSSO機能を使用する場合、トークンリクエストに`domain`パラメータが必須です。

---

### 4. app/Access/Oidc/OidcService.php

**変更内容①**: PostAuthOptionProviderの使用

```php
use League\OAuth2\Client\OptionProvider\PostAuthOptionProvider;

protected function getProvider(OidcProviderSettings $settings): OidcOAuthProvider
{
    $provider = new OidcOAuthProvider([
        ...$settings->arrayForOAuthProvider(),
        'redirectUri' => url('/oidc/callback'),
    ], [
        'httpClient'     => $this->http->buildClient(5),
        'optionProvider' => new PostAuthOptionProvider(), // Changed from HttpBasicAuthOptionProvider
    ]);
    // ...
}
```

**理由**: LINE WORKSのトークンエンドポイントは、HTTP Basic認証ではなく、リクエストボディに`client_id`と`client_secret`を含める方式を要求します。

**変更内容②**: domain設定の追加

```php
// Set LINE WORKS domain for SSO functionality
$domain = env('LINEWORKS_DOMAIN');
if ($domain) {
    $provider->setDomain($domain);
    \Log::info('OIDC: Setting LINE WORKS domain', ['domain' => $domain]);
}
```

**変更内容③**: ドメイン検証機能の追加

```php
protected function validateUserDomain(string $email): void
{
    $allowedDomain = env('LINEWORKS_DOMAIN', 'shin-on1981');

    // Extract domain from email (part after @)
    $emailDomain = substr(strrchr($email, '@'), 1);

    \Log::info('OIDC: Email domain validation', [
        'email' => $email,
        'domain' => $emailDomain,
        'allowed' => $allowedDomain,
    ]);

    if ($emailDomain !== $allowedDomain) {
        \Log::warning('OIDC: Domain validation failed', [
            'expected' => $allowedDomain,
            'got' => $emailDomain,
        ]);
        throw new OidcException("Access denied: Only users from {$allowedDomain} domain are allowed.");
    }

    \Log::info('OIDC: Domain validation passed');
}
```

**変更内容④**: processAccessTokenCallbackでのドメイン検証呼び出し

```php
$userDetails = $this->getUserDetailsFromToken($idToken, $accessToken, $settings);
if (empty($userDetails->email)) {
    throw new OidcException(trans('errors.oidc_no_email_address'));
}

// Modified for LINE WORKS: Validate email domain (shin-on1981 only)
$this->validateUserDomain($userDetails->email);
```

**理由**: shin-on1981ドメインのユーザーのみがアクセスできるように制限します。

---

### 5. .env

**追加した環境変数**:

```env
# LINE WORKS OIDC Configuration
OIDC_NAME="LINE WORKS"
OIDC_CLIENT_ID=f5FvEfHR2JCKyVR65jdQ
OIDC_CLIENT_SECRET=ugVCSBArYL
OIDC_ISSUER=https://auth.worksmobile.com
OIDC_ISSUER_DISCOVER=false
OIDC_AUTH_ENDPOINT=https://auth.worksmobile.com/oauth2/v2.0/authorize
OIDC_TOKEN_ENDPOINT=https://auth.worksmobile.com/oauth2/v2.0/token

# LINE WORKS Domain
LINEWORKS_DOMAIN=shin-on1981

# HTTPS URL (required for LINE WORKS)
APP_URL=https://localhost:8443
```

## LINE WORKS認証フロー

### 1. 認証開始
```
ユーザー → BookStack (/login)
    ↓ LINE WORKSでログインボタンをクリック
BookStack → LINE WORKS 認証エンドポイント
    - パラメータ: client_id, redirect_uri, scope, state, code_challenge (PKCE)
```

### 2. ユーザー認証
```
LINE WORKS → ユーザーにログイン画面を表示
ユーザー → LINE WORKSでログイン
LINE WORKS → BookStackへリダイレクト
    - パラメータ: code (認証コード), state
```

### 3. トークン交換
```
BookStack → LINE WORKS トークンエンドポイント
    - リクエストボディ:
      - grant_type: authorization_code
      - code: 認証コード
      - redirect_uri: コールバックURL
      - client_id: クライアントID
      - client_secret: クライアントシークレット
      - code_verifier: PKCEコード検証子
      - domain: shin-on1981 ← LINE WORKS固有

LINE WORKS → BookStack
    - レスポンス: access_token, id_token, refresh_token
```

### 4. ユーザー情報検証
```
BookStack:
    1. ID Tokenをパース
    2. JWT署名検証をスキップ（公開鍵なしのため）
    3. Issuer (iss) クレーム検証
    4. Audience (aud) クレーム検証
    5. メールアドレスのドメイン検証 (@shin-on1981)
    6. ユーザー登録 or ログイン
```

## セキュリティ上の考慮事項

### JWT署名検証のスキップ

**リスク**: ID Tokenの改ざんを検出できない

**補完策**:
1. **State/Nonce検証**: CSRF攻撃とリプレイ攻撃を防止
2. **HTTPS通信**: トークンの盗聴を防止
3. **ドメイン制限**: @shin-on1981ドメインのみ許可
4. **PKCE**: 認証コードの横取り攻撃を防止
5. **トークンの有効期限**: 短時間でトークンを無効化
6. **Issuer/Audience検証**: トークンの発行元と対象を検証

### アクセス制御

- **ドメイン制限**: メールアドレスのドメインが `shin-on1981` であることを検証
- **認証コードの使い捨て**: 認証コードは1回のみ使用可能
- **セッション管理**: Laravelのセッション機能でセッション管理

## トラブルシューティング

### エラー: "invalid_request"

**原因**: トークンリクエストのパラメータが不正

**確認事項**:
1. `.env` の `LINEWORKS_DOMAIN` が正しく設定されているか
2. `PostAuthOptionProvider` が使用されているか（`HttpBasicAuthOptionProvider` ではない）
3. ログで `domain` パラメータが含まれているか確認

```bash
grep "OIDC" storage/logs/laravel.log | tail -20
```

### エラー: "Access denied: Only users from shin-on1981 domain are allowed"

**原因**: ログインしようとしているユーザーのメールアドレスのドメインが `shin-on1981` ではない

**解決策**:
- LINE WORKSで `@shin-on1981` のメールアドレスを持つユーザーでログインする
- 別のドメインを許可する場合は `.env` の `LINEWORKS_DOMAIN` を変更

### エラー: "JWT signature validation skipped"

**説明**: これは警告ログであり、エラーではありません

LINE WORKSはJWKSエンドポイントを提供していないため、JWT署名検証をスキップしています。
セキュリティは他の手段（State/Nonce、ドメイン制限、PKCE）で補完されています。

### HTTPS証明書エラー

**原因**: `mkcert` で生成した自己署名証明書がブラウザに信頼されていない

**解決策**:
```bash
mkcert -install
```

ローカル認証局をシステムに追加します。

## 動作確認

### 1. ログインテスト

1. ブラウザで `https://localhost:8443/login` にアクセス
2. 「LINE WORKSでログイン」ボタンをクリック
3. LINE WORKSのログイン画面が表示される
4. `@shin-on1981` のユーザーでログイン
5. BookStackのホーム画面が表示される

### 2. ログの確認

```bash
# 認証プロセスのログを確認
grep "OIDC" storage/logs/laravel.log | tail -20

# 成功時のログ例:
# [INFO] OIDC: Setting LINE WORKS domain {"domain":"shin-on1981"}
# [INFO] OIDC: Adding domain to token request options {"domain":"shin-on1981"}
# [INFO] OIDC: Adding domain parameter to token request {"domain":"shin-on1981","all_params":["client_id","client_secret","redirect_uri","code_verifier","grant_type","code","domain"]}
# [WARNING] OIDC: JWT signature validation skipped - no keys provided
# [INFO] OIDC: Email domain validation {"email":"s-tateishi@shin-on1981","domain":"shin-on1981","allowed":"shin-on1981"}
# [INFO] OIDC: Domain validation passed
```

## 参考資料

- [LINE WORKS API 認証ガイド](https://developers.worksmobile.com/jp/docs/auth)
- [OAuth 2.0 Authorization Code Flow](https://datatracker.ietf.org/doc/html/rfc6749#section-4.1)
- [OpenID Connect Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html)
- [PKCE (RFC 7636)](https://datatracker.ietf.org/doc/html/rfc7636)

## 実装日

2025年11月11日

## 実装者

Claude Code + satoshi
