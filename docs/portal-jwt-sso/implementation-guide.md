# Portal JWT SSO 移植手順

汎用 Laravel アプリに Portal JWT SSO 認証を組み込む手順です。
shin-on_wiki の実装 (`app/Access/PortalJwt/`) をベースに、BookStack 固有の依存を
標準 Laravel に置き換えた版です。

## 目次

1. [パッケージインストール](#1-パッケージインストール)
2. [データベース準備](#2-データベース準備)
3. [設定ファイル](#3-設定ファイル)
4. [例外クラス](#4-例外クラス)
5. [サービスクラス (コア実装)](#5-サービスクラス-コア実装)
6. [ミドルウェア](#6-ミドルウェア)
7. [クッキー暗号化の除外](#7-クッキー暗号化の除外)
8. [コントローラー](#8-コントローラー)
9. [ルーティング](#9-ルーティング)
10. [環境変数](#10-環境変数)
11. [動作確認](#11-動作確認)

---

## 1. パッケージインストール

```bash
composer require firebase/php-jwt
```

JWT の署名検証と JWKS パースに使用します。これ以外に追加パッケージは不要です。

---

## 2. データベース準備

`users` テーブルに `external_auth_id` カラムを追加します。
Portal の `sub` クレーム (UUID) を保存し、ユーザーの同一性を維持します。

```bash
php artisan make:migration add_external_auth_id_to_users_table
```

```php
// database/migrations/xxxx_add_external_auth_id_to_users_table.php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('external_auth_id', 100)->nullable()->after('id');
        $table->index('external_auth_id');
    });
}
```

```bash
php artisan migrate
```

---

## 3. 設定ファイル

```php
// config/portal_jwt.php
return [

    // Portal JWKS エンドポイント (公開鍵取得)
    'jwks_url' => env('PORTAL_JWKS_URL', 'https://portal.shin-on1981.com/api/jwks/'),

    // JWT の iss クレームと照合する発行者 URL
    'issuer' => env('PORTAL_JWT_ISSUER', 'https://portal.shin-on1981.com'),

    // JWT の aud クレームと照合するアプリ識別子
    'audience' => env('PORTAL_JWT_AUDIENCE', 'my-app'),

    // 未認証ユーザーのリダイレクト先 (Portal ログインページ)
    'login_url' => env('PORTAL_LOGIN_URL', 'https://portal.shin-on1981.com/login/'),

    // ログアウト後のリダイレクト先
    'logout_url' => env('PORTAL_LOGOUT_URL', 'https://portal.shin-on1981.com/logout/'),

    // Portal が発行するクッキー名
    'cookie_name' => env('PORTAL_JWT_COOKIE', 'portal_jwt'),

    // JWKS キャッシュ TTL (秒)
    'jwks_cache_ttl' => 3600,

];
```

---

## 4. 例外クラス

```php
// app/Auth/PortalJwtException.php
<?php

namespace App\Auth;

class PortalJwtException extends \Exception
{
}
```

---

## 5. サービスクラス (コア実装)

JWT の検証とユーザーの照合・作成を担当するクラスです。

```php
// app/Auth/PortalJwtService.php
<?php

namespace App\Auth;

use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PortalJwtService
{
    /**
     * JWT トークンを検証してペイロードを返す。
     *
     * @throws PortalJwtException
     */
    public function validateToken(string $token): \stdClass
    {
        $keys = $this->getPublicKeys();

        try {
            $payload = JWT::decode($token, $keys);
        } catch (\Exception $e) {
            throw new PortalJwtException('JWT validation failed: ' . $e->getMessage());
        }

        // iss (Issuer) 検証
        $expectedIss = config('portal_jwt.issuer');
        if (($payload->iss ?? '') !== $expectedIss) {
            throw new PortalJwtException('Invalid issuer: ' . ($payload->iss ?? '(none)'));
        }

        // aud (Audience) 検証
        $expectedAud = config('portal_jwt.audience');
        $aud = isset($payload->aud) ? (array) $payload->aud : [];
        if (!in_array($expectedAud, $aud)) {
            throw new PortalJwtException('Invalid audience');
        }

        // is_active フラグ検証
        if (isset($payload->is_active) && !$payload->is_active) {
            throw new PortalJwtException('User account is inactive');
        }

        return $payload;
    }

    /**
     * JWT ペイロードからユーザーを検索、存在しなければ作成して返す。
     *
     * @throws PortalJwtException
     */
    public function findOrCreateUser(\stdClass $payload): User
    {
        $portalUuid = $payload->sub ?? null;
        $email = $payload->email ?? null;

        if (!$portalUuid || !$email) {
            throw new PortalJwtException('JWT missing required claims (sub, email)');
        }

        // 1. external_auth_id (= portal sub) でユーザー検索
        $user = User::where('external_auth_id', $portalUuid)->first();

        // 2. email で検索し external_auth_id を付与 (既存ユーザーの移行対応)
        if (!$user) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->external_auth_id = $portalUuid;
                $user->save();
            }
        }

        // 3. 新規ユーザー作成
        if (!$user) {
            $familyName = $payload->family_name ?? '';
            $givenName  = $payload->given_name ?? '';
            $name = trim($familyName . ' ' . $givenName) ?: ($payload->name ?? $email);

            $user = User::create([
                'name'             => $name,
                'email'            => $email,
                'external_auth_id' => $portalUuid,
                'password'         => Hash::make(Str::random(32)), // SSO専用のためランダムパスワード
            ]);
        }

        return $user;
    }

    /**
     * Portal の JWKS から公開鍵を取得する (キャッシュ付き)。
     *
     * @return array<string, \Firebase\JWT\Key>
     * @throws PortalJwtException
     */
    protected function getPublicKeys(): array
    {
        $cacheKey = 'portal_jwt_jwks';
        $ttl = config('portal_jwt.jwks_cache_ttl', 3600);

        // OpenSSLAsymmetricKey はシリアライズ不可のため JWKS JSON をキャッシュし、
        // 鍵オブジェクトへのパースはリクエストごとに行う。
        $jwks = Cache::remember($cacheKey, $ttl, function () {
            $jwksUrl = config('portal_jwt.jwks_url');

            try {
                $response = Http::timeout(5)->get($jwksUrl);
                return $response->json();
            } catch (\Exception $e) {
                throw new PortalJwtException('Failed to fetch JWKS: ' . $e->getMessage());
            }
        });

        if (empty($jwks['keys'])) {
            throw new PortalJwtException('Invalid JWKS response from portal');
        }

        return JWK::parseKeySet($jwks, 'RS256');
    }
}
```

> **shin-on_wiki との違い:**
> - `HttpRequestService` → `Illuminate\Support\Facades\Http`
> - `RegistrationService::findOrRegister()` → `User::create()`

---

## 6. ミドルウェア

リクエストごとに `portal_jwt` クッキーを検証し、未認証なら Portal にリダイレクトします。

```php
// app/Http/Middleware/PortalJwtAuthenticate.php
<?php

namespace App\Http\Middleware;

use App\Auth\PortalJwtException;
use App\Auth\PortalJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PortalJwtAuthenticate
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // 未認証の場合のみ JWT クッキーを検証
        if (!auth()->check()) {
            $cookieName = config('portal_jwt.cookie_name', 'portal_jwt');
            $token = $request->cookie($cookieName);

            if ($token) {
                try {
                    $service = app(PortalJwtService::class);
                    $payload = $service->validateToken($token);
                    $user    = $service->findOrCreateUser($payload);
                    auth()->login($user);
                    $request->session()->regenerate();
                } catch (PortalJwtException $e) {
                    Log::warning('portal_jwt validation failed: ' . $e->getMessage());
                }
            }
        }

        // 認証済みかチェック
        if (!auth()->check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            // Portal のログインページにリダイレクト (?next= で元 URL を伝える)
            $loginUrl = config('portal_jwt.login_url');
            $nextUrl  = urlencode($request->fullUrl());
            return redirect($loginUrl . '?next=' . $nextUrl);
        }

        return $next($request);
    }
}
```

### ミドルウェアの登録

**Laravel 11以降** (`bootstrap/app.php`):

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'portal.auth' => \App\Http\Middleware\PortalJwtAuthenticate::class,
    ]);
})
```

**Laravel 10以前** (`app/Http/Kernel.php`):

```php
protected $routeMiddleware = [
    // ...
    'portal.auth' => \App\Http\Middleware\PortalJwtAuthenticate::class,
];
```

---

## 7. クッキー暗号化の除外

Laravel はデフォルトですべての Set-Cookie を暗号化しますが、`portal_jwt` クッキーは
Portal が署名した JWT なので Laravel の暗号化を通してはいけません。

**Laravel 11以降** (`bootstrap/app.php`):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->encryptCookies(except: [
        'portal_jwt',
    ]);
})
```

**Laravel 10以前** (`app/Http/Middleware/EncryptCookies.php`):

```php
protected $except = [
    'portal_jwt',
];
```

---

## 8. コントローラー

ログイン・ログアウト処理です。

```php
// app/Http/Controllers/Auth/PortalJwtController.php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PortalJwtController extends Controller
{
    /**
     * ログインページ表示 → Portal にリダイレクト
     */
    public function login(Request $request)
    {
        $loginUrl = config('portal_jwt.login_url');
        $next = urlencode($request->query('next', url('/')));
        return redirect($loginUrl . '?next=' . $next);
    }

    /**
     * ログアウト: セッション破棄 → クッキー削除 → Portal のログアウトページへ
     */
    public function logout(Request $request)
    {
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $logoutUrl = config('portal_jwt.logout_url');
        return redirect($logoutUrl)
            ->withCookie(cookie()->forget('portal_jwt'));
    }
}
```

---

## 9. ルーティング

```php
// routes/web.php

use App\Http\Controllers\Auth\PortalJwtController;

// 認証なしでアクセス可能なルート
Route::get('/login', [PortalJwtController::class, 'login'])->name('login');
Route::post('/logout', [PortalJwtController::class, 'logout'])->name('logout');

// 認証が必要なルート (ミドルウェアを適用)
Route::middleware('portal.auth')->group(function () {
    Route::get('/', fn() => view('dashboard'))->name('dashboard');
    // その他のルート...
});
```

---

## 10. 環境変数

`.env` に以下を追加します。

```env
# ── Portal JWT SSO 設定 ──────────────────────────────────────────

# Portal JWKS エンドポイント (公開鍵取得)
PORTAL_JWKS_URL=https://portal.shin-on1981.com/api/jwks/

# JWT の iss クレーム検証値
PORTAL_JWT_ISSUER=https://portal.shin-on1981.com

# JWT の aud クレーム検証値 (Portal に登録したアプリ識別子)
PORTAL_JWT_AUDIENCE=my-app

# 未認証時のリダイレクト先
PORTAL_LOGIN_URL=https://portal.shin-on1981.com/login/

# ログアウト後のリダイレクト先
PORTAL_LOGOUT_URL=https://portal.shin-on1981.com/logout/

# クッキー名 (Portal の設定と一致させる、通常変更不要)
PORTAL_JWT_COOKIE=portal_jwt
```

### 開発環境

Portal をローカルで動かしている場合は、Docker ネットワーク経由に変更します。

```env
PORTAL_JWKS_URL=http://host.docker.internal/api/jwks/
PORTAL_LOGIN_URL=http://localhost/login/
PORTAL_LOGOUT_URL=http://localhost/logout/
```

---

## 11. 動作確認

### 設定の確認

```bash
php artisan config:clear
php artisan tinker

# JWKS エンドポイントに接続できるか確認
>>> app(\App\Auth\PortalJwtService::class)->validateToken('your.jwt.token.here')
```

### ログの確認

JWT 検証に失敗した場合、`storage/logs/laravel.log` に以下が記録されます。

```
[warning] portal_jwt validation failed: JWT validation failed: ...
```

### よくあるエラー

| エラーメッセージ | 原因 | 対処 |
|----------------|------|------|
| `Failed to fetch JWKS` | Portal に接続できない | `PORTAL_JWKS_URL` と Portal の疎通を確認 |
| `Invalid issuer` | `iss` クレームが不一致 | `PORTAL_JWT_ISSUER` を Portal の発行者 URL に合わせる |
| `Invalid audience` | `aud` クレームが不一致 | `PORTAL_JWT_AUDIENCE` を Portal に登録したアプリ識別子に合わせる |
| `User account is inactive` | Portal 側でユーザーが無効化 | Portal 管理画面でユーザーを有効化 |
| JWT 検証は通るがログインできない | クッキーが暗号化されている | [手順 7](#7-クッキー暗号化の除外) の設定を確認 |

---

## 参考: shin-on_wiki での実装箇所

| このドキュメントのファイル | shin-on_wiki での対応ファイル |
|--------------------------|------------------------------|
| `app/Auth/PortalJwtService.php` | `app/Access/PortalJwt/PortalJwtService.php` |
| `app/Auth/PortalJwtException.php` | `app/Access/PortalJwt/PortalJwtException.php` |
| `config/portal_jwt.php` | `app/Config/portal_jwt.php` |
| `app/Http/Middleware/PortalJwtAuthenticate.php` | `app/Http/Middleware/Authenticate.php` (portal_jwt 部分) |
| `app/Http/Controllers/Auth/PortalJwtController.php` | `app/Access/Controllers/LoginController.php` (portal_jwt 部分) |
