<?php

namespace BookStack\Access\LineWorksOtp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use BookStack\Users\Models\User;

class LineWorksOtpLog extends Model
{
    /**
     * updated_atは使用しない
     */
    const UPDATED_AT = null;

    /**
     * テーブル名
     */
    protected $table = 'lineworks_otp_logs';

    /**
     * 一括代入可能な属性
     */
    protected $fillable = [
        'user_id',
        'action',
        'ip_address',
        'user_agent',
    ];

    /**
     * ユーザーとのリレーション
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * OTPログを記録するヘルパーメソッド
     *
     * @param int|null $userId ユーザーID
     * @param string $action アクション種別 ('sent', 'verified', 'failed', 'locked', 'resent')
     */
    public static function log(?int $userId, string $action): void
    {
        static::create([
            'user_id' => $userId,
            'action' => $action,
            'ip_address' => request()->ip() ?? '0.0.0.0',
            'user_agent' => request()->userAgent(),
        ]);
    }
}
