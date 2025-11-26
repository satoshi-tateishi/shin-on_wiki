# 変更履歴

shin·on Wiki by BookStack の変更履歴です。

---

## 2025年11月26日

### サブドメイン構成への移行

本番URLを `shin-on.mydns.jp` から `wiki.shin-on.mydns.jp` に変更しました。

**変更内容:**
- MyDNS でサブドメイン `wiki` を追加
- Apache VirtualHost を `shin-on-apps.conf` に変更
- Let's Encrypt SSL証明書を新ドメインで取得
- OAuth Redirect URI を更新（LINE WORKS, Dropbox）

**詳細:** [DEPLOYMENT_HOME_SERVER.md](./DEPLOYMENT_HOME_SERVER.md)

---

### LINE WORKS OTP 二段階認証

LINE WORKS Bot を使用した二段階認証（2FA）を実装しました。

**主な機能:**
- JWT (RS256) 認証によるBot API連携
- 6桁OTP送信（有効期限10分、5回失敗で3分間ロック）
- 監査ログ記録

**詳細:** [LINEWORKS_OTP_2FA.md](./LINEWORKS_OTP_2FA.md)

---

## 2025年11月17日

### 自宅サーバーデプロイ対応

Docker環境での自宅サーバーデプロイをサポートしました。

**主な機能:**
- MyDNS.JP 自動更新
- GitHub Actions 自動デプロイ
- 復元後のキャッシュ再生成機能

**詳細:** [DEPLOYMENT_HOME_SERVER.md](./DEPLOYMENT_HOME_SERVER.md)

---

## 2025年11月16日

### Dropbox復元後の自動サムネイル再生成

復元時に本棚・本のカバー画像サムネイルを自動再生成するようにしました。

**主な機能:**
- 復元後に自動サムネイル再生成
- 手動再生成コマンド: `php artisan bookstack:regenerate-thumbnails`
- 3種類のサイズ生成（150x150, 250x250, 440x250）

**詳細:** [BACKUP_RESTORE.md](./BACKUP_RESTORE.md)

---

## 2025年11月12日

### カバー画像の正方形化

本棚・本のカバー画像を384×384pxの正方形に変更しました。

**主な変更:**
- 画像サイズを512×512pxから384×384pxに変更
- パディング方式で正方形化（アスペクト比維持）
- PNG画像は透明背景、その他は白背景

**変更ファイル:**
- `app/Uploads/ImageResizer.php` - 正方形パディング処理
- `app/Entities/Repos/BaseRepo.php` - サイズ変更
- `resources/sass/_lists.scss` - CSS修正
- `lang/*/common.php` - メッセージ更新

---

### GitHub Actions ワークフロー削除

個人開発環境のため、`test-php.yml`を削除しました。

---

## 2025年11月11日

### LINE WORKS SSO統合

BookStackにLINE WORKS OAuth 2.0/OpenID Connect認証を統合しました。

**主な機能:**
- OAuth 2.0 + PKCE 認証
- ドメインベースのアクセス制御（@shin-on1981のみ）
- JWT署名検証スキップ（他の手段でセキュリティ補完）

**詳細:** [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md)

---

## 変更ファイル一覧

### LINE WORKS SSO関連

| ファイル | 変更内容 |
|---------|---------|
| `app/Access/Oidc/OidcProviderSettings.php` | JWT公開鍵を任意に変更 |
| `app/Access/Oidc/OidcJwtWithClaims.php` | JWT署名検証スキップ処理 |
| `app/Access/Oidc/OidcOAuthProvider.php` | LINE WORKS domain パラメータ対応 |
| `app/Access/Oidc/OidcService.php` | PostAuthOptionProvider、ドメイン検証 |

### LINE WORKS OTP関連

| ファイル | 変更内容 |
|---------|---------|
| `app/Access/LineWorksOtp/*` | OTPサービス（Bot API、セッション、ログ） |
| `app/Access/Controllers/LineWorksOtpController.php` | OTP検証コントローラー |
| `app/Access/LoginService.php` | OTP検証判定追加 |
| `database/migrations/2025_11_26_*` | OTPテーブル追加 |

### バックアップ・復元関連

| ファイル | 変更内容 |
|---------|---------|
| `app/Services/BackupService.php` | サムネイル再生成メソッド追加 |
| `app/Console/Commands/RegenerateThumbnailsCommand.php` | 手動再生成コマンド |

---

**最終更新**: 2025年11月26日
