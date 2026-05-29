<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDashboardAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() || $this->hasCloudflareIdentity($request)) {
            return $next($request);
        }

        return redirect()->guest('/');
    }

    private function hasCloudflareIdentity(Request $request): bool
    {
        $email = $request->headers->get('Cf-Access-Authenticated-User-Email')
            ?: $request->headers->get('X-Forwarded-Email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
