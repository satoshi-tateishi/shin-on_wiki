<?php

namespace BookStack\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $service_name
 * @property string|null $access_token
 * @property Carbon|null $access_token_expires_at
 * @property string|null $refresh_token
 * @property string|null $account_id
 * @property string|null $account_name
 * @property string|null $scope
 * @property bool $is_active
 * @property Carbon|null $last_refreshed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DropboxToken extends Model
{
    protected $fillable = [
        'service_name',
        'access_token',
        'access_token_expires_at',
        'refresh_token',
        'account_id',
        'account_name',
        'scope',
        'is_active',
        'last_refreshed_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token_expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function isAccessTokenExpired(): bool
    {
        if (! $this->access_token_expires_at) {
            return false;
        }

        return Carbon::now()->isAfter($this->access_token_expires_at);
    }

    public function hasValidRefreshToken(): bool
    {
        return ! empty($this->refresh_token);
    }

    public static function getActiveToken(?string $serviceName = 'backup'): ?self
    {
        return self::where('service_name', $serviceName)
            ->where('is_active', true)
            ->first();
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }
}
