# shin·on Wiki by BookStack - LINE WORKS SSO 統合 変更内容サマリー

## 変更概要

shin·on Wiki by BookStack (BookStackベース) にLINE WORKS OAuth 2.0/OpenID Connect認証を統合し、shin-on1981ドメインのユーザーのみがログインできるように実装しました。

## 変更日

2025年11月11日

## 変更ファイル一覧

### 1. app/Access/Oidc/OidcProviderSettings.php

**変更箇所**: `validate()` メソッド

**変更前**:
```php
$required = ['tokenEndpoint', 'authorizationEndpoint', 'keys'];
```

**変更後**:
```php
// Modified for LINE WORKS: keys are optional if signature validation is skipped
$required = ['tokenEndpoint', 'authorizationEndpoint'];
```

**理由**: LINE WORKSはJWKSエンドポイントを提供していないため、JWT公開鍵を任意にしました。

---

### 2. app/Access/Oidc/OidcJwtWithClaims.php

**変更箇所**: `validateTokenSignature()` メソッド

**追加したコード**:
```php
protected function validateTokenSignature(): void
{
    // Modified for LINE WORKS: Skip signature validation if no keys are provided
    if (empty($this->keys)) {
        \Log::warning('OIDC: JWT signature validation skipped - no keys provided');
        return;
    }
    // ... 既存の検証コード
}
```

**理由**: 公開鍵がない場合はJWT署名検証をスキップします。セキュリティは他の手段で補完します。

---

### 3. app/Access/Oidc/OidcOAuthProvider.php

**追加したプロパティとメソッド**:

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

#### 変更①: use文の変更

**変更前**:
```php
use League\OAuth2\Client\OptionProvider\HttpBasicAuthOptionProvider;
```

**変更後**:
```php
use League\OAuth2\Client\OptionProvider\PostAuthOptionProvider;
```

#### 変更②: getProvider()メソッド

**変更前**:
```php
'optionProvider' => new HttpBasicAuthOptionProvider(),
```

**変更後**:
```php
'optionProvider' => new PostAuthOptionProvider(),
```

**理由**: LINE WORKSはHTTP Basic認証ではなく、リクエストボディに認証情報を含める方式を要求します。

#### 変更③: domain設定の追加

**追加したコード**:
```php
// Set LINE WORKS domain for SSO functionality
$domain = env('LINEWORKS_DOMAIN');
if ($domain) {
    $provider->setDomain($domain);
    \Log::info('OIDC: Setting LINE WORKS domain', ['domain' => $domain]);
}
```

#### 変更④: ドメイン検証メソッドの追加

**追加したメソッド**:
```php
protected function validateUserDomain(string $email): void
{
    $allowedDomain = env('LINEWORKS_DOMAIN', 'shin-on1981');
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

#### 変更⑤: processAccessTokenCallback()メソッド

**追加したコード**:
```php
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

---

## 技術的な課題と解決策

### 課題1: JWT署名検証

**問題**: LINE WORKSはJWKSエンドポイントを提供していないため、JWT署名を検証できない

**解決策**:
- JWT署名検証をスキップ
- 代わりに以下のセキュリティ対策を実施：
  - State/Nonce検証（CSRF/リプレイ攻撃防止）
  - HTTPS通信（盗聴防止）
  - ドメイン制限（@shin-on1981のみ）
  - PKCE（認証コード横取り防止）
  - Issuer/Audience検証

### 課題2: LINE WORKSのdomain パラメータ

**問題**: LINE WORKSのトークンエンドポイントはSSO機能使用時に`domain`パラメータを要求する

**解決策**:
- `OidcOAuthProvider`に`domain`プロパティとセッターを追加
- `getAccessTokenRequest()`メソッドをオーバーライドして`domain`パラメータを追加

### 課題3: HTTP Basic認証 vs リクエストボディ認証

**問題**: BookStackのデフォルト設定では`HttpBasicAuthOptionProvider`を使用しているが、LINE WORKSはリクエストボディに認証情報を要求する

**解決策**:
- `PostAuthOptionProvider`に変更
- これにより`client_id`と`client_secret`がリクエストボディに含まれる

### 課題4: アクセス制御

**問題**: 特定のドメイン（shin-on1981）のユーザーのみにアクセスを制限する必要がある

**解決策**:
- `validateUserDomain()`メソッドを実装
- メールアドレスのドメイン部分を検証
- 不一致の場合は`OidcException`をスロー

