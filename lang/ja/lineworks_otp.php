<?php

return [
    'title' => '二段階認証',
    'description' => 'LINE WORKSに送信された6桁の認証コードを入力してください。',
    'code_label' => '認証コード',
    'code_placeholder' => '000000',
    'expires_in' => '有効期限: 10分',
    'verify_button' => '認証する',
    'resend_button' => '認証コードを再送信',

    'error_code_required' => '認証コードを入力してください。',
    'error_code_size' => '認証コードは6桁です。',
    'error_code_regex' => '認証コードは数字のみです。',
    'error_invalid_session' => 'セッションが無効です。再度ログインしてください。',
    'error_user_not_found' => 'ユーザーが見つかりません。',
    'error_account_locked' => 'アカウントがロックされています。:minutes分後に再試行してください。',
    'error_code_expired' => '認証コードの有効期限が切れています。再送信してください。',
    'error_code_invalid' => '認証コードが正しくありません。残り:attempts回試行できます。',
    'error_max_attempts' => '認証に5回失敗しました。アカウントが3分間ロックされます。',
    'error_send_failed' => '認証コードの送信に失敗しました。しばらく待ってから再試行してください。',

    'success_resent' => '認証コードを再送信しました。',
    'success_verified' => 'ログインしました。',
];
