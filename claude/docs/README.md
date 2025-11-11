# shin·on Wiki by BookStack - LINE WORKS SSO 統合 ドキュメント

このディレクトリには、shin·on Wiki by BookStack (BookStackベース) へのLINE WORKS SSO認証統合に関するドキュメントが含まれています。

## 📚 ドキュメント一覧

### 1. [README_LINEWORKS.md](./README_LINEWORKS.md) - クイックスタートガイド 🚀
**最初に読むべきドキュメント**

- セットアップ手順
- 環境変数の設定
- 主な機能の概要
- よくある問題と解決策

**対象読者**: 初めて環境をセットアップする開発者、運用担当者

---

### 2. [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md) - 詳細実装ドキュメント 📖
**技術的な詳細を知りたい場合に読むドキュメント**

- 実装した機能の詳細説明
- 修正したファイルとコードの解説
- LINE WORKS認証フローの詳細
- セキュリティ上の考慮事項
- トラブルシューティングガイド
- 動作確認手順

**対象読者**: 実装の詳細を理解したい開発者、コードレビュー担当者

---

### 3. [CHANGES.md](./CHANGES.md) - 変更内容サマリー 📝
**何が変更されたかを知りたい場合に読むドキュメント**

- 各ファイルの変更箇所（変更前/変更後の比較）
- 技術的な課題と解決策
- テスト結果とログ例
- セキュリティレビュー

**対象読者**: コードレビュー担当者、プロジェクトマネージャー、監査担当者

---

## 📖 読む順番（推奨）

### 初めてセットアップする場合
1. **README_LINEWORKS.md** - まずはクイックスタートガイドを読んで環境をセットアップ
2. **LINEWORKS_SSO_SETUP.md** - セットアップ後、詳細な動作を理解する
3. **CHANGES.md** - 必要に応じて、具体的な変更内容を確認

### コードレビューの場合
1. **CHANGES.md** - 変更内容のサマリーを確認
2. **LINEWORKS_SSO_SETUP.md** - 実装の詳細を確認
3. **README_LINEWORKS.md** - セットアップ手順を確認

### トラブルシューティングの場合
1. **README_LINEWORKS.md** - よくある問題を確認
2. **LINEWORKS_SSO_SETUP.md** - 詳細なトラブルシューティングガイドを確認
3. ログを確認: `grep "OIDC" storage/logs/laravel.log | tail -20`

---

## 🎯 ドキュメントの目的

このドキュメント群は、以下の目的で作成されています：

1. **知識の共有**: 実装の背景と技術的な判断を記録
2. **再現性の確保**: 他の環境でも同じセットアップができるように
3. **メンテナンス性の向上**: 将来の変更や修正を容易にする
4. **セキュリティの透明性**: セキュリティ上の考慮事項を明確にする

---

## 🔍 主な実装内容

- ✅ LINE WORKS OAuth 2.0/OpenID Connect 認証
- ✅ PKCE (Proof Key for Code Exchange) サポート
- ✅ JWT署名検証スキップ（LINE WORKS非対応のため）
- ✅ ドメインベースのアクセス制御（@shin-on1981のみ）
- ✅ セキュリティ補完（State/Nonce、HTTPS、ドメイン制限）

---

## 📞 サポート

質問や問題がある場合は、以下を確認してください：

1. **ログの確認**
   ```bash
   grep "OIDC" storage/logs/laravel.log | tail -20
   ```

2. **環境変数の確認**
   ```bash
   php artisan config:show
   ```

3. **ドキュメントの確認**
   - [README_LINEWORKS.md](./README_LINEWORKS.md) のトラブルシューティングセクション
   - [LINEWORKS_SSO_SETUP.md](./LINEWORKS_SSO_SETUP.md) のトラブルシューティングセクション

---

## 📅 最終更新日

2025年11月11日

---

## 🔗 関連リンク

### プロジェクト
- **[GitHubリポジトリ](https://github.com/satoshi-tateishi/shin-on_wiki)** - このプロジェクトのソースコード
- [プロジェクトルートREADME](../../README.md) - プロジェクト全体の概要

### 技術資料
- [BookStack公式サイト](https://www.bookstackapp.com/)
- [LINE WORKS API ドキュメント](https://developers.worksmobile.com/jp/docs/auth)
- [OAuth 2.0 RFC 6749](https://datatracker.ietf.org/doc/html/rfc6749)
- [OpenID Connect Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html)

---

## 👥 作成者

Claude Code + satoshi

---

## 📄 ライセンス

このドキュメントは BookStack のライセンスに従います。
