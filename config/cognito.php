<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Keycloak JWT verification
    |--------------------------------------------------------------------------
    |
    | RS256 access/ID tokens are validated against the JWKS key matched by
    | `kid`, checking issuer, allowed algorithms, and a one-day max age.
    |
    | Migrated from Cognito to Keycloak (realm "master" on
    | https://wpiauth.joeterranova.net). Only `iss`/`jwks` changed here.
    |
    | The JWKS is fetched and cached from the realm's certs endpoint at runtime
    | (see `jwks_uri` below), so Keycloak signing-key rotation is picked up
    | automatically. The bundled `jwks` set is only an offline fallback used
    | when the IdP is unreachable.
    |
    */

    'kid' => env('COGNITO_TOKEN_KID', ''),

    'iss' => env('COGNITO_TOKEN_ISS', 'https://wpiauth.joeterranova.net/realms/master'),

    'algorithms' => ['RS256'],

    // Max token age (Node used "1d").
    'max_age' => '1 day',

    /*
    | JWKS endpoint (the IdP's public signing keys). Fetched and cached at
    | runtime by CognitoVerifier, which refetches when a token's `kid` is not
    | found in the cached set (i.e. after Keycloak rotates keys). Defaults to
    | the Keycloak realm's OIDC certs endpoint derived from `iss`; override with
    | COGNITO_JWKS_PATH. Set it empty to disable the fetch and use the bundled
    | `jwks` below only.
    */
    'jwks_uri' => env(
        'COGNITO_JWKS_PATH',
        rtrim(env('COGNITO_TOKEN_ISS', 'https://wpiauth.joeterranova.net/realms/master'), '/').'/protocol/openid-connect/certs'
    ),

    // How long (seconds) to cache the fetched JWKS before refetching.
    'jwks_cache_ttl' => (int) env('COGNITO_JWKS_TTL', 3600),

    /*
    | Public JWKS. Not secret. Offline fallback only: used when `jwks_uri` is
    | empty or the IdP cannot be reached. Bundled from the Keycloak realm's
    | certs endpoint; only the RS256 signing key (use: sig) is needed. Will go
    | stale when Keycloak rotates keys, so keep `jwks_uri` set in production.
    */
    'jwks' => [
        'keys' => [
            [
                'alg' => 'RS256',
                'e' => 'AQAB',
                'kid' => 'lR_3yd0VYgEu187TwO-PGvlNl9UF8J0VboC_hiPaodE',
                'kty' => 'RSA',
                'n' => '0YAfpxJtkRtrAdYoJ1IsxleO-HYIsglKDabm1x9MXNtMu8cOXhqrmp1F5nmmWRcG-8ZgmXbL9pwdhaT4QH_ESAsFOhw5AAYcrHI7gK4g_vBmbuNc3G7-ogajfsdICMBKaY2QhafjwEZ63Rt_XlBWRw7IJYBMWSncbdRFJgwpeQMgAABecQqht1WhMboboS-YXfBukpAvp_b1xSB4h9yWTbP2u1wJUhiseG7M9jrmHgNMlr8fwRqHPpW3zAtxQn1l0Mef0nlcEDbOjXPa9kpvJsmUHYRvejXanT5Cwp7g1SoUd0kLfTPPFVW6CRVhP9MuPKOCIYJaDie96Q3slE8igw',
                'use' => 'sig',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development / test bypass
    |--------------------------------------------------------------------------
    |
    | Node allowed token=DEV (=> user 1) and a numeric Authorization header
    | (=> that user id) when env was "development". We enable the same outside
    | production so the feature suite can act as specific users.
    |
    */

    'dev_bypass' => env('APP_ENV') !== 'production',

    'dev_user_id' => 1,
];
