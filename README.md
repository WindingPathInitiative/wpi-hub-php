# wpi-hub (PHP / Laravel)

The Winding Path Initiative User Hub, ported from the original Node.js
(Express + Bookshelf/Knex) service to **PHP 8.4 / Laravel 13**. It is a headless
REST API for managing members, a materialized-path hierarchy of organizational
units, and the offices/permissions layered on top.

See [`Migrate.md`](Migrate.md) for the full migration plan.

## Stack

| Concern | Implementation |
| --- | --- |
| Framework | Laravel 13 (API-only, routes under `/v1`) |
| ORM | Eloquent (existing **camelCase** columns preserved) |
| DB | MySQL 8 (`utf8mb3`, to match production exactly) |
| Auth | Cognito RS256 JWT via `web-token/jwt-library` (Keycloak-ready) |
| Permissions | `App\Services\PermissionService` (office-based) |
| Serialization | API Resources (`UserResource`, `OrgUnitResource`, `OfficeResource`) |
| Tests | PHPUnit (Pest is not yet L13-compatible) |
| Style | Laravel Pint |

## Setup

Requires PHP 8.4 with: `mbstring xml curl mysql(pdo_mysql) zip bcmath gmp intl`,
plus Composer and a MySQL 8 server.

```shell
composer install
cp .env.example .env        # fill DB creds + INTERNAL_SECRET
php artisan key:generate
php artisan migrate          # against your DB_DATABASE
php artisan db:seed          # default seed data (DatabaseSeeder)
php artisan serve            # http://localhost:8000
```

## Tests

Feature/unit tests run against a dedicated MySQL database (`hub_test` by
default; configured in `phpunit.xml`). HTTP feature tests seed the
`TestFixturesSeeder` (a snapshot of the beta data + a few personas) and freeze
the clock to when that data is valid.

```shell
./vendor/bin/phpunit
./vendor/bin/pint --test
```

Authentication in tests uses the **numeric `Authorization` dev bypass**
(`Authorization: <userId>`), enabled outside production.

## Architecture notes

- **Auth** — `AuthenticateCognito` (guard middleware) verifies the JWT, then
  find-or-creates the user; `cognito` requires a token, `cognito:optional`
  doesn't. `EnsureMember` (`member`) enforces the membership gate. Dev bypass:
  `token=DEV` ⇒ user 1, numeric `Authorization` ⇒ that user.
- **Internal endpoints** — `POST /v1/user/portal`, `GET /v1/user/{id}/internal`
  are guarded by a **shared secret** (`X-Internal-Secret`) instead of the Node
  dual-port trust.
- **Materialized path** — `App\Models\Concerns\HasMaterializedPath`
  (`parents/getParents/getChildren/getChain/isChild/hasChild/insertWithPath`);
  `Office` overrides the two methods whose legacy semantics differ.

## Divergences from the Node service

These were surfaced during the port (the Node controllers are the source of
truth; the legacy `test/*.js` suite was stale):

1. **`0000-00-00` sentinel → `1000-01-01`.** MySQL 8 strict mode rejects the
   zero date the Node code used for new/unapproved members; a valid sentinel is
   used instead (such users are `None`/`Pending`, so it is never gated on).
2. **Manual-create defaults.** `POST /v1/user` sets `membershipNumber=''` and
   the expiration sentinel explicitly (Node relied on non-strict MySQL defaults).
3. **Self-access checks.** Several Node self-checks compared the User *model* to
   an int id (always false — a bug; `show()` used `.id` correctly). Implemented
   as intended: a user may read/update/move their own record.
4. **Office update role ceiling.** The Node assistant role-ceiling check
   re-fetched the *same* office; the port fetches the actual parent office so an
   assistant cannot exceed its primary.
5. **Code / membership-number lookup regexes.** `^[a-z]{2}[-\d]*$` (org code)
   and `^[a-z]{2}\d{10}$` (membership number) only match 2-letter-prefixed
   values; the current `WPI…` data does not match, so those lookups return 400.
   Kept faithful to the source — revisit if path-by-code/number is needed.
6. **`office/verify/*` are not port/secret restricted** in the Node code,
   despite `Migrate.md` marking them internal. Left as in the code.
7. **Unknown update fields are dropped** (validated allowlist) rather than
   passed through to the model as the Node code did.
8. **Resources are returned with HTTP 200** on create (Node used `res.json`),
   not Laravel's default 201.

## Out of scope

The legacy `/dev/migrate*` importers (retired `crd`/`apps` databases) were
dropped per decision. Deployment target is Laravel Cloud when ready.
