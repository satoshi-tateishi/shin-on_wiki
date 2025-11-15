<?php

namespace BookStack\Services;

use BookStack\Models\DropboxToken;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Dropbox\Client as DropboxClient;

class DropboxService
{
    private ?DropboxClient $client = null;

    private ?DropboxToken $tokenModel = null;

    public function __construct()
    {
        $this->tokenModel = DropboxToken::getActiveToken();
        if ($this->tokenModel) {
            $this->initializeClient();
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->tokenModel !== null && ! empty($this->tokenModel->access_token);
    }

    public function testConnection(): array
    {
        if (! $this->isAuthenticated()) {
            throw new Exception('Not authenticated with Dropbox');
        }

        try {
            $accountInfo = $this->getAccountInfo();

            return [
                'success' => true,
                'account_name' => $accountInfo['name']['display_name'],
                'account_id' => $accountInfo['account_id'],
                'email' => $accountInfo['email'] ?? null,
            ];
        } catch (Exception $e) {
            Log::error('Dropbox connection test failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getTokenInfo(): array
    {
        if (! $this->tokenModel) {
            throw new Exception('No token available');
        }

        $now = Carbon::now();
        $expiresAt = $this->tokenModel->access_token_expires_at;

        $isExpired = $expiresAt ? $now->isAfter($expiresAt) : false;
        $expiresIn = $expiresAt ? $expiresAt->diffInSeconds($now, false) : null;
        $willExpireSoon = $expiresAt ? $now->diffInMinutes($expiresAt) < 30 : false;

        return [
            'access_token_expires_at' => $expiresAt?->toISOString(),
            'access_token_expires_at_formatted' => $expiresAt?->format('Y-m-d H:i:s'),
            'is_access_token_expired' => $isExpired,
            'expires_in_seconds' => $expiresIn,
            'expires_in_minutes' => $expiresAt ? intval($now->diffInMinutes($expiresAt)) : null,
            'will_expire_soon' => $willExpireSoon,
            'has_refresh_token' => $this->tokenModel->hasValidRefreshToken(),
            'last_refreshed_at' => $this->tokenModel->last_refreshed_at?->toISOString(),
            'last_refreshed_at_formatted' => $this->tokenModel->last_refreshed_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function forceRefreshToken(): bool
    {
        if (! $this->tokenModel || ! $this->tokenModel->hasValidRefreshToken()) {
            throw new Exception('No valid refresh token available');
        }

        try {
            $this->refreshAccessToken();
            return true;
        } catch (Exception $e) {
            Log::error('Forced token refresh failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function uploadFile(string $filePath, string $remotePath): bool
    {
        if (! $this->isAuthenticated()) {
            throw new Exception('Not authenticated with Dropbox');
        }

        if (! file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        $fileContent = file_get_contents($filePath);
        $fileSizeMB = strlen($fileContent) / 1024 / 1024;

        // ファイルサイズ制限チェック（150MB）
        if (strlen($fileContent) >= config('backup.dropbox.max_file_size', 150 * 1024 * 1024)) {
            throw new Exception('File too large: '.number_format($fileSizeMB, 2).'MB');
        }

        try {
            // 階層フォルダを作成
            $this->ensureFoldersExist($remotePath);

            // Dropbox API v2を使用してアップロード
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->getValidAccessToken(),
                'Content-Type' => 'application/octet-stream',
                'Dropbox-API-Arg' => json_encode([
                    'path' => $remotePath,
                    'mode' => 'overwrite',
                    'autorename' => false,
                ]),
            ])->withBody($fileContent, 'application/octet-stream')
                ->post('https://content.dropboxapi.com/2/files/upload');

            if (! $response->successful()) {
                throw new Exception('Upload failed: '.$response->body());
            }

            Log::info('Backup uploaded successfully', [
                'file_path' => $remotePath,
                'file_size_mb' => round($fileSizeMB, 2),
            ]);

            return true;

        } catch (Exception $e) {
            // アクセストークンエラーの場合はリフレッシュを試行
            if (strpos($e->getMessage(), 'invalid_access_token') !== false) {
                if ($this->tokenModel && $this->tokenModel->hasValidRefreshToken()) {
                    $this->refreshAccessToken();

                    return $this->uploadFile($filePath, $remotePath); // 再試行
                }
            }
            throw $e;
        }
    }

    public function downloadFile(string $remotePath): string
    {
        if (! $this->isAuthenticated()) {
            throw new Exception('Not authenticated with Dropbox');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->getValidAccessToken(),
                'Dropbox-API-Arg' => json_encode(['path' => $remotePath]),
            ])->post('https://content.dropboxapi.com/2/files/download', null);

            if (! $response->successful()) {
                throw new Exception('Download failed: '.$response->body());
            }

            return $response->body();

        } catch (Exception $e) {
            // アクセストークンエラーの場合はリフレッシュを試行
            if (strpos($e->getMessage(), 'invalid_access_token') !== false) {
                if ($this->tokenModel && $this->tokenModel->hasValidRefreshToken()) {
                    $this->refreshAccessToken();

                    return $this->downloadFile($remotePath); // 再試行
                }
            }
            throw $e;
        }
    }

    public function listFolder(string $path = ''): array
    {
        if (! $this->isAuthenticated()) {
            throw new Exception('Not authenticated with Dropbox');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->getValidAccessToken(),
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/files/list_folder', [
                'path' => $path === '/' ? '' : $path,
                'recursive' => false,
                'include_media_info' => false,
                'include_deleted' => false,
            ]);

            if (! $response->successful()) {
                throw new Exception('List folder failed: '.$response->body());
            }

            return $response->json();

        } catch (Exception $e) {
            // アクセストークンエラーの場合はリフレッシュを試行
            if (strpos($e->getMessage(), 'invalid_access_token') !== false) {
                if ($this->tokenModel && $this->tokenModel->hasValidRefreshToken()) {
                    $this->refreshAccessToken();

                    return $this->listFolder($path); // 再試行
                }
            }
            throw $e;
        }
    }

    public function findBackupFolders(string $basePath = ''): array
    {
        $backups = [];
        $searchPath = $basePath ?: config('backup.dropbox.folder_path', '');

        try {
            $contents = $this->listFolder($searchPath);

            foreach ($contents['entries'] as $entry) {
                if ($entry['.tag'] === 'folder') {
                    $folderName = basename($entry['path_lower']);

                    // タイムスタンプフォルダかチェック（YYYY-MM-DD_HH-mm-ss形式）
                    if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $folderName)) {
                        $backups[] = [
                            'path' => trim($entry['path_lower'], '/'),
                            'name' => $folderName,
                            'full_path' => $entry['path_display'],
                        ];
                    } else {
                        // 年/月/日フォルダの場合は再帰的に検索
                        $subBackups = $this->findBackupFolders($entry['path_lower']);
                        $backups = array_merge($backups, $subBackups);
                    }
                }
            }
        } catch (Exception $e) {
            // フォルダが存在しない場合は無視
            if (strpos($e->getMessage(), 'not_found') === false) {
                throw $e;
            }
        }

        // 日付順でソート（新しい順）
        usort($backups, function ($a, $b) {
            return strcmp($b['name'], $a['name']);
        });

        return $backups;
    }

    public function generateBackupPath(string $timestamp, string $fileName): string
    {
        $now = Carbon::now(config('backup.timezone', 'Asia/Tokyo'));
        $basePath = config('backup.dropbox.folder_path', '');

        $pathParts = [
            $now->format('Y'),
            $now->format('m'),
            $now->format('d'),
            $timestamp,
            $fileName
        ];

        if (! empty($basePath)) {
            // Remove leading slash from basePath to avoid double slashes
            $basePath = ltrim($basePath, '/');
            $pathParts = array_merge([$basePath], $pathParts);
        }

        return '/' . implode('/', $pathParts);
    }

    private function initializeClient(): void
    {
        if ($this->tokenModel) {
            $accessToken = $this->getValidAccessToken();
            $this->client = new DropboxClient($accessToken);
        }
    }

    private function getValidAccessToken(): string
    {
        if (! $this->tokenModel) {
            throw new Exception('No valid token available');
        }

        // トークンが期限切れの場合、リフレッシュを試行
        if ($this->tokenModel->isAccessTokenExpired()) {
            if ($this->tokenModel->hasValidRefreshToken()) {
                return $this->refreshAccessToken();
            } else {
                throw new Exception('Access token expired and no refresh token available');
            }
        }

        return $this->tokenModel->access_token;
    }

    private function refreshAccessToken(): string
    {
        $response = Http::withBasicAuth(
            config('backup.dropbox.client_id'),
            config('backup.dropbox.client_secret')
        )->asForm()->post('https://api.dropbox.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->tokenModel->refresh_token,
        ]);

        if (! $response->successful()) {
            throw new Exception('Token refresh failed: '.$response->body());
        }

        $data = $response->json();

        $this->tokenModel->update([
            'access_token' => $data['access_token'],
            'access_token_expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 14400),
            'last_refreshed_at' => Carbon::now(),
        ]);

        // クライアントを再初期化
        $this->client = new DropboxClient($data['access_token']);

        Log::info('Successfully refreshed Dropbox access token');

        return $data['access_token'];
    }

    private function ensureFoldersExist(string $filePath): void
    {
        $pathParts = explode('/', dirname($filePath));
        $currentPath = '';

        foreach ($pathParts as $part) {
            if (empty($part)) {
                continue;
            }

            $currentPath .= '/'.$part;
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->getValidAccessToken(),
                    'Content-Type' => 'application/json',
                ])->post('https://api.dropboxapi.com/2/files/create_folder_v2', [
                    'path' => $currentPath,
                ]);

                // フォルダが既に存在する場合のエラーは無視
                if (! $response->successful() && strpos($response->body(), 'already_exists') === false) {
                    Log::warning('Failed to create folder', [
                        'path' => $currentPath,
                        'error' => $response->body(),
                    ]);
                }
            } catch (Exception $e) {
                // フォルダ作成エラーは警告レベルでログ出力のみ
                Log::warning('Folder creation failed', [
                    'path' => $currentPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function getAccountInfo(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->getValidAccessToken(),
            ])->post('https://api.dropboxapi.com/2/users/get_current_account', null);

            if (! $response->successful()) {
                throw new Exception('Failed to get account info: '.$response->body());
            }

            $accountInfo = $response->json();

            // アカウント名をトークンモデルに保存
            if ($this->tokenModel && empty($this->tokenModel->account_name)) {
                $this->tokenModel->update([
                    'account_name' => $accountInfo['name']['display_name'],
                    'account_id' => $accountInfo['account_id'],
                ]);
            }

            return $accountInfo;

        } catch (Exception $e) {
            // アクセストークンが無効な場合、リフレッシュを試行
            if (strpos($e->getMessage(), 'invalid_access_token') !== false) {
                if ($this->tokenModel && $this->tokenModel->hasValidRefreshToken()) {
                    $this->refreshAccessToken();

                    return $this->getAccountInfo(); // 再試行
                }
            }
            throw $e;
        }
    }
}
