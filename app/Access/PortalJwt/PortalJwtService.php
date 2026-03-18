<?php

namespace BookStack\Access\PortalJwt;

use BookStack\Access\RegistrationService;
use BookStack\Exceptions\UserRegistrationException;
use BookStack\Http\HttpRequestService;
use BookStack\Users\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PortalJwtService
{
    public function __construct(
        protected RegistrationService $registrationService,
        protected HttpRequestService $http,
    ) {
    }

    /**
     * Validate a portal_jwt token and return its payload.
     *
     * @throws PortalJwtException
     */
    public function validateToken(string $token): \stdClass
    {
        $keys = $this->getPublicKeys();

        try {
            $payload = JWT::decode($token, $keys);
        } catch (\Exception $e) {
            throw new PortalJwtException('JWT validation failed: ' . $e->getMessage());
        }

        $expectedIss = config('portal_jwt.issuer');
        if (($payload->iss ?? '') !== $expectedIss) {
            throw new PortalJwtException("Invalid issuer: " . ($payload->iss ?? '(none)'));
        }

        $expectedAud = config('portal_jwt.audience');
        $aud = isset($payload->aud) ? (array) $payload->aud : [];
        if (!in_array($expectedAud, $aud)) {
            throw new PortalJwtException("Invalid audience");
        }

        if (isset($payload->is_active) && !$payload->is_active) {
            throw new PortalJwtException("User account is inactive");
        }

        return $payload;
    }

    /**
     * Find or create a BookStack user from a validated JWT payload.
     *
     * @throws PortalJwtException
     */
    public function findOrCreateUser(\stdClass $payload): User
    {
        $portalUuid = $payload->sub ?? null;
        $email = $payload->email ?? null;

        if (!$portalUuid || !$email) {
            throw new PortalJwtException('JWT missing required claims (sub, email)');
        }

        // 1. portal_uuid (sub) で external_auth_id を検索
        $user = User::query()
            ->where('external_auth_id', $portalUuid)
            ->first();

        // 2. email で既存ユーザーを検索し external_auth_id を更新（既存OIDCユーザーの移行）
        if (!$user) {
            $user = User::query()
                ->where('email', $email)
                ->whereNull('system_name')
                ->first();

            if ($user) {
                $user->external_auth_id = $portalUuid;
                $user->save();
            }
        }

        // 3. 新規作成
        if (!$user) {
            $familyName = $payload->family_name ?? '';
            $givenName = $payload->given_name ?? '';
            $name = trim($familyName . ' ' . $givenName) ?: ($payload->name ?? $email);

            try {
                $user = $this->registrationService->findOrRegister($name, $email, $portalUuid);
            } catch (UserRegistrationException $e) {
                throw new PortalJwtException($e->getMessage());
            }
        }

        return $user;
    }

    /**
     * Fetch the portal's JWKS public keys (cached).
     *
     * @return array<string, \Firebase\JWT\Key>
     * @throws PortalJwtException
     */
    protected function getPublicKeys(): array
    {
        $cacheKey = 'portal_jwt_jwks';
        $ttl = config('portal_jwt.jwks_cache_ttl', 3600);

        // OpenSSLAsymmetricKey はシリアライズ不可のため、JWKS JSON をキャッシュし
        // 鍵オブジェクトへのパースはリクエストごとに行う
        $jwks = Cache::remember($cacheKey, $ttl, function () {
            $jwksUrl = config('portal_jwt.jwks_url');

            try {
                $client = $this->http->buildClient(5);
                $response = $client->sendRequest(new Request('GET', $jwksUrl));
                return json_decode($response->getBody()->getContents(), true);
            } catch (\Exception $e) {
                throw new PortalJwtException('Failed to fetch JWKS from portal: ' . $e->getMessage());
            }
        });

        if (empty($jwks['keys'])) {
            throw new PortalJwtException('Invalid JWKS response from portal');
        }

        return JWK::parseKeySet($jwks, 'RS256');
    }
}
