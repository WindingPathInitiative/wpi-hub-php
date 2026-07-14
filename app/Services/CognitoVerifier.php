<?php

namespace App\Services;

use App\Exceptions\UserException;
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
        $jwks = JWKSet::createFromKeyData(config('cognito.jwks'));

        try {
            $jws = (new CompactSerializer)->unserialize($token);
        } catch (Throwable $e) {
            throw new UserException('Invalid jwt', 403, $e);
        }

        $header = $jws->getSignature(0)->getProtectedHeader();

        if (! in_array($header['alg'] ?? null, config('cognito.algorithms'), true)) {
            throw new UserException('Invalid jwt', 403);
        }

        $key = isset($header['kid'])
            ? $jwks->selectKey('sig', new RS256, ['kid' => $header['kid']])
            : null;

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
