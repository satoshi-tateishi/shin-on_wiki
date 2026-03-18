<?php

namespace BookStack\Http\Middleware;

use BookStack\Access\PortalJwt\PortalJwtException;
use BookStack\Access\PortalJwt\PortalJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Authenticate
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (config('auth.method') === 'portal_jwt' && !auth()->check()) {
            $cookieName = config('portal_jwt.cookie_name', 'portal_jwt');
            $token = $request->cookie($cookieName);
            if ($token) {
                try {
                    $service = app(PortalJwtService::class);
                    $payload = $service->validateToken($token);
                    $user = $service->findOrCreateUser($payload);
                    auth()->login($user);
                    $request->session()->regenerate();
                } catch (PortalJwtException $e) {
                    Log::warning('portal_jwt validation failed: ' . $e->getMessage());
                }
            }
        }

        if (!user()->hasAppAccess()) {
            if ($request->ajax()) {
                return response('Unauthorized.', 401);
            }

            if (config('auth.method') === 'portal_jwt') {
                $loginUrl = config('portal_jwt.login_url');
                $nextUrl = urlencode($request->fullUrl());
                return redirect($loginUrl . '?next=' . $nextUrl);
            }

            return redirect()->guest(url('/login'));
        }

        return $next($request);
    }
}
