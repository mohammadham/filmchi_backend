<?php

namespace App\Http\Middleware;

use Closure;

/**
 * Authenticates the machine-to-machine automation endpoints (n8n): the caller
 * must present the shared token from RELAY_AUTOMATION_TOKEN, either as the
 * X-Fliksy-Token header or as a bearer token.
 *
 * These endpoints are the only unauthenticated-by-session write path into the
 * ingest queue, so an unset token disables them instead of allowing access.
 */
class VerifyAutomationToken
{
    public function handle($request, Closure $next)
    {
        $token = trim((string) config('media_relay.automation.token'));

        if ($token === '') {
            abort(503, 'The automation API is not configured (RELAY_AUTOMATION_TOKEN is empty).');
        }

        $given = trim((string) ($request->header('X-Fliksy-Token') ?: $request->bearerToken()));

        if ($given === '' || !hash_equals($token, $given)) {
            abort(403, 'Invalid automation token.');
        }

        return $next($request);
    }
}
