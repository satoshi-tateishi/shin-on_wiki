# Portal JWT SSO 認証

shin-on Portal との JWT ベースの SSO (Single Sign-On) 認証システムです。
shin-on_wiki で実装しており、他の Laravel 製アプリにも移植できます。

## 目次

- [仕組み](#仕組み)
- [認証フロー](#認証フロー)
- [JWT クレーム仕様](#jwt-クレーム仕様)
- [セキュリティ設計](#セキュリティ設計)
- [前提条件](#前提条件)
- [移植手順](./implementation-guide.md)

---

## 仕組み

Portal が発行した **RS256 署名付き JWT** を HTTP クッキー (`portal_jwt`) に乗せてアプリに渡します。
アプリ側のミドルウェアがこのクッキーを検出し、Portal の JWKS エンドポイントから公開鍵を取得して署名を検証します。
検証が成功するとユーザーを自動ログインさせます。アプリ独自のログインフォームは不要です。

```
アプリ ←── portal_jwt クッキー ──── Portal
  │                                   │
  │  JWKS で署名検証                  │  RS256 で署名
  │                                   │
  └── ユーザーを自動ログイン          └── JWT を発行
```

---

## 認証フロー

### ログイン

```
1. ユーザーが保護ページにアクセス
   ↓
2. ミドルウェアが portal_jwt クッキーを確認
   ├── クッキーあり → JWT 署名検証 → ユーザー照合/作成 → ログイン (ステップ5へ)
   └── クッキーなし → Portal のログイン URL へリダイレクト
         ?next=<元のURL> を付与
   ↓
3. Portal でユーザー認証
   ↓
4. Portal が portal_jwt クッキーをセットしてアプリにリダイレクト
   ↓
5. ミドルウェアが JWT を検証し、アプリのセッションを作成
   ↓
6. 元のページを表示
```

### ログアウト

```
1. ユーザーがログアウト操作
   ↓
2. アプリのセッションを破棄
3. portal_jwt クッキーを削除
4. Portal のログアウト URL へリダイレクト
   (Portal 側でもセッションを破棄)
```

---

## JWT クレーム仕様

Portal が発行する JWT に含まれるクレームです。

| クレーム | 型 | 必須 | 説明 |
|---------|-----|------|------|
| `sub` | string (UUID) | 必須 | ユーザーの一意識別子。アプリの `external_auth_id` に保存 |
| `email` | string | 必須 | メールアドレス |
| `family_name` | string | 任意 | 姓 |
| `given_name` | string | 任意 | 名 |
| `name` | string | 任意 | 表示名 (family_name/given_name がない場合のフォールバック) |
| `is_active` | boolean | 任意 | `false` の場合は認証を拒否 |
| `iss` | string | 必須 (標準) | 発行者 URL (例: `https://portal.shin-on1981.com`) |
| `aud` | string or array | 必須 (標準) | 対象アプリ識別子 (例: `shin-on-apps`) |
| `exp` | integer | 必須 (標準) | 有効期限 (Unix timestamp) |

### ユーザー名の決定ロジック

```
family_name + given_name → "山田 太郎"
├── どちらかが空 → name クレームを使用
└── name もなし → email を使用
```

---

## セキュリティ設計

### JWT 署名アルゴリズム

**RS256 (RSA + SHA-256)** を使用します。
Portal が秘密鍵で署名し、アプリは公開鍵（JWKS）で検証します。
秘密鍵はアプリ側には不要です。

### JWKS キャッシュ

公開鍵は Portal の JWKS エンドポイント (`/api/jwks/`) から取得します。
リクエストごとに取得すると Portal に負荷がかかるため、**1時間 (3600秒) キャッシュ**します。

`OpenSSLAsymmetricKey` はシリアライズ不可のため、キャッシュには JWKS の生 JSON を保存し、
鍵オブジェクトへのパースはリクエストごとに行います。

### 検証項目

| 検証 | 内容 |
|------|------|
| JWT 署名 | `firebase/php-jwt` + RS256 公開鍵で検証 |
| Issuer (`iss`) | 設定値と完全一致 |
| Audience (`aud`) | 設定値がリストに含まれているか |
| 有効期限 (`exp`) | `firebase/php-jwt` が自動で検証 |
| アカウント状態 | `is_active: false` で拒否 |

### クッキー設定

`portal_jwt` クッキーは **Laravel の暗号化対象から除外**します。
Portal が署名した JWT をそのまま検証する必要があるため、Laravel による暗号化を通すと
JWT が破損します。

---

## 前提条件

移植先アプリで Portal JWT SSO を使用するには、以下が必要です。

### Portal 側の設定

1. 移植先アプリのドメインを Portal に登録 (CORS/リダイレクト許可)
2. アプリ用の `audience` 識別子を決定 (例: `my-app`)
3. Portal のログイン後リダイレクト先にアプリの URL を許可

### アプリ側の要件

- **PHP**: 8.2+
- **Laravel**: 10+
- **パッケージ**: `firebase/php-jwt` ^6.0
- **`users` テーブル**: `external_auth_id` カラム (string, nullable) が必要

---

## 移植手順

[implementation-guide.md](./implementation-guide.md) を参照してください。
