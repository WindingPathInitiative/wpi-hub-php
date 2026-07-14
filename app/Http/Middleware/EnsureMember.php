<?php

namespace App\Http\Middleware;

use App\Exceptions\UserException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects non-members / suspended / expelled / expired users. Ports the
 * `expired` middleware from wpi-hub middlewares/token.js (messages + statuses
 * preserved exactly).
 */
class EnsureMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new UserException('User not loaded', 500);
        }

        match ($user->membershipType) {
            'None', 'Pending' => throw new UserException('User is not a member', 403),
            'Suspended' => throw new UserException('User is suspended', 403),
            'Expelled' => throw new UserException('User is expelled', 403),
            'Uninvited' => throw new UserException('User has been declined membership', 403),
            default => null,
        };

        if ($user->membershipExpiration->lt(now())) {
            throw new UserException('User is expired', 403);
        }

        return $next($request);
    }
}
