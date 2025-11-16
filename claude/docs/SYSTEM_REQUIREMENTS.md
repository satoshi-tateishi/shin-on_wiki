# システム要件

shin·on Wiki by BookStack を Ubuntu on-premises サーバーで運用するための詳細なシステム要件です。

---

## 📋 目次

1. [オペレーティングシステム](#オペレーティングシステム)
2. [ハードウェア要件](#ハードウェア要件)
3. [ソフトウェア要件](#ソフトウェア要件)
4. [ネットワーク要件](#ネットワーク要件)
5. [ブラウザ要件](#ブラウザ要件)

---

## オペレーティングシステム

### サポート対象OS

| OS | バージョン | サポート状況 |
|---|---|---|
| Ubuntu LTS | 22.04 | ✅ 推奨 |
| Ubuntu LTS | 20.04 | ✅ サポート |
| Ubuntu | 18.04 | ⚠️ 非推奨（EOL） |
| Debian | 11 (Bullseye) | ✅ サポート |
| Debian | 10 (Buster) | ⚠️ 動作可能だが推奨しない |

### 推奨構成

- **OS**: Ubuntu 22.04 LTS (Jammy Jellyfish)
- **アーキテクチャ**: x86_64 (64-bit)
- **カーネル**: 5.15 以上
- **init システム**: systemd

---

## ハードウェア要件

### 最小要件

| リソース | 最小値 | 説明 |
|---|---|---|
| **CPU** | 1 コア | 2.0 GHz 以上 |
| **RAM** | 2 GB | スワップを含む |
| **ストレージ** | 10 GB | システム + アプリケーション |
| **ネットワーク** | 1 Mbps | インターネット接続 |

### 推奨要件

| リソース | 推奨値 | 説明 |
|---|---|---|
| **CPU** | 2 コア以上 | 2.4 GHz 以上 |
| **RAM** | 4 GB 以上 | 快適な動作のため |
| **ストレージ** | 50 GB 以上 | SSD 推奨 |
| **ネットワーク** | 10 Mbps 以上 | 安定した接続 |

### ストレージ詳細

**ディスク容量の内訳:**

| 用途 | 容量 | 説明 |
|---|---|---|
| OS | 5 GB | Ubuntu本体 |
| アプリケーション | 2 GB | BookStack + 依存関係 |
| データベース | 1 GB〜 | ページ数に応じて増加 |
| アップロードファイル | 可変 | ユーザーがアップロードした画像・PDF等 |
| ログ | 500 MB〜 | アプリケーション・Apacheログ |
| バックアップ | 可変 | ローカルバックアップ（一時的） |
| **合計** | **10 GB〜** | 最小構成 |

**ストレージタイプ:**
- **SSD**: 推奨（高速なI/O）
- **HDD**: 動作可能だがパフォーマンス低下

### RAM詳細

**メモリ使用量の内訳:**

| プロセス | メモリ使用量 |
|---|---|
| Ubuntu システム | 300〜500 MB |
| Apache | 100〜300 MB |
| MySQL | 400〜800 MB |
| PHP-FPM | 200〜400 MB |
| その他サービス | 100〜200 MB |
| **合計** | **1.1〜2.2 GB** |

**スワップ領域:**
- RAM 2GB の場合: 2GB のスワップ推奨
- RAM 4GB の場合: 1GB のスワップ推奨
- RAM 8GB 以上の場合: スワップ不要

---

## ソフトウェア要件

### Webサーバー

| ソフトウェア | バージョン | 必須モジュール |
|---|---|---|
| **Apache** | 2.4.41 以上 | mod_rewrite, mod_headers, mod_ssl |

**必須Apacheモジュール:**
```bash
# 有効化コマンド
sudo a2enmod rewrite headers ssl
```

- **mod_rewrite**: URL書き換え (Laravel routing に必須)
- **mod_headers**: HTTPヘッダー操作 (セキュリティヘッダー設定)
- **mod_ssl**: HTTPS サポート (LINE WORKS SSO に必須)

**推奨Apacheモジュール:**
- **mod_deflate**: Gzip圧縮
- **mod_expires**: ブラウザキャッシュ制御

### PHP

| 項目 | 要件 |
|---|---|
| **バージョン** | 8.2.0 以上（推奨 8.3.x） |
| **SAPI** | FPM または mod_php |
| **メモリ制限** | 512MB 以上 |
| **実行時間** | 300秒 以上（バックアップ時） |
| **アップロード制限** | 128MB 以上 |

**必須PHP拡張機能:**

| 拡張機能 | 用途 |
|---|---|
| **php-cli** | コマンドライン実行 |
| **php-curl** | HTTP リクエスト |
| **php-dom** | XML/HTML パース |
| **php-fileinfo** | ファイル情報取得 |
| **php-gd** | 画像処理 |
| **php-json** | JSON処理 |
| **php-mbstring** | マルチバイト文字列処理 |
| **php-xml** | XML処理 |
| **php-zip** | ZIP圧縮・解凍 |
| **php-ldap** | LDAP認証（将来の拡張用） |
| **php-mysql** | MySQL接続 |
| **pdo_mysql** | PDO MySQL ドライバー |

**インストール確認:**
```bash
php -m | grep -E "(curl|dom|fileinfo|gd|json|mbstring|xml|zip|ldap|pdo_mysql)"
```

**php.ini 推奨設定:**
```ini
memory_limit = 512M
upload_max_filesize = 128M
post_max_size = 128M
max_execution_time = 300
max_input_time = 300
```

### データベース

| 項目 | 要件 |
|---|---|
| **MySQL** | 8.0.0 以上（推奨 8.4.x） |
| **文字セット** | utf8mb4 |
| **照合順序** | utf8mb4_unicode_ci |
| **ストレージエンジン** | InnoDB |

**MySQL設定:**

my.cnf推奨設定:
```ini
[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
max_connections = 150
innodb_buffer_pool_size = 512M  # RAMの10-20%
```

**必須ユーティリティ:**
- **mysqldump**: バックアップに必須

確認:
```bash
which mysqldump
# /usr/bin/mysqldump
```

**Docker環境での対応:**

Dockerコンテナ内でバックアップ機能を使用する場合、`mysqldump`がインストールされている必要があります。

このプロジェクトでは、`dev/docker/Dockerfile`に以下が含まれています：

```dockerfile
RUN apt-get update && \
    apt-get install -y \
        # ... 他のパッケージ ...
        default-mysql-client && \
    rm -rf /var/lib/apt/lists/*
```

これにより、`mysqldump`コマンドがDockerイメージに含まれます。

### Node.js & NPM

| 項目 | 要件 |
|---|---|
| **Node.js** | 18.0.0 以上（推奨 22.x） |
| **NPM** | 9.0.0 以上（推奨 10.x） |

**用途:**
- フロントエンドアセット（CSS/JavaScript）のビルド
- 開発時のみ必要（本番環境ではビルド済みファイルを使用）

**インストール（Ubuntu 22.04）:**
```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

### Composer

| 項目 | 要件 |
|---|---|
| **バージョン** | 2.0.0 以上 |
| **PHPバージョン** | 設定されたPHPバージョンと一致 |

**用途:**
- PHP依存関係の管理
- デプロイ時に必須

### Git

| 項目 | 要件 |
|---|---|
| **バージョン** | 2.25.0 以上 |

**用途:**
- コードのデプロイ
- バージョン管理

### SSL/TLS証明書

| 項目 | 要件 |
|---|---|
| **証明書発行** | Let's Encrypt（推奨）または有料CA |
| **Certbot** | 1.0.0 以上 |
| **TLS バージョン** | TLS 1.2 以上 |

**重要:**
- LINE WORKS SSO は HTTPS を必須とします
- HTTP のみでは認証が動作しません

### システムユーティリティ

| ユーティリティ | 用途 |
|---|---|
| **curl** | HTTPリクエスト、スクリプト実行 |
| **wget** | ファイルダウンロード |
| **zip/unzip** | バックアップ圧縮・解凍 |
| **mysql-client** | MySQLクライアント、バックアップ |

---

## ネットワーク要件

### ファイアウォール

**開放が必要なポート:**

| ポート | プロトコル | 用途 |
|---|---|---|
| 22 | TCP | SSH（管理用） |
| 80 | TCP | HTTP（HTTPS リダイレクト用） |
| 443 | TCP | HTTPS（メインアクセス） |

**設定例（UFW）:**
```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### DNS

**必要なDNSレコード:**

| タイプ | ホスト名 | 値 | TTL |
|---|---|---|---|
| A | your-domain.com | サーバーIPアドレス | 3600 |
| A | www.your-domain.com | サーバーIPアドレス | 3600 |

**オプション（推奨）:**
| タイプ | ホスト名 | 値 |
|---|---|---|
| AAAA | your-domain.com | IPv6アドレス（あれば） |
| CAA | your-domain.com | 0 issue "letsencrypt.org" |

### 外部サービスへのアクセス

**必須の外部接続:**

| サービス | プロトコル | 用途 |
|---|---|---|
| auth.worksmobile.com | HTTPS | LINE WORKS 認証 |
| api.dropboxapi.com | HTTPS | Dropbox バックアップ |
| content.dropboxapi.com | HTTPS | Dropbox ファイル操作 |

**オプションの外部接続:**
| サービス | プロトコル | 用途 |
|---|---|---|
| SMTPサーバー | SMTP/SMTPS | メール送信 |
| gravatar.com | HTTPS | アバター画像 |

### 帯域幅

**推奨帯域幅:**
- **アップロード**: 5 Mbps 以上
- **ダウンロード**: 10 Mbps 以上

**トラフィック見積もり（10ユーザー）:**
- 通常時: 1〜2 Mbps
- バックアップ時: 5〜10 Mbps
- ピーク時: 10〜20 Mbps

---

## ブラウザ要件

### サポート対象ブラウザ

**デスクトップ:**

| ブラウザ | 最小バージョン | 推奨バージョン |
|---|---|---|
| Google Chrome | 90 | 最新版 |
| Mozilla Firefox | 88 | 最新版 |
| Microsoft Edge | 90 | 最新版 |
| Safari | 14 | 最新版 |

**モバイル:**

| ブラウザ | 最小バージョン | 推奨バージョン |
|---|---|---|
| Chrome (Android) | 90 | 最新版 |
| Safari (iOS) | 14 | 最新版 |
| Samsung Internet | 14 | 最新版 |

### 必要な機能

- **JavaScript**: 有効必須
- **Cookies**: 有効必須（セッション管理）
- **LocalStorage**: 推奨（一部機能で使用）
- **TLS 1.2+**: 必須（HTTPS接続）

---

## 開発環境との違い

| 項目 | 開発環境 | 本番環境 |
|---|---|---|
| OS | macOS (Docker) | Ubuntu Linux |
| Webサーバー | Apache (Proxy) + Docker | Apache (直接) |
| PHP実行 | Docker コンテナ | システムPHP |
| データベース | MySQL 8.4 (Docker) | MySQL 8.x (システム) |
| ポート | 8083 (HTTP), 8443 (HTTPS) | 80 (HTTP), 443 (HTTPS) |
| APP_ENV | local | production |
| APP_DEBUG | true | false |
| SSL証明書 | mkcert（自己署名） | Let's Encrypt（正式CA） |

---

## パフォーマンス最適化

### Apache

**MPM Prefork 設定 (php-mod 使用時):**
```apache
<IfModule mpm_prefork_module>
    StartServers             5
    MinSpareServers          5
    MaxSpareServers         10
    MaxRequestWorkers      150
    MaxConnectionsPerChild   0
</IfModule>
```

**MPM Event 設定 (php-fpm 使用時、推奨):**
```apache
<IfModule mpm_event_module>
    StartServers             2
    MinSpareThreads         25
    MaxSpareThreads         75
    ThreadLimit             64
    ThreadsPerChild         25
    MaxRequestWorkers      150
    MaxConnectionsPerChild   0
</IfModule>
```

### PHP OpCache

**opcache設定（php.ini）:**
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
```

### MySQL

**InnoDB設定:**
```ini
innodb_buffer_pool_size = 512M  # RAM の 10-20%
innodb_log_file_size = 128M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT
```

---

## セキュリティ要件

### システムセキュリティ

- [ ] ファイアウォール有効化（UFW推奨）
- [ ] SSH パスワード認証無効化（公開鍵認証のみ）
- [ ] rootログイン無効化
- [ ] fail2ban インストール（ブルートフォース攻撃対策）
- [ ] 自動セキュリティアップデート有効化

### アプリケーションセキュリティ

- [ ] HTTPS 必須（HTTP は HTTPS にリダイレクト）
- [ ] HSTS ヘッダー有効
- [ ] セキュリティヘッダー設定（X-Frame-Options 等）
- [ ] .env ファイルパーミッション 600
- [ ] storage/, bootstrap/cache/ パーミッション 775
- [ ] データベースパスワード強化

---

## 互換性マトリックス

### 動作確認済み構成

| OS | Apache | PHP | MySQL | Node.js | 状態 |
|---|---|---|---|---|---|
| Ubuntu 22.04 | 2.4.52 | 8.3.12 | 8.4.0 | 22.11.0 | ✅ 推奨 |
| Ubuntu 20.04 | 2.4.41 | 8.2.24 | 8.0.39 | 18.20.0 | ✅ サポート |
| Debian 11 | 2.4.54 | 8.2.20 | 8.0.36 | 18.19.0 | ✅ サポート |

---

## アップグレードパス

### PHP アップグレード

**8.2 → 8.3:**
```bash
sudo apt install php8.3 php8.3-fpm \
  php8.3-mysql php8.3-curl php8.3-gd \
  php8.3-mbstring php8.3-xml php8.3-zip
sudo a2dismod php8.2
sudo a2enmod php8.3
sudo systemctl restart apache2
```

### MySQL アップグレード

**8.0 → 8.4:**
```bash
# バックアップを取得
mysqldump -u root -p --all-databases > backup_before_upgrade.sql

# アップグレード実行
sudo apt update
sudo apt upgrade mysql-server

# アップグレード後の確認
mysql_upgrade -u root -p
```

---

## トラブルシューティング

### 要件確認コマンド

```bash
# OS バージョン
lsb_release -a

# CPU コア数
nproc

# メモリ容量
free -h

# ディスク容量
df -h

# PHP バージョンと拡張機能
php -v
php -m

# Apache バージョンとモジュール
apache2 -v
apache2ctl -M

# MySQL バージョン
mysql --version

# Node.js & NPM バージョン
node --version
npm --version

# Composer バージョン
composer --version
```

---

## 関連ドキュメント

- [DEPLOYMENT.md](./DEPLOYMENT.md) - デプロイ手順
- [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md) - デプロイチェックリスト
- [BACKUP_RESTORE.md](./BACKUP_RESTORE.md) - バックアップ・リストア手順

---

**最終更新**: 2025年11月17日
**作成者**: Claude Code + satoshi
