# claude ディレクトリ

このディレクトリには、Claude Codeによる開発に関連するファイルが含まれています。

## 📁 ディレクトリ構成

```
claude/
├── README.md           # このファイル
├── certs/              # SSL/TLS証明書（gitignore対象）
│   ├── localhost+2.pem         # SSL証明書
│   └── localhost+2-key.pem     # SSL秘密鍵
└── docs/               # ドキュメント
    ├── README.md               # ドキュメント目次
    ├── README_LINEWORKS.md     # クイックスタートガイド
    ├── LINEWORKS_SSO_SETUP.md  # 詳細実装ドキュメント
    └── CHANGES.md              # 変更内容サマリー
```

## 📚 docs/ - ドキュメント

LINE WORKS SSO統合に関する技術ドキュメントが含まれています。

### 各ドキュメントの役割

- **[docs/README.md](./docs/README.md)** - ドキュメント目次とナビゲーション
- **[docs/README_LINEWORKS.md](./docs/README_LINEWORKS.md)** - クイックスタートガイド
- **[docs/LINEWORKS_SSO_SETUP.md](./docs/LINEWORKS_SSO_SETUP.md)** - 詳細実装ドキュメント
- **[docs/CHANGES.md](./docs/CHANGES.md)** - 変更内容サマリー

詳細は [docs/README.md](./docs/README.md) を参照してください。

## 🔐 certs/ - SSL/TLS証明書

HTTPS接続用のSSL/TLS証明書ファイルが格納されています。

### 証明書ファイル

- **localhost+2.pem** - SSL証明書（公開鍵）
- **localhost+2-key.pem** - SSL秘密鍵（非公開）

### セキュリティ上の注意

⚠️ **これらのファイルは `.gitignore` に含まれており、Gitリポジトリにコミットされません。**

- 秘密鍵（`*-key.pem`）は絶対に共有しないでください
- 本番環境では正式なCA発行の証明書を使用してください
- このディレクトリの証明書は開発環境専用です

### 証明書の生成方法

```bash
# mkcertを使用してローカル開発用の証明書を生成
mkcert localhost 127.0.0.1 ::1

# 生成された証明書をclaude/certs/に移動
mv localhost+2.pem localhost+2-key.pem /Users/satoshi/Laravel/shin-on_wiki/claude/certs/
```

### Apache設定

証明書は以下のApache設定ファイルで参照されています：

```apache
# /opt/homebrew/etc/httpd/extra/httpd-bookstack-ssl.conf
SSLCertificateFile "/Users/satoshi/Laravel/shin-on_wiki/claude/certs/localhost+2.pem"
SSLCertificateKeyFile "/Users/satoshi/Laravel/shin-on_wiki/claude/certs/localhost+2-key.pem"
```

## 🚫 .gitignore

以下のパターンが `.gitignore` に追加されています：

```gitignore
# Claude Code - SSL Certificates
claude/certs/
*.pem
*.key
*.crt
```

これにより、証明書ファイルがGitリポジトリにコミットされることを防ぎます。

## 📝 使用方法

### ドキュメントの参照

プロジェクトルートから：
```bash
# ドキュメント目次を開く
cat claude/docs/README.md

# クイックスタートガイドを開く
cat claude/docs/README_LINEWORKS.md
```

### 証明書の確認

```bash
# 証明書の内容を確認
openssl x509 -in claude/certs/localhost+2.pem -text -noout

# 証明書の有効期限を確認
openssl x509 -in claude/certs/localhost+2.pem -noout -dates
```

## 🔗 関連ファイル・リンク

### プロジェクトファイル
- [プロジェクトルートREADME](../README.md) - プロジェクト全体の概要
- [Apache SSL設定](/opt/homebrew/etc/httpd/extra/httpd-bookstack-ssl.conf) - HTTPS設定
- [.gitignore](../.gitignore) - Git除外ファイル

### 外部リンク
- **[GitHubリポジトリ](https://github.com/satoshi-tateishi/shin-on_wiki)** - このプロジェクトのソースコード

## 📅 最終更新日

2025年11月11日

## 👥 作成者

Claude Code + satoshi
