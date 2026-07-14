<?php

namespace App\Http\Middleware;

use App\Exceptions\UserException;
use App\Models\User;
use App\Services\CognitoUserProvisioner;
use App\Services\CognitoVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request from a Cognito JWT. Ports middlewares/token.js
 * (normalize + query + fakeToken).
 *
 *   cognito        -> token required (Node token.parse(true))
 *   cognito:optional -> token optional; request continues unauthenticated
 *
 * Outside production, two dev bypasses are honoured (the test suite relies on
 * the numeric one to act as a specific user):
 *   - token "DEV"                  => user 1
 *   - numeric Authorization header => that user id
 */
class AuthenticateCognito
{
    public function __construct(
        private readonly CognitoVerifier $verifier,
        private readonly CognitoUserProvisioner $provisioner,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        if (config('cognito.dev_bypass') && $this->tryDevBypass($request)) {
            return $next($request);
        }

        $token = $this->extractToken($request);

        if ($token === null || $token === '') {
            if ($mode === 'optional') {
                return $next($request);
            }
            throw new UserException('Authorization Token not provided', 403);
        }

        $claims = $this->verifier->verify($token);

        if (empty($claims['email'])) {
            throw new UserException('Token does not include email', 403);
        }
        if (empty($claims['email_verified'])) {
            throw new UserException('Token needs email verification', 403);
        }

        $user = $this->provisioner->fromClaims($claims, $request->boolean('refresh'));
        $this->setUser($request, $user);

        return $next($request);
    }

    /**
     * Apply a dev bypass if present; returns true when it authenticated.
     */
    private function tryDevBypass(Request $request): bool
    {
        if ($this->extractToken($request) === 'DEV') {
            $this->actAs($request, (int) config('cognito.dev_user_id'));

            return true;
        }

        $auth = $request->header('Authorization');
        if ($auth !== null && is_numeric($auth)) {
            $this->actAs($request, (int) $auth);

            return true;
        }

        return false;
    }

    /**
     * Bearer header, then ?token, then (non-production) a "token" cookie.
     */
    private function extractToken(Request $request): ?string
    {
        $auth = $request->header('Authorization');
        if ($auth !== null && stripos($auth, 'bearer ') === 0) {
            return substr($auth, 7);
        }

        if ($request->query('token') !== null) {
            return (string) $request->query('token');
        }

        if (config('cognito.dev_bypass') && $request->hasCookie('token')) {
            return $request->cookie('token');
        }

        return $auth; // raw header (covers a bare numeric value)
    }

    private function actAs(Request $request, int $id): void
    {
        $user = User::find($id);
        if ($user === null) {
            throw new UserException('Invalid token', 403);
        }
        $this->setUser($request, $user);
    }

    private function setUser(Request $request, User $user): void
    {
        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);
    }
}
