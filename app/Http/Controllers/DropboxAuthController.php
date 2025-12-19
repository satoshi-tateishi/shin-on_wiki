<?php

namespace BookStack\Http\Controllers;

use BookStack\Models\DropboxToken;
use BookStack\Http\Controller;
use BookStack\Permissions\Permission;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DropboxAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        // 管理者権限チェック（BookStack形式）
        $this->checkPermission(Permission::SettingsManage);

        // State生成（CSRF防止）
        $state = Str::random(40);
        session(['dropbox_oauth_state' => $state]);

        $params = [
            'client_id' => config('backup.dropbox.client_id'),
            'redirect_uri' => config('backup.dropbox.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
            'token_access_type' => 'offline', // リフレッシュトークン取得
            'scope' => config('backup.dropbox.scope'),
        ];

        $authUrl = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query($params);

        return redirect($authUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            // 管理者権限チェック（BookStack形式）
            $this->checkPermission(Permission::SettingsManage);

            // State検証（CSRF防止）
            if ($request->state !== session('dropbox_oauth_state')) {
                throw new Exception('Invalid state parameter');
            }

            // エラーチェック
            if ($request->error) {
                throw new Exception('Authorization failed: ' . $request->error_description);
            }

            // Authorization Code取得
            $code = $request->code;
            if (! $code) {
                throw new Exception('Authorization code not received');
            }

            // トークン交換
            $tokenData = $this->exchangeCodeForTokens($code);

            // 既存のアクティブトークンを無効化
            DropboxToken::where('service_name', 'backup')
                ->where('is_active', true)
                ->update(['is_active' => false]);

            // 新しいトークンを保存
            $expiresAt = isset($tokenData['expires_in'])
                ? Carbon::now()->addSeconds($tokenData['expires_in'])
                : null;

            DropboxToken::create([
                'service_name' => 'backup',
                'access_token' => $tokenData['access_token'],
                'access_token_expires_at' => $expiresAt,
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'scope' => $tokenData['scope'] ?? config('backup.dropbox.scope'),
                'is_active' => true,
            ]);

            session()->forget('dropbox_oauth_state');

            Log::info('Dropbox OAuth authentication successful');

            return redirect('/settings/features')
                ->with('success', 'Dropbox認証が完了しました。');
        } catch (Exception $e) {
            Log::error('Dropbox OAuth callback failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect('/settings/features')
                ->with('error', 'Dropbox認証に失敗しました: ' . $e->getMessage());
        }
    }

    public function authStatus(): JsonResponse
    {
        // 管理者権限チェック（BookStack形式）
        try {
            $this->checkPermission(Permission::SettingsManage);
        } catch (Exception $e) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        try {
            $token = DropboxToken::getActiveToken();

            if (! $token) {
                return response()->json([
                    'authenticated' => false,
                    'message' => 'No active Dropbox token found',
                ]);
            }

            return response()->json([
                'authenticated' => true,
                'account_name' => $token->account_name,
                'account_id' => $token->account_id,
                'expires_at' => $token->access_token_expires_at?->toISOString(),
                'is_expired' => $token->isAccessTokenExpired(),
                'has_refresh_token' => $token->hasValidRefreshToken(),
                'last_refreshed_at' => $token->last_refreshed_at?->toISOString(),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to get Dropbox auth status', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'authenticated' => false,
                'error' => 'Failed to check authentication status',
            ], 500);
        }
    }

    public function refreshToken(): JsonResponse
    {
        // 管理者権限チェック（BookStack形式）
        try {
            $this->checkPermission(Permission::SettingsManage);
        } catch (Exception $e) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        try {
            $token = DropboxToken::getActiveToken();

            if (! $token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active token found',
                ], 404);
            }

            if (! $token->hasValidRefreshToken()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid refresh token available',
                ], 400);
            }

            $newAccessToken = $this->performTokenRefresh($token);

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'expires_at' => $token->fresh()->access_token_expires_at?->toISOString(),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to refresh Dropbox token', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to refresh token: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function revokeAuth(): JsonResponse
    {
        // 管理者権限チェック（BookStack形式）
        try {
            $this->checkPermission(Permission::SettingsManage);
        } catch (Exception $e) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        try {
            $token = DropboxToken::getActiveToken();

            if ($token) {
                $token->deactivate();
                Log::info('Dropbox authentication revoked');
            }

            return response()->json([
                'success' => true,
                'message' => 'Dropbox authentication revoked',
            ]);
        } catch (Exception $e) {
            Log::error('Failed to revoke Dropbox auth', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to revoke authentication',
            ], 500);
        }
    }

    private function exchangeCodeForTokens(string $code): array
    {
        $response = Http::withBasicAuth(
            config('backup.dropbox.client_id'),
            config('backup.dropbox.client_secret')
        )->asForm()->post('https://api.dropbox.com/oauth2/token', [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('backup.dropbox.redirect_uri'),
        ]);

        if (! $response->successful()) {
            throw new Exception('Token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    private function performTokenRefresh(DropboxToken $token): string
    {
        $response = Http::withBasicAuth(
            config('backup.dropbox.client_id'),
            config('backup.dropbox.client_secret')
        )->asForm()->post('https://api.dropbox.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
        ]);

        if (! $response->successful()) {
            throw new Exception('Token refresh failed: ' . $response->body());
        }

        $data = $response->json();

        $token->update([
            'access_token' => $data['access_token'],
            'access_token_expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 14400),
            'last_refreshed_at' => Carbon::now(),
        ]);

        Log::info('Successfully refreshed Dropbox access token');

        return $data['access_token'];
    }
}
