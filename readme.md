# shin·on Wiki - システム管理ガイド

BookStackをベースにLINE WORKS SSO認証を統合した社内Wikiシステムです。

---

## 1. システム概要

| 項目 | 内容 |
|------|------|
| **システム名** | shin·on Wiki |
| **用途** | 社内情報共有・ナレッジ管理 |
| **ベースシステム** | [BookStack](https://www.bookstackapp.com/) v25.11 |
| **認証方式** | LINE WORKS SSO + OTP二段階認証 |
| **アクセス制限** | @shin-on1981ドメインのユーザーのみ |
| **本番URL** | https://wiki.shin-on.mydns.jp |

---

## 2. システム構成

```
インターネット
    │
    ▼
ルーター（80, 443, 56834 ポートフォワーディング）
    │
    ▼
┌─────────────────────────────────────────────┐
│  自宅サーバー (Ubuntu)                        │
│                                             │
│  Apache (SSL終端 / リバースプロキシ)          │
│    ・Let's Encrypt SSL証明書                 │
│    ・wiki.shin-on.mydns.jp → localhost:8083 │
│                    │                         │
│                    ▼                         │
│  Docker                                      │
│    ├─ App (PHP 8.4 + Laravel) : 8083        │
│    └─ MySQL 5.7+             : 3308         │
└─────────────────────────────────────────────┘
```

---

## 3. 運用コマンド

### 起動・停止

```bash
cd /var/www/shin-on_wiki

# 起動
docker compose -f docker-compose.production.yml up -d

# 停止
docker compose -f docker-compose.production.yml down

# 状態確認
docker compose -f docker-compose.production.yml ps
```

### ログ確認

```bash
# アプリケーションログ
docker compose -f docker-compose.production.yml logs -f app

# Laravelログ
tail -f /var/www/shin-on_wiki/storage/logs/laravel.log

# Apacheログ
sudo tail -f /var/log/apache2/wiki-error.log
```

### バックアップ・復元

```bash
# 手動バックアップ（Dropboxへ）
docker compose -f docker-compose.production.yml exec app php artisan backup:dropbox

# バックアップ一覧
docker compose -f docker-compose.production.yml exec app php artisan restore:dropbox:list

# 復元
docker compose -f docker-compose.production.yml exec app php artisan restore:dropbox <timestamp>
```

**自動バックアップ**: 毎日午前2時にDropboxへ保存

### キャッシュ再生成

```bash
docker compose -f docker-compose.production.yml exec app php artisan cache:clear
docker compose -f docker-compose.production.yml exec app php artisan config:cache
docker compose -f docker-compose.production.yml exec app php artisan route:cache
docker compose -f docker-compose.production.yml exec app php artisan view:cache
```

---

## 4. トラブルシューティング

| 問題 | 対処 |
|------|------|
| 502 Bad Gateway | `docker compose up -d` でコンテナ起動 |
| ログインできない | `.env`の`LINEWORKS_DOMAIN=shin-on1981`を確認 |
| OTPが届かない | `.env`のBot API設定、秘密鍵パスを確認 |
| CSS反映されない | キャッシュ再生成を実行 |
| SSL証明書エラー | `sudo certbot renew` |
| サムネイル表示されない | `php artisan bookstack:regenerate-thumbnails` |

---

## 5. セキュリティ

| 対策 | 説明 |
|------|------|
| LINE WORKS SSO | OAuth 2.0 / OpenID Connect認証 |
| OTP二段階認証 | LINE WORKS Bot経由のワンタイムパスワード |
| ドメイン制限 | @shin-on1981のみアクセス可能 |
| HTTPS | Let's Encrypt SSL証明書 |
| ファイアウォール | ufw（80, 443, 56834のみ開放） |
| SSH | 公開鍵認証のみ、ポート56834 |

---

## 6. 定期メンテナンス

| 頻度 | 作業内容 |
|------|----------|
| **日次（自動）** | Dropboxバックアップ、MyDNS IP更新 |
| **週次** | ログ確認、ディスク容量確認（`df -h`） |
| **月次** | SSL証明書確認、復元テスト |

---

## 7. 関連ドキュメント

| ドキュメント | 対象読者 |
|------------|---------|
| [クイックスタート](./claude/docs/README_LINEWORKS.md) | 初期構築担当者 |
| [SSO詳細設定](./claude/docs/LINEWORKS_SSO_SETUP.md) | 開発者 |
| [OTP二段階認証](./claude/docs/LINEWORKS_OTP_2FA.md) | 開発者 |
| [バックアップ・復元](./claude/docs/BACKUP_RESTORE.md) | 運用担当者 |
| [自宅サーバーデプロイ](./claude/docs/DEPLOYMENT_HOME_SERVER.md) | インフラ担当者 |
| [変更履歴](./claude/docs/CHANGES.md) | すべて |

---

## 8. リンク

- **GitHub**: https://github.com/satoshi-tateishi/shin-on_wiki
- **BookStack公式**: https://www.bookstackapp.com/
- **LINE WORKS API**: https://developers.worksmobile.com/jp/docs/auth

---

**最終更新**: 2025年11月26日 | **作成者**: Claude Code + satoshi

> 📖 BookStackオリジナルREADMEは [README_BOOKSTACK_ORIGINAL.md](./README_BOOKSTACK_ORIGINAL.md)
