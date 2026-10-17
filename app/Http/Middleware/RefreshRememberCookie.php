<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Recaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class RefreshRememberCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->hasSession()) {
            return $response;
        }

        $guard = Auth::guard('web');
        $name = $guard->getRecallerName();
        $value = $request->cookie($name);
        $user = $guard->user();

        if (! $user || ! is_string($value) || Cookie::hasQueued($name)) {
            return $response;
        }

        $recaller = new Recaller($value);

        if ($recaller->valid()
            && $recaller->id() === (string) $user->getAuthIdentifier()
            && hash_equals((string) $user->getRememberToken(), $recaller->token())) {
            Cookie::queue($name, $value, (int) config('auth.guards.web.remember'));
        }

        return $response;
    }
}
