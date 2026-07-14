<?php

namespace App\Services;

use App\Models\User;

/**
 * Find-or-create a User from Cognito token claims. Ports the find-or-create
 * branch of fakeToken() plus the Cognito field normalization from User.saving()
 * in wpi-hub.
 *
 * Lookup order (matches Node): by portalID (=sub), then by email, else create.
 * `?refresh=1` re-applies the claims to the stored user.
 */
class CognitoUserProvisioner
{
    /**
     * Sentinel "no real expiration" date. Node used '0000-00-00', which MySQL 8
     * strict mode rejects; new users always have membershipType None/Pending so
     * the EnsureMember gate stops them before expiration is ever consulted.
     */
    public const NO_EXPIRATION = '1000-01-01';

    public function fromClaims(array $claims, bool $refresh = false): User
    {
        $sub = $claims['sub'] ?? null;

        if ($sub !== null && $user = User::query()->where('portalID', $sub)->first()) {
            if ($refresh) {
                $this->apply($user, $claims);
                $user->save();
            }

            return $user;
        }

        $email = $claims['email'] ?? null;
        if ($email !== null && $user = User::query()->where('email', $email)->first()) {
            $this->apply($user, $claims);
            $user->save();

            return $user;
        }

        $user = new User;
        $this->apply($user, $claims, fresh: true);
        $user->save();

        return $user;
    }

    /**
     * Map Cognito claims onto User columns. Mirrors User.saving().
     */
    private function apply(User $user, array $claims, bool $fresh = false): void
    {
        if (isset($claims['sub'])) {
            $user->portalID = $claims['sub'];
        }

        if (isset($claims['name'])) {
            $name = $claims['name'];
            $split = strrpos($name, ' ');
            if ($split !== false) {
                $user->firstName = substr($name, 0, $split);
                $user->lastName = substr($name, $split + 1);
            } else {
                $user->firstName = $name;
            }
        }

        if (isset($claims['email'])) {
            $user->email = $claims['email'];
        }

        $user->address = $this->formatAddress($claims['address'] ?? null) ?? $user->address;

        if ($fresh) {
            $user->membershipType ??= 'None';
            $user->membershipNumber ??= '';
            $user->membershipExpiration ??= self::NO_EXPIRATION;
            $user->lastName ??= '';
            $user->nickname ??= trim(($user->firstName ?? '').' '.($user->lastName ?? ''));
        }
    }

    /**
     * Cognito delivers address as { formatted: "<json string>" }; flatten it to
     * a single line as Node did.
     */
    private function formatAddress(mixed $address): ?string
    {
        if (! is_array($address) || ! isset($address['formatted'])) {
            return null;
        }

        $parts = json_decode($address['formatted'], true);
        if (! is_array($parts)) {
            return null;
        }

        return sprintf(
            '%s, %s, %s %s, %s',
            $parts['street_address'] ?? '',
            $parts['locality'] ?? '',
            $parts['region'] ?? '',
            $parts['postal_code'] ?? '',
            $parts['country'] ?? '',
        );
    }
}
