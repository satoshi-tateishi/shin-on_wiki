<?php

namespace BookStack\Access\LineWorksOtp;

use BookStack\Users\Models\User;

class LineWorksOtpSession
{
    /**
     * LINE WORKS OTP検証が必要かどうかを判定
     * 条件: external_auth_id が存在する（LINE WORKSログインユーザー）
     */
    public function isRequiredForUser(User $user): bool
    {
        // LINE WORKSユーザー（external_auth_idが設定されている）のみ対象
        return !empty($user->external_auth_id);
    }

    /**
     * 現在のセッションでOTPが検証済みかどうかを確認
     */
    public function isVerifiedForUser(User $user): bool
    {
        return session()->get($this->getOtpVerifiedSessionKey($user)) === 'true';
    }

    /**
     * 現在のセッションをOTP検証済みとしてマーク
     */
    public function markVerifiedForUser(User $user): void
    {
        session()->put($this->getOtpVerifiedSessionKey($user), 'true');
    }

    /**
     * OTP検証状態を保存するセッションキーを取得
     */
    protected function getOtpVerifiedSessionKey(User $user): string
    {
        return 'lineworks-otp-verified:' . $user->id;
    }
}
