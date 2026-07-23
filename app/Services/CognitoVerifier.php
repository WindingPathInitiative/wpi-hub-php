<?php

namespace App\Services;

use App\Exceptions\UserException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jose\Component\Core\JWK;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Throwable;

/**
 * Verifies a Cognito RS256 JWT against the configured JWKS. Ports the
 * verifyToken() logic from wpi-hub middlewares/token.js using
 * web-token/jwt-library (full JWKS support; swappable for Keycloak).
 *
 * Returns the decoded claims, or throws UserException('Invalid jwt', 403) on
 * any failure (matching the Node "return false" -> 403 behaviour).
 */
class CognitoVerifier
{
    public function verify(string $token): array
    {
        try {
            $jws = (new CompactSerializer)->unserialize($token);
        } catch (Throwable $e) {
            throw new UserException('Invalid jwt', 403, $e);
        }

        $header = $jws->getSignature(0)->getProtectedHeader();

        if (! in_array($header['alg'] ?? null, config('cognito.algorithms'), true)) {
            throw new UserException('Invalid jwt', 403);
        }

        $kid = $header['kid'] ?? null;
        if ($kid === null) {
            throw new UserException('Invalid jwt', 403);
        }

        // Try the cached JWKS first; if the key is unknown, Keycloak has likely
        // rotated its signing keys, so refetch once before giving up.
        $key = $this->selectKey($kid, false) ?? $this->selectKey($kid, true);

        if ($key === null) {
            throw new UserException('Invalid jwt', 403);
        }

        $verifier = new JWSVerifier(new AlgorithmManager([new RS256]));
        if (! $verifier->verifyWithKey($jws, $key, 0)) {
            throw new UserException('Invalid jwt', 403);
        }

        $claims = json_decode((string) $jws->getPayload(), true);
        if (! is_array($claims)) {
            throw new UserException('Invalid jwt', 403);
        }

        $this->assertClaims($claims);

        return $claims;
    }

    /**
     * Match a signing key by `kid` in the current JWKS, optionally forcing a
     * fresh fetch from the IdP (bypassing the cache) to pick up key rotation.
     */
    private function selectKey(string $kid, bool $forceRefresh): ?JWK
    {
        $jwks = JWKSet::createFromKeyData($this->loadJwks($forceRefresh));

        return $jwks->selectKey('sig', new RS256, ['kid' => $kid]);
    }

    /**
     * Load the JWKS. When `cognito.jwks_uri` is set it is fetched from the IdP
     * and cached for `cognito.jwks_cache_ttl` seconds; the bundled
     * `cognito.jwks` is used as a fallback when no URI is configured or the IdP
     * cannot be reached.
     */
    private function loadJwks(bool $forceRefresh = false): array
    {
        $uri = config('cognito.jwks_uri');

        if (! $uri) {
            return config('cognito.jwks');
        }

        if ($forceRefresh) {
            Cache::forget('cognito.jwks');
        }

        try {
            return Cache::remember('cognito.jwks', (int) config('cognito.jwks_cache_ttl', 3600), function () use ($uri) {
                $keys = Http::timeout(5)->get($uri)->throw()->json();

                if (! is_array($keys) || empty($keys['keys'])) {
                    throw new \RuntimeException("Malformed JWKS from {$uri}");
                }

                return $keys;
            });
        } catch (Throwable $e) {
            // IdP unreachable or returned garbage: fall back to bundled keys.
            return config('cognito.jwks');
        }
    }

    /**
     * Issuer, expiration, and 1-day max-age checks (the jwt.verify options in
     * the Node verifier).
     */
    private function assertClaims(array $claims): void
    {
        if (($claims['iss'] ?? null) !== config('cognito.iss')) {
            throw new UserException('Invalid jwt', 403);
        }

        $now = time();
        $maxAge = strtotime(config('cognito.max_age'), 0); // "1 day" => 86400

        if (isset($claims['exp']) && $claims['exp'] < $now) {
            throw new UserException('Invalid jwt', 403);
        }

        if (isset($claims['iat']) && $claims['iat'] < $now - $maxAge) {
            throw new UserException('Invalid jwt', 403);
        }
    }
}
