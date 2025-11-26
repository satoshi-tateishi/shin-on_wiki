# shin·on Wiki by BookStack - ドキュメント

LINE WORKS SSO統合を含むBookStackカスタマイズのドキュメントです。

---

## ドキュメント一覧

### LINE WORKS 認証

| ドキュメント | 説明 |
|-------------|------|
| [README_LINEWORKS.md](./README_LINEWORKS.md) | LINE WORKS認証のクイックスタート（SSO + OTP） |
| [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md) | SSO実装の詳細（OAuth 2.0/OpenID Connect） |
| [LINEWORKS_OTP_2FA.md](./LINEWORKS_OTP_2FA.md) | OTP二段階認証の詳細（Bot API） |

### デプロイ

| ドキュメント | 説明 |
|-------------|------|
| [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md) | デプロイ時のチェックリスト |
| [DEPLOYMENT.md](./DEPLOYMENT.md) | Ubuntu直接デプロイの詳細手順 |
| [DEPLOYMENT_DOCKER.md](./DEPLOYMENT_DOCKER.md) | Dockerデプロイの詳細手順 |
| [DEPLOYMENT_HOME_SERVER.md](./DEPLOYMENT_HOME_SERVER.md) | 自宅サーバー（MyDNS + GitHub Actions） |
| [SYSTEM_REQUIREMENTS.md](./SYSTEM_REQUIREMENTS.md) | システム要件 |

### バックアップ・運用

| ドキュメント | 説明 |
|-------------|------|
| [BACKUP_RESTORE.md](./BACKUP_RESTORE.md) | Dropboxバックアップ・復元ガイド |
| [CHANGES.md](./CHANGES.md) | 変更履歴 |

---

## 読む順番（推奨）

### 初めてセットアップする場合

1. [README_LINEWORKS.md](./README_LINEWORKS.md) - 認証機能の概要
2. [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md) - デプロイ手順
3. [BACKUP_RESTORE.md](./BACKUP_RESTORE.md) - バックアップ設定

### 詳細を確認したい場合

- SSO実装の詳細 → [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md)
- OTP二段階認証の詳細 → [LINEWORKS_OTP_2FA.md](./LINEWORKS_OTP_2FA.md)
- デプロイ詳細 → [DEPLOYMENT.md](./DEPLOYMENT.md) / [DEPLOYMENT_DOCKER.md](./DEPLOYMENT_DOCKER.md)

### トラブルシューティング

1. 各ドキュメントのトラブルシューティングセクションを確認
2. ログを確認: `grep "OIDC\|OTP" storage/logs/laravel.log | tail -20`

---

## 主な実装内容

### LINE WORKS認証

- LINE WORKS OAuth 2.0/OpenID Connect 認証
- PKCE (Proof Key for Code Exchange) サポート
- ドメインベースのアクセス制御（@shin-on1981のみ）
- OTP二段階認証（LINE WORKS Bot経由）

### バックアップ機能

- Dropbox自動バックアップ（データベース + ファイル）
- 復元後の自動サムネイル再生成
- 手動再生成コマンド

---

## 関連リンク

### プロジェクト

- [GitHubリポジトリ](https://github.com/satoshi-tateishi/shin-on_wiki)

### 技術資料

- [BookStack公式サイト](https://www.bookstackapp.com/)
- [LINE WORKS API ドキュメント](https://developers.worksmobile.com/jp/docs/auth)

---

**最終更新**: 2025年11月26日
