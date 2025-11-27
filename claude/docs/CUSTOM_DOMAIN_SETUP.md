# 独自ドメイン設定ガイド

wiki.shin-on1981.com でBookStackにアクセスするための設定手順

## 現在の状況

| 項目 | 状態 |
|------|------|
| さくらDNS設定 | ✅ 完了 |
| DNS反映確認 | ✅ 完了 |
| Apache設定 | ✅ 完了 |
| SSL証明書取得 | ✅ 完了 |
| URL統一 | ✅ 完了（2025年11月27日） |

## 構成図

```
wiki.shin-on1981.com
        ↓ CNAME
shin-on.mydns.jp
        ↓ MyDNS.jp (DDNS)
147.192.23.179 (自宅サーバー)
        ↓ Apache (リバースプロキシ)
Docker (localhost:8083)
        ↓
BookStack
```

---

## 現在のApache設定

### 有効な設定ファイル

| ファイル | 用途 |
|----------|------|
| `/etc/apache2/sites-enabled/shin-on_wiki-le-ssl.conf` | HTTPS (ポート443) |
| `/etc/apache2/sites-enabled/wiki-shin-on1981-redirect.conf` | HTTP→HTTPSリダイレクト (ポート80) |

### HTTPSサイト設定

`/etc/apache2/sites-enabled/shin-on_wiki-le-ssl.conf`:

```apache
<IfModule mod_ssl.c>
    <VirtualHost *:443>
        ServerName wiki.shin-on1981.com
        ServerAdmin admin@shin-on.mydns.jp

        # リバースプロキシ設定
        ProxyPreserveHost On
        ProxyPass / http://localhost:8083/
        ProxyPassReverse / http://localhost:8083/

        # セキュリティヘッダー
        Header always set X-Frame-Options "SAMEORIGIN"
        Header always set X-Content-Type-Options "nosniff"
        Header always set X-XSS-Protection "1; mode=block"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
        Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        Header unset X-Powered-By
        Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"

        # プロキシヘッダーの転送
        RequestHeader set X-Forwarded-Proto "https"
        RequestHeader set X-Forwarded-Port "443"

        # ログファイル
        ErrorLog ${APACHE_LOG_DIR}/shin-on_wiki-error.log
        CustomLog ${APACHE_LOG_DIR}/shin-on_wiki-access.log combined
        LogLevel warn

        # SSL証明書設定
        Include /etc/letsencrypt/options-ssl-apache.conf
        SSLCertificateFile /etc/letsencrypt/live/wiki.shin-on1981.com/fullchain.pem
        SSLCertificateKeyFile /etc/letsencrypt/live/wiki.shin-on1981.com/privkey.pem
    </VirtualHost>
</IfModule>
```

### HTTPリダイレクト設定

`/etc/apache2/sites-enabled/wiki-shin-on1981-redirect.conf`:

```apache
<VirtualHost *:80>
    ServerName wiki.shin-on1981.com

    RewriteEngine on
    RewriteCond %{SERVER_NAME} =wiki.shin-on1981.com
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>
```

---

## 設定変更履歴

### 2025年11月27日: URL統一

古いドメインを無効化し、`wiki.shin-on1981.com` のみでアクセス可能に変更。

**無効化した設定ファイル:**
- `shin-on-apps.conf` (wiki.shin-on.mydns.jp:80)
- `shin-on-apps-le-ssl.conf` (wiki.shin-on.mydns.jp:443)

**変更内容:**
1. `shin-on_wiki-le-ssl.conf` の `ServerName` を `wiki.shin-on1981.com` に変更
2. `ServerAlias shin-on.mydns.jp` を削除
3. HTTP→HTTPSリダイレクト用の新設定ファイルを作成

### 2025年11月26日: 初期設定

独自ドメイン `wiki.shin-on1981.com` を追加（`shin-on.mydns.jp` のエイリアスとして）。

---

## メンテナンス

### 設定確認

```bash
# VirtualHost一覧
apachectl -S

# 設定テスト
sudo apachectl configtest
```

### SSL証明書

```bash
# 証明書情報
sudo certbot certificates

# 自動更新テスト
sudo certbot renew --dry-run
```

### アクセステスト

```bash
# HTTPS接続確認
curl -I https://wiki.shin-on1981.com

# HTTPリダイレクト確認
curl -I http://wiki.shin-on1981.com
```

---

## トラブルシューティング

### 502 Bad Gateway

Dockerコンテナが起動しているか確認:
```bash
docker ps | grep shin-on_wiki
docker compose -f docker-compose.production.yml up -d
```

### SSL証明書エラー

証明書を更新:
```bash
sudo certbot renew
sudo systemctl reload apache2
```

### 設定変更が反映されない

```bash
sudo apachectl configtest
sudo systemctl reload apache2
```

---

## 参考情報

- **MyDNS.jp ホスト名**: shin-on.mydns.jp
- **独自ドメイン**: wiki.shin-on1981.com
- **さくらDNS設定**: CNAME wiki → shin-on.mydns.jp.
- **本番URL**: https://wiki.shin-on1981.com

## 更新日

2025年11月27日
