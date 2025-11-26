<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('lineworks_otp_code', 255)->nullable()
                  ->after('external_auth_id')
                  ->comment('LINE WORKS OTP認証コード（ハッシュ化）');
            $table->timestamp('lineworks_otp_expires_at')->nullable()
                  ->after('lineworks_otp_code')
                  ->comment('OTPコード有効期限');
            $table->timestamp('lineworks_otp_locked_until')->nullable()
                  ->after('lineworks_otp_expires_at')
                  ->comment('アカウントロック解除時刻');
            $table->unsignedTinyInteger('lineworks_otp_attempts')->default(0)
                  ->after('lineworks_otp_locked_until')
                  ->comment('OTP失敗試行回数');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'lineworks_otp_code',
                'lineworks_otp_expires_at',
                'lineworks_otp_locked_until',
                'lineworks_otp_attempts',
            ]);
        });
    }
};
