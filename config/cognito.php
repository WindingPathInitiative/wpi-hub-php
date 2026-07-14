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
    | NOTE: the JWKS below is bundled from the realm's certs endpoint
    | (/realms/master/protocol/openid-connect/certs). Keycloak rotates signing
    | keys, so when the realm's active `kid` changes, verification will 403
    | until this is refreshed. Prefer fetching/caching the JWKS at runtime.
    |
    */

    'kid' => env('COGNITO_TOKEN_KID', ''),

    'iss' => env('COGNITO_TOKEN_ISS', 'https://wpiauth.joeterranova.net/realms/master'),

    'algorithms' => ['RS256'],

    // Max token age (Node used "1d").
    'max_age' => '1 day',

    /*
    | Public JWKS. Not secret. Bundled from the Keycloak realm's certs endpoint;
    | only the RS256 signing key (use: sig) is needed for verification. May
    | later be fetched/cached from the IdP's well-known endpoint instead of
    | being bundled; override the whole set via a runtime loader if
    | COGNITO_JWKS_PATH is introduced.
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