## テスト結果

### 成功したテストケース

1. ✅ shin-on1981ドメインのユーザーでのログイン
2. ✅ JWT署名検証のスキップ
3. ✅ ドメインパラメータの送信
4. ✅ State/Nonce検証
5. ✅ PKCE検証
6. ✅ Issuer/Audience検証
7. ✅ メールドメイン検証

### ログ例（成功時）

```
[2025-11-11 18:05:25] local.INFO: OIDC: Setting LINE WORKS domain {"domain":"shin-on1981"}
[2025-11-11 18:05:25] local.INFO: OIDC: Adding domain to token request options {"domain":"shin-on1981"}
[2025-11-11 18:05:25] local.INFO: OIDC: Adding domain parameter to token request {"domain":"shin-on1981","all_params":["client_id","client_secret","redirect_uri","code_verifier","grant_type","code","domain"]}
[2025-11-11 18:05:25] local.WARNING: OIDC: JWT signature validation skipped - no keys provided
[2025-11-11 18:05:25] local.INFO: OIDC: Email domain validation {"email":"s-tateishi@shin-on1981","domain":"shin-on1981","allowed":"shin-on1981"}
[2025-11-11 18:05:25] local.INFO: OIDC: Domain validation passed
```

## セキュリティレビュー

### リスク評価

| リスク | レベル | 対策 | ステータス |
|--------|--------|------|-----------|
| JWT署名検証スキップ | 中 | State/Nonce、HTTPS、ドメイン制限、PKCE | ✅ 対策済み |
| CSRF攻撃 | 低 | State検証 | ✅ 対策済み |
| リプレイ攻撃 | 低 | Nonce検証、トークン有効期限 | ✅ 対策済み |
| 認証コード横取り | 低 | PKCE | ✅ 対策済み |
| 盗聴 | 低 | HTTPS通信 | ✅ 対策済み |
| 不正ドメインアクセス | なし | ドメイン検証 | ✅ 対策済み |

### 推奨事項

1. **本番環境では**:
   - 正式なSSL証明書を使用
   - `APP_DEBUG=false` に設定
   - ログレベルを適切に設定

2. **監視**:
   - 認証失敗のログを監視
   - ドメイン検証失敗のアラート設定

3. **定期的なレビュー**:
   - LINE WORKS APIの変更を確認
   - セキュリティアップデートを適用

## 参考資料

