# shin·on Wiki by BookStack - LINE WORKS 認証統合

LINE WORKS を使用した認証機能（SSO + 二段階認証）のクイックスタートガイドです。

---

## 認証機能の概要

| 機能 | 説明 | 詳細ドキュメント |
|------|------|------------------|
| **SSO** | LINE WORKS OAuth 2.0/OpenID Connect 認証 | [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md) |
| **OTP 二段階認証** | LINE WORKS Bot 経由のワンタイムパスワード | [LINEWORKS_OTP_2FA.md](./LINEWORKS_OTP_2FA.md) |

### 認証フロー

```
1. ユーザーが「LINE WORKSでログイン」をクリック
       ↓
2. LINE WORKS 認証画面でログイン（SSO）
       ↓
3. ドメイン検証（@shin-on1981 のみ許可）
       ↓
4. OTP入力画面が表示（設定時のみ）
       ↓
5. LINE WORKS Bot からOTPメッセージ受信
       ↓
6. OTP入力 → 認証完了
```

---

## クイックセットアップ

### 前提条件

- PHP 8.2以上
- MySQL 8.0以上
- LINE WORKS Developer Console へのアクセス権限

### 1. LINE WORKS SSO設定

#### Developer Console での設定

1. [LINE WORKS Developer Console](https://developers.worksmobile.com/) にアクセス
2. 「API 2.0」でアプリを作成
3. OAuth 2.0 設定:
   - **Redirect URI**: `https://your-domain.com/oidc/callback`
   - **Scopes**: `openid`, `profile`, `email`

#### 環境変数 (.env)

```env
# LINE WORKS OIDC
AUTH_METHOD=oidc
OIDC_NAME="LINE WORKS"
OIDC_CLIENT_ID=your_client_id
OIDC_CLIENT_SECRET=your_client_secret
OIDC_ISSUER=https://auth.worksmobile.com
OIDC_ISSUER_DISCOVER=false
OIDC_AUTH_ENDPOINT=https://auth.worksmobile.com/oauth2/v2.0/authorize
OIDC_TOKEN_ENDPOINT=https://auth.worksmobile.com/oauth2/v2.0/token

# ドメイン制限
LINEWORKS_DOMAIN=shin-on1981
```

### 2. OTP二段階認証設定（オプション）

#### Developer Console での追加設定

1. 「Bot」を作成し、Bot ID を取得
2. 「Service Account」を作成し、秘密鍵をダウンロード
3. Developer Console App を作成し、Client ID/Secret を取得

#### 秘密鍵の配置

```bash
# 秘密鍵を storage/app/lineworks/ に配置
mkdir -p storage/app/lineworks
# private_key.pem を配置
chmod 600 storage/app/lineworks/private_key.pem
```

#### 環境変数 (.env) 追加

```env
# LINE WORKS Bot API (二段階認証用)
LINEWORKS_API_BASE_URL=https://www.worksapis.com/v1.0
LINEWORKS_AUTH_URL=https://auth.worksmobile.com/oauth2/v2.0/token
LINEWORKS_BOT_ID=your_bot_id
LINEWORKS_BOT_SECRET=your_bot_secret
LINEWORKS_DB_CLIENT_ID=your_developer_console_client_id
LINEWORKS_DB_CLIENT_SECRET=your_developer_console_client_secret
LINEWORKS_SERVICE_ACCOUNT=xxxxx.serviceaccount@your-domain
LINEWORKS_PRIVATE_KEY_PATH=lineworks/private_key.pem
```

#### マイグレーション

```bash
php artisan migrate
```

---

## 主な機能

### SSO機能

- LINE WORKS OAuth 2.0/OpenID Connect 認証
- PKCE (Proof Key for Code Exchange) サポート
- ドメインベースのアクセス制御（@shin-on1981のみ）
- JWT署名検証スキップ（LINE WORKS非対応のため、他の手段で補完）

### OTP二段階認証機能

- LINE WORKS Bot 経由の6桁OTP送信
- OTP有効期限: 10分
- 試行回数制限: 5回失敗で3分間ロック
- ハッシュ化されたOTPをDBに保存
- 監査ログ記録

---

## セキュリティ

### JWT署名検証のスキップについて

LINE WORKS は JWKS エンドポイントを提供していないため、JWT署名検証をスキップしています。
セキュリティは以下の方法で補完:

- State/Nonce検証（CSRF/リプレイ攻撃防止）
- HTTPS通信（盗聴防止）
- ドメイン制限（@shin-on1981のみ）
- PKCE（認証コード横取り防止）
- Issuer/Audience検証

### OTPセキュリティ

- OTPは `Hash::make()` でハッシュ化して保存
- 検証成功後はDBからOTPを削除
- 監査ログでOTP操作を記録

---

## トラブルシューティング

### SSO関連

| 問題 | 対処 |
|------|------|
| "invalid_request" エラー | `.env`の`LINEWORKS_DOMAIN`を確認 |
| ドメインアクセス拒否 | `@shin-on1981`のユーザーでログインしているか確認 |
| HTTPS証明書エラー | `mkcert -install`で認証局を追加 |

### OTP関連

| 問題 | 対処 |
|------|------|
| OTPが送信されない | `.env`のBot API設定、秘密鍵ファイルを確認 |
| 有効期限切れエラー | 10分以内にOTPを入力、または再送信 |
| アカウントロック | DBで`lineworks_otp_locked_until`をNULLに更新 |

### ログ確認

```bash
# SSO関連
grep "OIDC" storage/logs/laravel.log | tail -20

# OTP関連
grep "LINE WORKS OTP" storage/logs/laravel.log | tail -20
```

---

## 関連ドキュメント

- [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md) - SSO実装の詳細
- [LINEWORKS_OTP_2FA.md](./LINEWORKS_OTP_2FA.md) - OTP二段階認証の詳細
- [CHANGES.md](./CHANGES.md) - 変更履歴

---

## 関連リンク

- [LINE WORKS API ドキュメント](https://developers.worksmobile.com/jp/docs/auth)
- [LINE WORKS Bot API ガイド](https://developers.worksmobile.com/jp/docs/bot-overview)

---

**最終更新**: 2025年11月26日
