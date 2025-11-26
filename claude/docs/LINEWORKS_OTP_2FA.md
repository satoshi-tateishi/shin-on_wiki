# shin·on Wiki by BookStack - LINE WORKS OTP 二段階認証 実装ドキュメント

## 概要

shin·on Wiki by BookStack に LINE WORKS Bot を使用した二段階認証（2FA）を実装しました。
LINE WORKS OIDC でログイン後、LINE WORKS Bot 経由で6桁のワンタイムパスワード（OTP）を送信し、追加の認証を行います。

## 実装した機能

1. **LINE WORKS Bot によるOTP送信**
   - JWT (RS256) 認証によるBot API連携
   - Service Account認証方式
   - 6桁の数字OTPを生成・送信

2. **セキュリティ機能**
   - OTP有効期限: 10分
   - 試行回数制限: 5回失敗で3分間のアカウントロック
   - ハッシュ化されたOTPをDBに保存（平文保存なし）
   - セッション単位での認証（初回ログイン時のみOTP要求）

3. **監査ログ**
   - OTP送信、検証成功、失敗、ロックの記録
   - IPアドレス、User-Agent の記録

4. **ユーザーエクスペリエンス**
   - 日本語/英語対応の多言語UI
   - OTP再送信機能
   - 残り試行回数の表示
   - ロック時間の表示

## アーキテクチャ

### 認証フロー

```
1. ユーザーがLINE WORKS OIDCでログイン
       ↓
2. LoginService が OTP検証が必要か判定
   - LINE WORKS ユーザー（external_auth_id あり）→ OTP必要
   - 既にOTP検証済み（セッション内）→ スキップ
       ↓
3. StoppedAuthenticationException で /lineworks-otp/verify にリダイレクト
       ↓
4. LineWorksOtpController::verify()
   - 新しいOTPを生成（4種類の数字から6桁）
   - OTPをハッシュ化してDBに保存
   - LINE WORKS Bot 経由でユーザーにOTP送信
       ↓
5. ユーザーがOTPを入力
       ↓
6. LineWorksOtpController::verifySubmit()
   - OTPを検証
   - 成功: セッションを検証済みとしてマーク、ログイン完了
   - 失敗: 試行回数インクリメント、5回でロック
```

### ディレクトリ構造

```
app/
├── Access/
│   ├── Controllers/
│   │   └── LineWorksOtpController.php  # OTP検証コントローラー
│   ├── LineWorksOtp/
│   │   ├── LineWorksBotService.php     # Bot API クライアント
│   │   ├── LineWorksOtpSession.php     # セッション管理
│   │   └── LineWorksOtpLog.php         # 監査ログモデル
│   └── LoginService.php                # OTP検証判定を追加
├── Exceptions/
│   └── StoppedAuthenticationException.php  # OTPリダイレクト追加
└── Users/Models/
    └── User.php                        # OTPフィールド追加

database/migrations/
├── 2025_11_26_000001_add_lineworks_otp_fields_to_users_table.php
└── 2025_11_26_000002_create_lineworks_otp_logs_table.php

resources/views/lineworks-otp/
└── verify.blade.php                    # OTP入力画面

lang/
├── ja/lineworks_otp.php                # 日本語翻訳
└── en/lineworks_otp.php                # 英語翻訳

routes/web.php                          # OTPルート追加
```

## 作成・修正したファイル

### 1. データベースマイグレーション

#### 2025_11_26_000001_add_lineworks_otp_fields_to_users_table.php

usersテーブルにOTP関連フィールドを追加:

```php
$table->string('lineworks_otp_code', 255)->nullable()
      ->comment('ハッシュ化されたOTPコード');
$table->timestamp('lineworks_otp_expires_at')->nullable()
      ->comment('OTP有効期限');
$table->timestamp('lineworks_otp_locked_until')->nullable()
      ->comment('アカウントロック解除日時');
$table->unsignedTinyInteger('lineworks_otp_attempts')->default(0)
      ->comment('OTP試行回数');
```

#### 2025_11_26_000002_create_lineworks_otp_logs_table.php

監査ログテーブルを作成:

