<?php

namespace App\Http\Middleware;

use App\Exceptions\UserException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the internal-only endpoints. Replaces Node's dual-port trust
 * (middlewares/network.js) with a shared-secret header (decision §6.3).
 *
 * Response shape is kept identical to the legacy network check so existing
 * callers/expectations don't break:
 *   - secret not configured  => 500 "Internal error"
 *   - missing/incorrect      => 403 "Request over insecure port"
 */
class InternalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('wpi.internal_secret');

        if (empty($secret)) {
            throw new UserException('Internal error', 500);
        }

        $provided = (string) $request->header(config('wpi.internal_header'), '');

        if (! hash_equals((string) $secret, $provided)) {
            throw new UserException('Request over insecure port', 403);
        }

        return $next($request);
    }
}
