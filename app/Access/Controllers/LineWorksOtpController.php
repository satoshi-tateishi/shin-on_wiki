<?php

namespace BookStack\Access\Controllers;

use BookStack\Access\LineWorksOtp\LineWorksBotService;
use BookStack\Access\LineWorksOtp\LineWorksOtpLog;
use BookStack\Access\LineWorksOtp\LineWorksOtpSession;
use BookStack\Access\LoginService;
use BookStack\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class LineWorksOtpController extends Controller
{
    use HandlesPartialLogins;

    public function __construct(
        protected LineWorksBotService $botService,
        protected LineWorksOtpSession $otpSession,
        protected LoginService $loginService
    ) {
    }

    /**
     * OTP入力画面を表示し、OTPを送信
     */
    public function verify()
    {
        try {
            $user = $this->currentOrLastAttemptedUser();
        } catch (\Exception $e) {
            return redirect('/login');
        }

        // 既にOTP検証済みの場合はログイン処理を再開
        if ($this->otpSession->isVerifiedForUser($user)) {
            $this->loginService->reattemptLoginFor($user);
            return redirect('/');
        }

        // 最新のユーザー情報を取得
        $user->refresh();

        // アカウントロックチェック
        if ($user->lineworks_otp_locked_until && now()->lt($user->lineworks_otp_locked_until)) {
            $remainingMinutes = (int) ceil(now()->diffInMinutes($user->lineworks_otp_locked_until, true));
            $this->setPageTitle(trans('lineworks_otp.title'));
            return view('lineworks-otp.verify', [
                'error' => trans('lineworks_otp.error_account_locked', ['minutes' => $remainingMinutes]),
            ]);
        }

        // セッションキーでOTP送信済みかチェック（リロード時の再送信防止）
        $otpSentKey = 'lineworks-otp-sent:' . $user->id;
        $otpAlreadySent = session()->has($otpSentKey);

        // OTPがまだ送信されていない、または有効期限切れの場合のみ送信
        $needsSend = !$user->lineworks_otp_code
            || !$user->lineworks_otp_expires_at
            || now()->gt($user->lineworks_otp_expires_at);

        Log::debug('LINE WORKS OTP verify check', [
            'user_id' => $user->id,
            'needsSend' => $needsSend,
            'otpAlreadySent' => $otpAlreadySent,
            'hasOtpCode' => !empty($user->lineworks_otp_code),
            'otpExpiresAt' => $user->lineworks_otp_expires_at,
        ]);

        if ($needsSend && !$otpAlreadySent) {
            if ($this->sendOtp($user)) {
                // OTP送信成功をセッションに記録
                session()->put($otpSentKey, true);
                Log::info('LINE WORKS OTP sent successfully', ['user_id' => $user->id]);
            } else {
                Log::error('LINE WORKS OTP send failed', ['user_id' => $user->id]);
            }
        }

        $this->setPageTitle(trans('lineworks_otp.title'));

        return view('lineworks-otp.verify');
    }

    /**
     * OTP検証処理
     */
    public function verifySubmit(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ], [
            'code.required' => trans('lineworks_otp.error_code_required'),
            'code.size' => trans('lineworks_otp.error_code_size'),
            'code.regex' => trans('lineworks_otp.error_code_regex'),
        ]);

        try {
            $user = $this->currentOrLastAttemptedUser();
        } catch (\Exception $e) {
            return redirect('/login')
                ->withErrors(['code' => trans('lineworks_otp.error_invalid_session')]);
        }

        // アカウントロックチェック
        if ($user->lineworks_otp_locked_until && now()->lt($user->lineworks_otp_locked_until)) {
            LineWorksOtpLog::log($user->id, 'locked');
            $remainingMinutes = (int) ceil(now()->diffInMinutes($user->lineworks_otp_locked_until, true));

            return back()->withErrors([
                'code' => trans('lineworks_otp.error_account_locked', ['minutes' => $remainingMinutes]),
            ]);
        }

        // コード期限チェック
        if (!$user->lineworks_otp_expires_at || now()->gt($user->lineworks_otp_expires_at)) {
            LineWorksOtpLog::log($user->id, 'failed');

            return back()->withErrors([
                'code' => trans('lineworks_otp.error_code_expired'),
            ]);
        }

        // コード検証
        if (!Hash::check($request->code, $user->lineworks_otp_code)) {
            // 失敗回数をインクリメント
            $user->increment('lineworks_otp_attempts');
            LineWorksOtpLog::log($user->id, 'failed');

            // 5回失敗でアカウントロック（3分）
            if ($user->lineworks_otp_attempts >= 5) {
                $user->update([
                    'lineworks_otp_locked_until' => now()->addMinutes(3),
                    'lineworks_otp_attempts' => 0,
                ]);
                LineWorksOtpLog::log($user->id, 'locked');

                return back()->withErrors([
                    'code' => trans('lineworks_otp.error_max_attempts'),
                ]);
            }

            $remainingAttempts = 5 - $user->lineworks_otp_attempts;

            return back()->withErrors([
                'code' => trans('lineworks_otp.error_code_invalid', ['attempts' => $remainingAttempts]),
            ]);
        }

        // 認証成功
        LineWorksOtpLog::log($user->id, 'verified');

        // OTP情報クリア
        $user->update([
            'lineworks_otp_code' => null,
            'lineworks_otp_expires_at' => null,
            'lineworks_otp_attempts' => 0,
            'lineworks_otp_locked_until' => null,
        ]);

        // OTP送信済みセッションキーをクリア
        session()->forget('lineworks-otp-sent:' . $user->id);

        // セッションをOTP検証済みとしてマーク
        $this->otpSession->markVerifiedForUser($user);

        // セッション再生成（セキュリティ）
        $request->session()->regenerate();

        // ログイン処理を再開
        $this->loginService->reattemptLoginFor($user);

        return redirect('/');
    }

    /**
     * OTP再送信
     */
    public function resend()
    {
        try {
            $user = $this->currentOrLastAttemptedUser();
        } catch (\Exception $e) {
            return redirect('/login')
                ->withErrors(['code' => trans('lineworks_otp.error_invalid_session')]);
        }

        // 最新のユーザー情報を取得
        $user->refresh();

        // アカウントロックチェック
        if ($user->lineworks_otp_locked_until && now()->lt($user->lineworks_otp_locked_until)) {
            $remainingMinutes = (int) ceil(now()->diffInMinutes($user->lineworks_otp_locked_until, true));

            return back()->withErrors([
                'code' => trans('lineworks_otp.error_account_locked', ['minutes' => $remainingMinutes]),
            ]);
        }

        // OTP送信
        if ($this->sendOtp($user)) {
            // セッションキーを更新（新しいOTPで上書き）
            session()->put('lineworks-otp-sent:' . $user->id, true);
            return back()->with('status', trans('lineworks_otp.success_resent'));
        }

        return back()->withErrors([
            'code' => trans('lineworks_otp.error_send_failed'),
        ]);
    }

    /**
     * OTPを生成して送信
     */
    protected function sendOtp($user): bool
    {
        try {
            // 新しいOTP生成
            $otp = $this->generateOTP();
            $hashedOtp = Hash::make($otp);

            // ユーザー情報更新
            $user->update([
                'lineworks_otp_code' => $hashedOtp,
                'lineworks_otp_expires_at' => now()->addMinutes(10),
                'lineworks_otp_attempts' => 0,
            ]);

            // LINE WORKS IDを取得（emailから - LINE WORKSではemail形式がユーザーID）
            $lineWorksId = $user->email;
            if (empty($lineWorksId)) {
                Log::error('LINE WORKS ID (email) not found for user', ['user_id' => $user->id]);
                return false;
            }

            // LINE WORKS Bot経由で送信
            $this->botService->sendOtpMessage($lineWorksId, $otp);

            LineWorksOtpLog::log($user->id, 'sent');

            return true;
        } catch (\Exception $e) {
            Log::error('LINE WORKS OTP send failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * カスタムOTP生成（4種類の数字から6桁を生成）
     *
     * セキュリティ強度: 10^6 = 1,000,000通り（全組み合わせ）
     * 実際の組み合わせ: C(10,4) × 4^6 = 210 × 4,096 = 860,160通り（約86%）
     */
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
}