```php
Schema::create('lineworks_otp_logs', function (Blueprint $table) {
    $table->id();
    $table->unsignedInteger('user_id')->nullable();
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->enum('action', ['sent', 'verified', 'failed', 'locked', 'resent']);
    $table->string('ip_address', 45);
    $table->text('user_agent')->nullable();
    $table->timestamp('created_at')->useCurrent();
});
```

**注意**: BookStackの `users.id` は `int unsigned` のため、`foreignId()` ではなく `unsignedInteger()` を使用。

---

### 2. app/Access/LineWorksOtp/LineWorksBotService.php

LINE WORKS Bot API クライアント。JWT認証でアクセストークンを取得し、メッセージを送信。

**主要メソッド**:
- `sendOtpMessage($userId, $otp)`: OTPメッセージを送信
- `getAccessToken()`: JWT認証でアクセストークン取得
- `generateJwt()`: RS256署名のJWTを生成

**設定値**（.envから取得）:
```php
$this->botId = config('services.lineworks.bot_id');
$this->clientId = config('services.lineworks.db_client_id');
$this->clientSecret = config('services.lineworks.db_client_secret');
$this->serviceAccount = config('services.lineworks.service_account');
$this->privateKeyPath = config('services.lineworks.private_key_path');
```

---

### 3. app/Access/LineWorksOtp/LineWorksOtpSession.php

セッションベースのOTP検証状態管理。

```php
class LineWorksOtpSession
{
    protected const OTP_VERIFIED_SESSION_KEY = 'lineworks-otp-verified';

    // LINE WORKSユーザーかどうか判定
    public function isRequiredForUser(User $user): bool
    {
        return !empty($user->external_auth_id);
    }

    // セッション内で検証済みか判定
    public function isVerifiedForUser(User $user): bool
    {
        return session()->get(self::OTP_VERIFIED_SESSION_KEY) === $user->id;
    }

    // 検証済みとしてマーク
    public function markVerifiedForUser(User $user): void
    {
        session()->put(self::OTP_VERIFIED_SESSION_KEY, $user->id);
    }
}
```

---

### 4. app/Access/LineWorksOtp/LineWorksOtpLog.php

監査ログのEloquentモデル。

```php
class LineWorksOtpLog extends Model
{
    public static function log(int $userId, string $action): void
    {
        static::create([
            'user_id' => $userId,
            'action' => $action,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
```

---

### 5. app/Access/Controllers/LineWorksOtpController.php

OTP検証のメインコントローラー。

**エンドポイント**:
- `GET /lineworks-otp/verify` - OTP入力画面表示、OTP送信
- `POST /lineworks-otp/verify` - OTP検証
- `POST /lineworks-otp/resend` - OTP再送信

**OTP生成アルゴリズム**:
```php
protected function generateOTP(): string
{
    // 0-9から4つの数字をランダム選択
    $availableDigits = range(0, 9);
    shuffle($availableDigits);
    $selectedDigits = array_slice($availableDigits, 0, 4);

    // 選ばれた4つの数字から6桁を生成
    $otp = '';
    for ($i = 0; $i < 6; $i++) {
        $otp .= $selectedDigits[array_rand($selectedDigits)];
    }

    return $otp;
}
```

**セキュリティ強度**: 約860,160通り（標準の1,000,000通りの86%）

---

### 6. app/Access/LoginService.php

**追加した機能**:

1. `LineWorksOtpSession` をコンストラクタインジェクション
2. `needsLineWorksOtpVerification()` メソッド追加
3. ログイン条件にOTP検証チェックを追加
4. 新しいログイン試行時にOTPセッションキーをクリア

```php
public function __construct(
    // ...
    protected LineWorksOtpSession $lineWorksOtpSession,
) {}

public function needsLineWorksOtpVerification(User $user): bool
{
    return !$this->lineWorksOtpSession->isVerifiedForUser($user)
        && $this->lineWorksOtpSession->isRequiredForUser($user);
}

// login() メソッドの条件に追加
if ($this->awaitingEmailConfirmation($user)
    || $this->needsMfaVerification($user)
    || $this->needsLineWorksOtpVerification($user)) {
    // ...
}

// 新しいログイン試行時にセッションキークリア
protected function setLastLoginAttemptedForUser(User $user, string $method, bool $remember): void
{
    session()->forget('lineworks-otp-sent:' . $user->id);
    // ...
}
```

---

### 7. app/Exceptions/StoppedAuthenticationException.php

