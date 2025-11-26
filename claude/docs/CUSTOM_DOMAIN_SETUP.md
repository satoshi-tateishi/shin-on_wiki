# 独自ドメイン設定ガイド

wiki.shin-on1981.com でBookStackにアクセスするための設定手順

## 現在の状況

| 項目 | 状態 |
|------|------|
| さくらDNS設定 | ✅ 完了 |
| DNS反映確認 | ✅ 完了 |
| Apache設定 | ✅ 完了 |
| SSL証明書取得 | ✅ 完了 |

## 構成図

```
wiki.shin-on1981.com
        ↓ CNAME
shin-on.mydns.jp
        ↓ MyDNS.jp (DDNS)
147.192.23.179 (自宅サーバー)
        ↓
BookStack
```

---

## 残作業

### Step 1: DNS反映確認

設定したCNAMEレコードが正しく反映されているか確認します。

```bash
# macOSのDNSキャッシュをクリア
sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder

# DNS確認
nslookup wiki.shin-on1981.com
nslookup shin-on.mydns.jp
```

**成功条件**: 両方が同じIPアドレスを返すこと

もし異なるIPが返る場合は、数時間待ってから再確認してください。

---

### Step 2: 自宅サーバーのApache設定

自宅サーバーにSSHで接続して作業します。

#### 2-1. 現在のApache設定ファイルを探す

```bash
# Ubuntu/Debian
sudo find /etc/apache2 -name "*.conf" | xargs grep -l "shin-on.mydns.jp"

# CentOS/RHEL
sudo find /etc/httpd -name "*.conf" | xargs grep -l "shin-on.mydns.jp"
```

#### 2-2. ServerAliasを追加

見つけた設定ファイルを編集し、`ServerAlias` を追加します：

```apache
<VirtualHost *:80>
    ServerName shin-on.mydns.jp
    ServerAlias wiki.shin-on1981.com    # ← この行を追加
    DocumentRoot /var/www/bookstack/public
    # ... 既存設定 ...
</VirtualHost>

# HTTPS用のVirtualHostがある場合はそちらにも追加
<VirtualHost *:443>
    ServerName shin-on.mydns.jp
    ServerAlias wiki.shin-on1981.com    # ← この行を追加
    # ... 既存設定 ...
</VirtualHost>
```

#### 2-3. 設定を反映

```bash
# 設定テスト
sudo apachectl configtest

# Apache再起動
sudo systemctl restart apache2
# または
sudo systemctl restart httpd
```

#### 2-4. HTTP接続テスト

ブラウザで http://wiki.shin-on1981.com にアクセスして動作確認

---

### Step 3: Let's EncryptでSSL証明書取得

#### 3-1. Certbotインストール（未インストールの場合）

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install certbot python3-certbot-apache

# CentOS/RHEL
sudo dnf install certbot python3-certbot-apache
```

#### 3-2. SSL証明書の取得

```bash
# 新しいドメイン用の証明書を取得
sudo certbot --apache -d wiki.shin-on1981.com
```

または、既存の shin-on.mydns.jp の証明書に追加する場合：

```bash
sudo certbot --apache -d shin-on.mydns.jp -d wiki.shin-on1981.com
```

#### 3-3. 自動更新の確認

```bash
sudo certbot renew --dry-run
```

#### 3-4. HTTPS接続テスト

ブラウザで https://wiki.shin-on1981.com にアクセスして動作確認

---

### Step 4: BookStackのAPP_URL変更

独自ドメインをメインにするため、APP_URLを変更します：

```bash
# 自宅サーバーで編集
sudo nano /var/www/shin-on_wiki/.env
```

```env
APP_URL=https://wiki.shin-on1981.com
```

変更後：

```bash
cd /var/www/shin-on_wiki
sudo php artisan config:clear
sudo php artisan cache:clear
```

---

### Step 5: 外部サービスのリダイレクトURL更新

APP_URLを変更した場合、OAuthを使用する外部サービスのリダイレクトURLも更新が必要です。

#### 5-1. LINEWORKS SSO

**LINEWORKS Developer Console** で変更：
https://developers.worksmobile.com/

- アプリ設定 → OAuth → Redirect URL
- 新URL: `https://wiki.shin-on1981.com/lineworks/callback`

#### 5-2. Dropbox バックアップ

**Dropbox App Console** で変更：
https://www.dropbox.com/developers/apps

- アプリ選択 → Settings → OAuth 2 → Redirect URIs
- 新URL: `https://wiki.shin-on1981.com/auth/dropbox/callback`

---

## トラブルシューティング

### DNS設定が反映されない

- DNSの反映には最大48時間かかる場合があります
- 異なるネットワーク（モバイル回線等）から確認してみてください

### 証明書取得時にエラー

- ポート80が外部からアクセス可能か確認（ルーターのポート転送設定）
- ファイアウォールでポート80/443が開放されているか確認

### 「このサイトにアクセスできません」エラー

- DNS反映を再確認
- 自宅サーバーのApacheが起動しているか確認
- ルーターのポート転送設定を確認

---

## 完了チェックリスト

- [x] DNS反映確認（両ドメインが同じIPを返す）
- [x] Apache ServerAlias設定追加
- [x] HTTP接続テスト成功
- [x] Let's Encrypt SSL証明書取得
- [x] HTTPS接続テスト成功
- [x] BookStack APP_URL変更
- [ ] LINEWORKS リダイレクトURL更新
- [ ] Dropbox リダイレクトURL更新
- [ ] HTTPからHTTPSへの自動リダイレクト確認（オプション）

---

## 参考情報

- **MyDNS.jp ホスト名**: shin-on.mydns.jp
- **独自ドメイン**: wiki.shin-on1981.com
- **さくらDNS設定**: CNAME wiki → shin-on.mydns.jp.

## 作成日

2025年11月27日