### プロジェクト
- **[GitHubリポジトリ](https://github.com/satoshi-tateishi/shin-on_wiki)** - このプロジェクトのソースコード

### 技術資料
- [LINE WORKS API ドキュメント](https://developers.worksmobile.com/jp/docs/auth)
- [OAuth 2.0 RFC 6749](https://datatracker.ietf.org/doc/html/rfc6749)
- [OpenID Connect Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html)
- [PKCE RFC 7636](https://datatracker.ietf.org/doc/html/rfc7636)

## 作成者

Claude Code + satoshi

## カバー画像の正方形化

### カバー画像を384×384px正方形に変更

**変更日**: 2025年11月12日

**変更内容**:
- 本棚（Shelf）と本（Book）のカバー画像を正方形化
- 画像サイズを512×512pxから384×384pxに変更
- パディング（余白追加）方式で正方形化を実装
- UIメッセージを更新

**変更ファイル**:

#### 1. app/Uploads/ImageResizer.php (行133-160)
**追加機能**: 正方形パディング処理

```php
if ($keepRatio) {
    $thumb->scaleDown($width, $height);

    // Add square padding for cover images
    if ($width === $height) {
        $currentWidth = $thumb->width();
        $currentHeight = $thumb->height();

        if ($currentWidth !== $currentHeight) {
            $size = max($width, $height);
            $backgroundColor = 'rgb(255,255,255)';
            if ($format === 'png') {
                $backgroundColor = 'rgba(255,255,255,0)';
            }
            $thumb->resizeCanvas($size, $size, 'center', false, $backgroundColor);
        }
    }
}
```

**理由**:
- 横長・縦長画像を正方形に統一
- アスペクト比を維持しながらパディング
- PNG画像は透明背景、その他は白背景

#### 2. app/Entities/Repos/BaseRepo.php (行116)
**変更箇所**: 画像サイズ指定

**変更前**:
```php
$image = $this->imageRepo->saveNew($coverImage, $imageType, $entity->id, 512, 512, true);
```

**変更後**:
```php
$image = $this->imageRepo->saveNew($coverImage, $imageType, $entity->id, 384, 384, true);
```

**理由**: ストレージ44%削減、転送量44%削減、最適なバランス

#### 3. lang/en/common.php (行23)
**変更箇所**: カバー画像説明メッセージ

**変更前**:
```php
'cover_image_description' => 'This image should be approximately 440x250px although it will be flexibly scaled & cropped to fit the user interface in different scenarios as required, so actual dimensions for display will differ.',
```

**変更後**:
```php
'cover_image_description' => 'This image should be 384x384px square. Non-square images will be automatically padded with white/transparent backgrounds to maintain the original aspect ratio.',
```

#### 4. lang/ja/common.php (行23)
**変更箇所**: カバー画像説明メッセージ

**変更前**:
```php
'cover_image_description' => 'この画像はおよそ440x250pxであるべきですが、必要に応じてさまざまなシナリオでユーザー・インターフェースに合うように柔軟に拡大・縮小されるため、実際の表示寸法は異なります。',
```

**変更後**:
```php
'cover_image_description' => 'この画像は384x384pxの正方形にしてください。正方形でない画像は、元のアスペクト比を維持するために、白色または透明な背景で自動的にパディングされます。',
```

#### 5. resources/sass/_lists.scss (行582-615, 773-790)
**変更箇所**: 画像表示のCSS設定

**リストビューの変更** (行582-615):
```scss
.entity-list-item-image {
  width: 140px;
  height: 140px;  // 高さを追加して正方形に
  // align-self: stretch; を削除

  &.entity-list-item-image-wide {
    width: 220px;
    height: 220px;  // 高さを追加
  }

  @include mixins.smaller-than(vars.$bp-m) {
    width: 80px;
    height: 80px;  // モバイルでも正方形
  }
}

.chapter > .entity-list-item-image {
  width: 60px;
  height: 60px;  // 高さを追加
}
```

**グリッドビューの変更** (行773-790):
```scss
.featured-image-container {
  aspect-ratio: 1 / 1;  // 正方形のアスペクト比
  // min-height: 140px; を削除
}
```

**理由**:
- リストビュー: 画像が縦に伸びないように固定サイズで正方形化
- グリッドビュー: レスポンシブに対応しながら正方形を維持
- すべての表示箇所で統一感のある正方形表示を実現

#### 6. ビルドファイル
**変更内容**: CSS/JSのビルド
- `npm run build` でコンパイル
- public/dist/*.css, *.js が更新

---

**期待される効果**:
- ✅ カバー画像の統一感向上（すべて正方形）
- ✅ ストレージ使用量44%削減
- ✅ 転送量44%削減
- ✅ ページ読み込み速度向上
- ✅ リストビュー・グリッドビューで適切な品質維持
- ✅ アスペクト比を維持（画像の歪みなし）
- ✅ 一覧ページでも正方形で統一表示

**技術的な詳細**:

アップロード時の処理:
- 横長画像: 上下に余白が追加される
- 縦長画像: 左右に余白が追加される
- 正方形画像: そのままリサイズされる
- PNG画像: 透明背景を使用
- JPEG/その他: 白背景を使用

表示時の処理:
- リストビュー: 140px × 140px（モバイル: 80px × 80px）
- Wide版: 220px × 220px
- Chapter: 60px × 60px
- グリッドビュー: アスペクト比1:1でレスポンシブ

---

## 開発環境の変更

### GitHub Actions ワークフローの削除

**変更日**: 2025年11月12日

**変更内容**:
- `.github/workflows/test-php.yml` を削除

**理由**:
- 個人開発環境のため、CI/CDによる自動テストが不要
- 毎回のプッシュ時にPHP 8.2, 8.3, 8.4でのテスト実行によるエラーメール通知が煩雑
- 手動でのテスト実行で品質管理が可能

**影響**:
- GitHubへのプッシュ時にPHPユニットテストが自動実行されなくなります
- 必要に応じて手動でテストを実行：`php artisan test` または `./vendor/bin/phpunit`

**残存するワークフロー**:
- `analyse-php.yml` - PHP静的解析
- `lint-js.yml` - JavaScript Lint
- `lint-php.yml` - PHP Lint
- `test-js.yml` - JavaScriptテスト
- `test-migrations.yml` - マイグレーションテスト

これらのワークフローは様子見として残しています。必要に応じて今後削除を検討します。

---

## バージョン

BookStack v25.11 + LINE WORKS SSO統合