OTP検証が必要な場合のリダイレクト処理を追加:

```php
public function getRedirectUrl(): string
{
    if ($this->loginService->needsLineWorksOtpVerification($this->user)) {
        return url('/lineworks-otp/verify');
    }
    // ... 既存のMFA/Email確認リダイレクト
}
```

---

### 8. app/Users/Models/User.php

OTPフィールドを `$fillable` と `$casts` に追加:

```php
protected $fillable = [
    'name',
    'email',
    'lineworks_otp_code',
    'lineworks_otp_expires_at',
    'lineworks_otp_locked_until',
    'lineworks_otp_attempts',
];

protected $casts = [
    'last_activity_at' => 'datetime',
    'lineworks_otp_expires_at' => 'datetime',
    'lineworks_otp_locked_until' => 'datetime',
];
```

---

### 9. routes/web.php

OTPルートを追加:

```php
Route::prefix('lineworks-otp')->group(function () {
    Route::get('/verify', [LineWorksOtpController::class, 'verify'])->name('lineworks-otp.verify');
    Route::post('/verify', [LineWorksOtpController::class, 'verifySubmit']);
    Route::post('/resend', [LineWorksOtpController::class, 'resend'])->name('lineworks-otp.resend');
});
```

---

### 10. resources/views/lineworks-otp/verify.blade.php

OTP入力画面。BookStackのデザインに合わせたBladeテンプレート。

**機能**:
- 6桁のOTP入力フォーム
- エラーメッセージ表示
- 成功メッセージ表示
- 再送信ボタン

---

### 11. config/services.php

LINE WORKS設定を追加:

```php
'lineworks' => [
    'api_base_url' => env('LINEWORKS_API_BASE_URL', 'https://www.worksapis.com/v1.0'),
    'auth_url' => env('LINEWORKS_AUTH_URL', 'https://auth.worksmobile.com/oauth2/v2.0/token'),
    'bot_id' => env('LINEWORKS_BOT_ID'),
    'bot_secret' => env('LINEWORKS_BOT_SECRET'),
    'db_client_id' => env('LINEWORKS_DB_CLIENT_ID'),
    'db_client_secret' => env('LINEWORKS_DB_CLIENT_SECRET'),
    'service_account' => env('LINEWORKS_SERVICE_ACCOUNT'),
    'private_key_path' => env('LINEWORKS_PRIVATE_KEY_PATH'),
],
```

---

### 12. .env

```env
# LINE WORKS BOT API Settings (2FA二段階認証用)
LINEWORKS_API_BASE_URL=https://www.worksapis.com/v1.0
LINEWORKS_AUTH_URL=https://auth.worksmobile.com/oauth2/v2.0/token
LINEWORKS_BOT_ID=11184625
LINEWORKS_BOT_SECRET=xxxxxxxxxxxxxxxxxxxxx
LINEWORKS_DB_CLIENT_ID=xxxxxxxxxxxxxxxxxxxxx
LINEWORKS_DB_CLIENT_SECRET=xxxxxxxxxx
LINEWORKS_SERVICE_ACCOUNT=xxxxx.serviceaccount@shin-on1981
LINEWORKS_PRIVATE_KEY_PATH=lineworks/private_key.pem
```

---

### 13. 翻訳ファイル

#### lang/ja/lineworks_otp.php

```php
return [
    'title' => '二段階認証',
    'description' => 'LINE WORKSに送信された6桁の認証コードを入力してください。',
    'code_label' => '認証コード',
    'code_placeholder' => '6桁のコードを入力',
    'verify_button' => '認証する',
    'resend_button' => 'コードを再送信',
    'success_resent' => '新しい認証コードを送信しました。',
    'error_code_required' => '認証コードを入力してください。',
    // ... その他のエラーメッセージ
];
```

#### lang/en/lineworks_otp.php

```php
return [
    'title' => 'Two-Factor Authentication',
    'description' => 'Please enter the 6-digit verification code sent to your LINE WORKS.',
    // ...
];
```

---

## LINE WORKS Bot設定

### Developer Console での設定

