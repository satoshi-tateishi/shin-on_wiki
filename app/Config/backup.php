<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backup Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for backup functionality, including database and file backups
    |
    */

    'default_disk' => 'local',

    'timezone' => env('BACKUP_TIMEZONE', 'Asia/Tokyo'),

    'retention' => [
        'days' => env('BACKUP_RETENTION_DAYS', 30),
        'minimum_count' => env('BACKUP_MINIMUM_RETENTION_COUNT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dropbox Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Dropbox backup functionality using OAuth 2.0
    |
    */
    'dropbox' => [
        // OAuth 2.0設定（リフレッシュトークン対応）
        'client_id' => env('DROPBOX_CLIENT_ID'),
        'client_secret' => env('DROPBOX_CLIENT_SECRET'),
        'redirect_uri' => env('DROPBOX_REDIRECT_URI'),

        // バックアップフォルダ
        'folder_path' => env('DROPBOX_BACKUP_FOLDER', ''),

        // トークンの有効期限設定（秒）
        'access_token_lifetime' => env('DROPBOX_ACCESS_TOKEN_LIFETIME', 14400),

        // OAuth スコープ
        'scope' => 'files.content.write files.content.read account_info.read',

        // アップロード制限（バイト）
        'max_file_size' => 150 * 1024 * 1024, // 150MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Backup Configuration
    |--------------------------------------------------------------------------
    */
    'database' => [
        'enabled' => true,
        'filename_format' => 'database_backup_{timestamp}.sql',
    ],

    /*
    |--------------------------------------------------------------------------
    | File Backup Configuration (BookStack用)
    |--------------------------------------------------------------------------
    */
    'files' => [
        'enabled' => true,
        'filename_format' => 'files_backup_{timestamp}.zip',
        'paths' => [
            'public/uploads',   // BookStackのアップロードファイル（画像など）
            'storage/uploads',  // BookStackの添付ファイル（PDFなど）
            'storage/logs',     // ログファイル
            '.env',             // 環境設定ファイル
        ],
        'exclude_paths' => [
            'storage/app/public/temp',
            'storage/app/backups/*',
            'node_modules/*',
            'vendor/*',
            '.git/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Test File Configuration
    |--------------------------------------------------------------------------
    */
    'test' => [
        'enabled' => false,
        'filename_format' => 'test_{timestamp}.txt',
        'content' => 'Dropbox backup test from shin-on_wiki at {timestamp}',
    ],
];
