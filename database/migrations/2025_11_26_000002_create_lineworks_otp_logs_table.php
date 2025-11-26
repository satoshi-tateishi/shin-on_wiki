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
        Schema::create('lineworks_otp_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable()
                  ->comment('ユーザーID');
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
            $table->enum('action', ['sent', 'verified', 'failed', 'locked', 'resent'])
                  ->comment('アクション種別');
            $table->string('ip_address', 45)->comment('IPアドレス');
            $table->text('user_agent')->nullable()->comment('ユーザーエージェント');
            $table->timestamp('created_at')->useCurrent()->comment('記録日時');

            // インデックス
            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lineworks_otp_logs');
    }
};