1. [LINE WORKS Developer Console](https://developers.worksmobile.com/) にアクセス
2. 「API 2.0」→「Bot」で新しいBotを作成
3. Bot IDを取得
4. 「Service Account」でサービスアカウントを作成
5. 秘密鍵（Private Key）をダウンロード
6. Developer Console App を作成し、Client ID/Secret を取得

### 秘密鍵の配置

```bash
# 秘密鍵を storage/app/lineworks/ に配置
storage/app/lineworks/private_key.pem
```

### Botのトーク権限

LINE WORKS管理者コンソールで、Botがユーザーにメッセージを送信できるよう権限を設定:
1. 「サービス」→「Bot」
2. 対象のBotを選択
3. 「トーク権限」を有効化

## セキュリティ上の考慮事項

### OTPの保護

1. **ハッシュ化保存**: OTPは `Hash::make()` でハッシュ化してDBに保存
2. **有効期限**: 10分で自動失効
3. **試行制限**: 5回失敗で3分間ロック
4. **ワンタイム**: 検証成功後はDBからOTPを削除

### セッション管理

1. **セッション単位**: OTP検証はログインセッション単位
2. **セッション再生成**: OTP検証成功後にセッションIDを再生成
3. **セッションキー管理**: 新しいログイン試行時に古いセッションキーをクリア

### 監査ログ

すべてのOTP操作を `lineworks_otp_logs` テーブルに記録:
- `sent`: OTP送信
- `verified`: 検証成功
- `failed`: 検証失敗
- `locked`: アカウントロック
- `resent`: OTP再送信

## トラブルシューティング

### OTPが送信されない

**確認事項**:
1. `.env` の LINE WORKS 設定が正しいか
2. 秘密鍵ファイルが存在するか: `storage/app/lineworks/private_key.pem`
3. ユーザーの `external_auth_id` が設定されているか
4. ログを確認: `grep "LINE WORKS" storage/logs/laravel.log`

### "認証コードの有効期限が切れています" エラー

**原因**:
1. OTPが10分以上経過している
2. OTPがDBに保存されていない（`$fillable` 設定漏れ）

**確認**:
```sql
SELECT lineworks_otp_code, lineworks_otp_expires_at FROM users WHERE id = ?;
```

### アカウントロック

**解除方法**:
```sql
UPDATE users SET lineworks_otp_locked_until = NULL, lineworks_otp_attempts = 0 WHERE id = ?;
```

### Bot APIエラー

**ログ確認**:
```bash
grep "LINE WORKS" storage/logs/laravel.log | tail -50
```

**よくあるエラー**:
- `401 Unauthorized`: JWT認証失敗、秘密鍵または設定を確認
- `403 Forbidden`: Botのトーク権限が不足
- `404 Not Found`: ユーザーIDが無効

## マイグレーション実行

```bash
# マイグレーション実行
php artisan migrate

# 確認
php artisan migrate:status
```

## 動作確認

### 1. ログインテスト

1. ブラウザで `https://localhost:8443/login` にアクセス
2. 「LINE WORKSでログイン」をクリック
3. LINE WORKSでログイン
4. OTP入力画面が表示される
5. LINE WORKSにOTPメッセージが届く
6. OTPを入力して認証
7. BookStackのホーム画面が表示される

### 2. ログ確認

```bash
# OTP関連のログ
grep "LINE WORKS OTP" storage/logs/laravel.log | tail -20

# 成功時のログ例:
# [DEBUG] LINE WORKS OTP verify check {"user_id":3,"needsSend":true,"otpAlreadySent":false}
# [INFO] LINE WORKS OTP sent successfully {"user_id":3}
# [INFO] LINE WORKS OTP log {"user_id":3,"action":"sent"}
# [INFO] LINE WORKS OTP log {"user_id":3,"action":"verified"}
```

### 3. 監査ログ確認

```sql
SELECT * FROM lineworks_otp_logs ORDER BY created_at DESC LIMIT 10;
```

## 参考資料

### LINE WORKS API ドキュメント

- [Bot API ガイド](https://developers.worksmobile.com/jp/docs/bot-overview)
- [Service Account 認証](https://developers.worksmobile.com/jp/docs/auth-jwt)
- [メッセージ送信 API](https://developers.worksmobile.com/jp/docs/bot-send-message)

### 参考実装

- `/Users/satoshi/Laravel/shin-on` プロジェクトの LINE WORKS OTP 実装を参考

## 実装日

2025年11月26日

## 実装者

Claude Code + satoshi
