# wpi-hub → PHP 8.4 / Laravel 13 Migration Plan

Porting the **Winding Path Initiative User Hub** from its current Node.js
(Express + Bookshelf/Knex + MySQL) stack to **PHP 8.4 / Laravel 13**.

This document is the working plan. It is split into:

1. What the existing app does (so behavior is preserved 1:1).
2. How to install & scaffold Laravel (authoritative steps from
   <https://laravel.com/for/agents>).
3. The target Laravel architecture and a component-by-component mapping.
4. A phased, checklist-style execution plan.
5. Risks, gotchas, and open questions.

---

## 1. Source application overview

The Node app is a **REST API** for managing club membership: members, a
hierarchy of organizational units, and the offices/permissions layered on
top of that hierarchy. It is designed to authenticate against **AWS Cognito**
(RS256 JWTs validated against a JWKS) and integrates with an external Portal
and an external audit service.

### 1.1 Stack being replaced

| Concern            | Node (current)                                  | Laravel (target)                                  |
| ------------------ | ----------------------------------------------- | ------------------------------------------------- |
| HTTP framework     | Express 4                                       | Laravel 13 routing/controllers                    |
| ORM                | Bookshelf on Knex                               | Eloquent                                          |
| Query builder      | Knex                                            | Eloquent / Query Builder                          |
| Migrations         | Knex migrations (`migrations/`)                 | Laravel migrations                                |
| Seeds              | Knex seeds (`seeds/*.default.js`)               | Laravel seeders                                   |
| Validation         | `validate.js`                                   | Form Requests / Validator                         |
| Auth               | `jsonwebtoken` + `jwk-to-pem` (Cognito)         | Custom guard/middleware (`firebase/php-jwt`)      |
| Promises/async     | Bluebird                                         | Native synchronous PHP + DB transactions          |
| Dates              | Moment                                          | Carbon                                            |
| HTTP client        | `request`                                       | Laravel HTTP client (Guzzle)                      |
| Serialization      | Bookshelf `serialize()` + custom `show()`       | API Resources                                     |
| Lint/test          | ESLint/JSCS + Mocha/Supertest                   | Pint + Pest/PHPUnit                               |

### 1.2 Data model (4 tables)

Derived from `migrations/` (final state after all 8 migrations applied).

**`users`**
- `id` (PK, auto-increment)
- `portalID` (string 36, unique) — Cognito `sub` / external Portal id
- `firstName` (notNullable), `lastName` (notNullable)
- `nickname`
- `address` (text)
- `email` (notNullable, indexed)
- `membershipType` enum → **`None, Pending, Uninvited, Full, Suspended, Expelled, Resigned`**, default `None`
- `membershipNumber` (string 15, notNullable, indexed)
- `membershipExpiration` (date, notNullable, indexed)
- `orgUnit` (integer, indexed) — FK-ish to `org_units.id`

**`org_units`**
- `id` (PK)
- `name` (notNullable)
- `code` (indexed)
- `venueType`, `location`, `website`
- `type` enum → **`Venue, Chapter, Region, Nation`** (indexed)
- `defDoc` (text)
- `parentPath` (string, notNullable, indexed) — **materialized path**, dot-delimited list of ancestor ids ending in self (e.g. `1.4.27`)

**`offices`**
- `id` (PK)
- `name` (notNullable), `email`
- `type` enum → **`Primary, Assistant`**
- `parentOfficeID` (integer, indexed) — set on Assistant offices
- `parentPath` (string, notNullable, indexed) — materialized path of offices
- `parentOrgID` (integer, notNullable, indexed) — the org unit this office governs
- `userID` (integer, indexed) — the officeholder (nullable = vacant)
- `roles` (json) — array of role keys

**`tokens`** (legacy; largely superseded by Cognito JWTs)
- `token` (uuid, PK)
- `user` (integer, notNullable, indexed)
- `expires` (datetime, indexed)

> Note: there are no DB-level foreign keys in the source; relationships are by
> convention. We will preserve column names exactly (camelCase) to avoid
> breaking the external Portal/audit integrations and any clients. Eloquent
> will be configured to use these non-conventional column names.

### 1.3 Core domain concepts (must be preserved exactly)

1. **Materialized-path hierarchy.** Both `org_units` and `offices` encode
   their ancestry in `parentPath` (e.g. `1.4.27`). Helpers derive ancestors
   and descendants from string prefix matching:
   - `parents()` → split path, drop self → ancestor ids.
   - `getChildren()` → `WHERE parentPath LIKE '<path>.%'`.
   - `getChain()` → ancestors **and** descendants, ordered by path.
   - `isChild(node)` / `hasChild(node)`.
   - New rows are inserted, then the path is rewritten to append the new id
     (`insertWithPath`, run inside a transaction). This must be ported faithfully.

2. **Office-based permissions** (`helpers/permissions.js`). A user's powers come
   from the **offices they hold** and the `roles` array on those offices.
   `admin` always passes. Three scoping checks:
   - `hasOverUser(user, role, officer)` — officer must hold a qualifying office
     at the user's org unit **or an ancestor** (special-case: user with no org
     unit ⇒ officer must be National (org id `1`)).
   - `hasOverUnit(unit, role, officer)` — officer's office must govern the unit,
     an ancestor, or National.
   - `hasOverOffice(office, role, officer)` — officer must sit above the target
     office in the office chain (handles Assistant → parent office).
   - `prefetch`/`normalizeOfficer` loads an officer's offices, optionally
     narrowed to a single "preset office" via `?useOffice=`/`body.useOffice`.
   - `checkOfficeRoles` enforces that you cannot grant/remove roles your own
     office (or the primary office, for assistants) does not hold.

3. **Authentication = Cognito JWT.** `middlewares/token.js`:
   - Reads `Authorization: Bearer <jwt>` (or `?token`, or cookie in dev).
   - Verifies RS256 signature against the configured **JWKS** key (matched by
     `kid`), checking `iss`, allowed algorithms, and `maxAge: 1d`.
   - Requires `email` + `email_verified` claims.
   - **Find-or-create user** from token claims: by `portalID`(=`sub`), else by
     `email`, else create. `?refresh=1` updates the stored user from claims.
   - **Dev bypass**: `token=DEV` ⇒ user 1; a numeric `Authorization` header ⇒
     that user id (used heavily by the test suite).
   - `expired` middleware rejects non-members / suspended / expelled / expired
     by `membershipType` and `membershipExpiration`.

4. **Internal-only endpoints.** The app listens on two ports (`PORT`=3000 and
   `INTERNAL_PORT`=3030). `middlewares/network.js` rejects requests whose
   `socket.localPort` is not the internal port. Endpoints behind this:
   `POST /v1/user/portal`, `GET /v1/user/:id/internal`, the `office/verify/*`
   routes, and `/dev/internal`.

5. **API versioning.** Routes are prefixed `/v{major}` where major comes from
   `package.json` version → currently **`/v1`**.

6. **Serialization with public vs private fields.** `models/base.js` `serialize`
   restricts output to `publicAttrs` unless `show()` (full) was called.
   `User.serialize` additionally strips PII (`email, address, firstName,
   lastName, fullName`) unless `showPrivate` is set, and always drops
   `portalID`. `OrgUnit` drops `parentPath`, escapes newlines in `defDoc`.
   `Office` parses/stringifies `roles` JSON.

7. **Membership number generation** (`User.makeMembershipNumber` /
   `prepareApproveMember`). Per-organization `numberFormat` (from
   `config/organizations.json`) builds a string from `string`/`year`/`month`/`max`
   segments; `max` queries the current DB maximum for the prefix and increments,
   with retry. Approval also sets `membershipType` and computes
   `membershipExpiration` from the org's `initialMembershipLength`.

8. **Audit logging** (`helpers/audit.js`). Fire-and-forget HTTP POST to an
   external audit service describing each mutating action (user, message,
   references, metadata, delta). Disabled when host is `bypass` or env is
   `testing`.

### 1.4 Endpoint inventory (behavior to reproduce)

All under `/v1` unless noted. **(I)** = internal-port only.

**User** (`controllers/user.js`)
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/user` | List + filter: `name` (LIKE first/last/nick), `email`, `membershipNumber`, `type`, `expired`, `orgUnit` (incl. descendants), `limit` (default 100), `offset`. |
| GET | `/user/:id` | `id` = numeric, `me`, or membership number (`/^[a-z]{2}\d{10}$/i`). Query flags `private`, `offices`, `children`. Private data gated by `user_read_private` (or self). |
| PUT | `/user/:id` | Update firstName/lastName/nickname/address/email. Self or `user_update`. |
| PUT | `/user/:id/approve` | Issue membership number; `user_approve`. Body `organization`. |
| PUT | `/user/:id/assign/:chapter` | Move user to chapter; complex self/permission rules; `user_assign`. Cannot target a Venue. |
| PUT | `/user/:id/suspend` | `?restore` toggles; `user_suspend`. |
| POST | `/user` | Manual create under an org unit; `user_add`; unique email. |
| POST | `/user/portal` | **(I)** Upsert by `portalID` from Portal changes. |
| GET | `/user/:id/internal` | **(I)** Full private record by id or membership number. |

**Org Unit** (`controllers/org-unit.js`)
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/org-unit/types` | Static list `[Nation, Region, Chapter, Venue]`. |
| GET | `/org-unit/venues` | From `config/venues.json`. |
| GET | `/org-unit/organizations` | From `config/organizations.json`. |
| GET | `/org-unit` | List + filter: `name`, `code`, `type`, `types` (csv), `parent` (via parentPath), `venue`, `limit`, `offset`. |
| GET | `/org-unit/:id` | `id` = numeric, `me`, or code (`/^[a-z]{2}[\-\d]*$/i`). Returns unit + `users` + `offices` (+ user summary) + `parents`/`children` chain with depth trimming. |
| POST | `/org-unit` | Create under parent; `org_create_<type>`; creates a manager office in a transaction. |
| PUT | `/org-unit/:id` | Update; `org_update` (+ `org_create_<type>` if changing code). |
| DELETE | `/org-unit/:id` | `org_create_<type>`; cannot delete root (id 1) or units with children; reparents users + deletes offices in a transaction. |

**Office** (`controllers/office.js`)
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/office/:id` | Office + parentOffice + orgUnit + user + parents/children chain. |
| POST | `/office` | Create Primary national office; requires `admin` over org 1. |
| GET | `/office/me` | Current user's offices (+ children). |
| GET | `/office/roles` | From `config/roles.json`. |
| PUT | `/office/:id/assign/:user` | Assign/vacate officeholder; `office_assign` or own-assistant rule. |
| PUT | `/office/:id` | Update name/email/roles; `office_update`; role-change validation. |
| POST | `/office/:id/assistant` | Create Assistant office; `office_create_assistants` or own. |
| DELETE | `/office/:id/assistant` | Delete Assistant; `office_create_assistants`/`_own_assistants`. |
| GET | `/office/verify/orgunit/:unit` | **(I)** `hasOverUnit` check, returns matching offices. |
| GET | `/office/verify/user/:user` | **(I)** `hasOverUser` check. |
| GET | `/office/verify/office/:office` | **(I)** `hasOverOffice` check. |

**Dev** (`controllers/dev.js`, non-production only): HTML login/switch helpers,
fake OAuth endpoints, and one-off `/dev/migrate*` data-import routes that pull
from legacy `crd`/`apps` MySQL databases. These are **out of scope** for the
port except as optional, clearly-marked dev tooling (see Phase 7).

### 1.5 Config files (`config/`)

`*.default.json` are templates; local copies hold secrets. Files:
`auth` (Cognito: `token_kid`, `token_iss`, `token_algorithms`), `jwks`
(Cognito public keys), `db`, `audit` (host), `roles` (role key → description),
`templates` (default offices per org-unit type), `organizations` (membership
number formats + lengths), `venues`, `clients`.

---

## 2. Installing & scaffolding Laravel (authoritative)

> These steps are the source of truth from <https://laravel.com/for/agents>,
> adapted to this environment. **Current environment status:**
> PHP 8.4.11 ✅ · npm 6.14.12 ✅ · Composer ❌ (missing) · `laravel` installer ❌ (missing) · bun ❌.
>
> `wpi-hub-php/` already exists as an empty git repo — Laravel will be
> scaffolded **into it** (or scaffolded adjacent and moved in) rather than
> creating a differently-named app.

### 2.1 Prerequisites

Verify versions:

```shell
php -v          # ✅ 8.4.11
composer -V     # ❌ install required
laravel --version   # ❌ install required
npm -v          # ✅ 6.14.12
```

Because Composer and the Laravel installer are missing, install them via
`php.new` (Linux). **Note:** the guide's Linux one-liner targets PHP 8.5; this
machine already has PHP 8.4 which satisfies our target, so we only need
Composer + the `laravel` installer. Prefer installing Composer directly to
avoid replacing the working PHP 8.4:

```shell
# Option A (preferred here): install Composer only, then the Laravel installer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
php -r "unlink('composer-setup.php');"
composer global require laravel/installer
# ensure ~/.config/composer/vendor/bin is on PATH

# Option B (guide default): full php.new toolchain (would also pull PHP 8.5)
# /bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installing, restart the shell (or re-source profile) so `composer` and
`laravel` are on `PATH`.

### 2.2 Create the application

Per the guide, default to the React starter kit unless told otherwise. **This
project is a headless REST API with no UI**, so the starter-kit frontend is
unnecessary — confirm with the user. Two viable shapes:

```shell
# API-only (recommended for this port — no starter kit UI):
laravel new wpi-hub-php --database=mysql --no-interaction --boost
# then: php artisan install:api   (adds routes/api.php + Sanctum if desired)

# Or, following the guide's default literally (React + SQLite):
laravel new wpi-hub-php --database=sqlite --react --npm --boost --no-interaction
```

Recommended deviations from the guide's defaults, with rationale:
- **Database = MySQL**, not SQLite — the app uses MySQL-specific SQL
  (`orderByRaw('CAST(SUBSTRING(...) AS UNSIGNED)')`, `CURRENT_TIMESTAMP`,
  enum columns). Match production.
- **No React/Vue/Livewire starter kit** — it's an API. (If the user wants the
  guide followed verbatim, use `--react`; the SPA simply goes unused.)
- Keep **`--boost`** — installs Laravel Boost guidelines for AI-assisted dev.
  Load `CLAUDE.md`/`AGENTS.md` immediately after creation.

### 2.3 After creation

```shell
cd wpi-hub-php
# confirm Laravel guidelines are loaded for the session (read CLAUDE.md / AGENTS.md)
composer global require laravel/cloud-cli   # optional, per guide
cloud skills:install                        # optional, per guide
composer run dev                            # starts app at http://localhost:8000
```

Run `composer run dev` in the background (or a second terminal). The app will
be available at **http://localhost:8000**. Deployment target per the guide is
**Laravel Cloud** (<https://cloud.laravel.com>) when ready to ship.

> **Decision needed from user** (see §6): API-only vs. guide-default React
> scaffold, and Composer-only vs. full `php.new` install. Recommended:
> API-only + Composer-only.

---

## 3. Target Laravel architecture

### 3.1 Directory mapping

| Node | Laravel |
| --- | --- |
| `app.js` | `bootstrap/app.php` + `public/index.php` (framework-provided) |
| `controllers/*.js` | `app/Http/Controllers/Api/V1/*Controller.php` |
| `controllers/index.js` (router) | `routes/api.php` (with `/v1` group) |
| `models/*.js` | `app/Models/*.php` (Eloquent) |
| `models/base.js` | trait/base class `app/Models/Concerns/HasMaterializedPath.php` + base `Model` |
| `middlewares/token.js` | `app/Http/Middleware/AuthenticateCognito.php` + a `cognito` guard, `EnsureMember` (the `expired` check) |
| `middlewares/network.js` | `app/Http/Middleware/InternalOnly.php` |
| `middlewares/cors.js` | `config/cors.php` (Laravel built-in) |
| `helpers/permissions.js` | `app/Services/PermissionService.php` + `presetOffice` middleware |
| `helpers/audit.js` | `app/Services/AuditService.php` (HTTP client) |
| `helpers/db.js` | `config/database.php` |
| `helpers/errors.js` (`UserError`) | `app/Exceptions/UserException.php` + handler in `bootstrap/app.php` |
| `helpers/validation.js` | Form Requests in `app/Http/Requests/` |
| `migrations/*.js` | `database/migrations/*.php` |
| `seeds/*.default.js` | `database/seeders/*.php` |
| `config/*.json` | `config/*.php` (or keep JSON + a small loader) + `.env` for secrets |
| serialization (`serialize`/`show`) | `app/Http/Resources/*Resource.php` |
| `test/*.js` (Mocha/Supertest) | `tests/Feature/*` (Pest/PHPUnit) |

### 3.2 Models (Eloquent)

Configure each model to use the **existing camelCase columns** and table names,
no Laravel timestamps (tables have none):

- `class User extends Model` — `$table='users'`, `public $timestamps=false`,
  cast `membershipExpiration` to `date`. Relationships: `orgUnit()`
  (`belongsTo OrgUnit, 'orgUnit'`), `offices()` (`hasMany Office, 'userID'`).
  Accessor `fullName`. Hidden/visible handled in Resources, not the model.
  Port `prepareApproveMember`/`makeMembershipNumber` to a
  **`MembershipNumberService`** (keeps the model lean; needs raw SQL for `max`).
  Port the Cognito `saving` normalization to a `saving` model event or to the
  auth find-or-create service.
- `class OrgUnit extends Model` — `$table='org_units'`, `$timestamps=false`.
  Relationships `users()`, `offices()` (`hasMany Office, 'parentOrgID'`).
  Uses `HasMaterializedPath` trait for `parents()/getParents()/getChildren()/
  getChain()/isChild()/hasChild()`. Static `getTypes()`, `getVenues()`.
- `class Office extends Model` — `$table='offices'`, `$timestamps=false`,
  cast `roles` to `array` (replaces manual `parse`/`format` JSON). Relationships
  `parentOffice()`, `orgUnit()` (`belongsTo OrgUnit,'parentOrgID'`), `user()`
  (`belongsTo User,'userID'`). `getParents()/getChildren()` via the path trait.
  Static `makeOfficeForUnit()` → move to an `OfficeFactoryService` or keep static.
- `class Token extends Model` — `$table='tokens'`, `$primaryKey='token'`,
  `$keyType='string'`, `$incrementing=false`, `$timestamps=false`. Generate UUID
  + 1h expiry on saving. (Legacy; include for dev/switch + completeness.)

**Materialized-path trait** centralizes the prefix logic and the
`insertWithPath` two-step (insert, then `UPDATE parentPath = '<path>.'+id`)
wrapped in `DB::transaction`. This is the riskiest port — cover with unit tests.

### 3.3 Permissions service

Port `helpers/permissions.js` verbatim into `PermissionService` with methods
`has`, `hasOverUser`, `hasOverUnit`, `hasOverOffice`, `prefetch`
(=`normalizeOfficer`), `checkOfficeRoles`, plus `mapCollection`/`unmapCollection`
helpers as private methods. Replace Bluebird `Promise.any` (used in user assign:
"pass if either unit OR user check passes") with try/catch-or-collect logic.
The **preset office** (`?useOffice=`) becomes request-scoped state — implement
as a middleware that stashes the value on the request / a scoped singleton,
rather than the module-global in the Node version.

Throwing behavior: permission failures throw → become **403** responses. Map
through `UserException`.

### 3.4 Auth (Cognito JWT)

Implement a **custom guard** `cognito` (or a single middleware) that:
1. Extracts the bearer token (header, `?token`, or dev cookie).
2. Loads JWKS from config, selects key by `kid`, verifies RS256 with
   `firebase/php-jwt` (or `web-token/jwt-framework`), validating `iss`,
   algorithms, and 1-day max age.
3. Requires `email` + `email_verified`.
4. Find-or-create the `User` from claims (by `portalID`/`sub`, then `email`,
   then create); honor `?refresh=1`.
5. Sets the authenticated user; the controller reads `Auth::user()` where the
   Node code read `req.token.get('user')` / `req.user`.

Provide the **dev bypass** behind `APP_ENV !== production`: `token=DEV` ⇒ user 1;
numeric `Authorization` ⇒ that user id. The test suite depends on this.

`token.validate()` (token optional/required) and `token.parse()` (also load
user) map to route middleware variants; `token.expired` → `EnsureMember`
middleware applying the membershipType/expiration gate.

### 3.5 Validation

Replace `validate.js` constraint objects with **Form Requests**, one per
mutating endpoint (e.g. `UpdateUserRequest`, `CreateUserRequest`,
`CreateOrgUnitRequest`, `UpdateOrgUnitRequest`, `CreateOfficeRequest`,
`AssistantRequest`). Mirror current rules (string/length/email/url/array/
numeric-strict/inclusion). Preserve the current error message shape
(`"Invalid data provided: ..."`, status 400) via the exception handler so
clients don't break.

### 3.6 Serialization (API Resources)

- `UserResource`: default = `publicAttrs` only (`id, membershipNumber, nickname,
  membershipType, membershipExpiration`); when `showFull`, include the rest;
  include PII only when `showPrivate`; always drop `portalID`. Support the
  `offices`/`children` expansions.
- `OrgUnitResource`: drop `parentPath`, drop null `venueType`, escape `\n` in
  `defDoc`; nested `parents`/`children`/`users`/`offices`.
- `OfficeResource`: roles as array; nest `user` summary, `parents`, `children`.

Carry the `show()`/`showPrivate` toggles as Resource constructor flags or
`additional()`/conditional `when()` clauses.

### 3.7 Error handling

`UserException(message, status, dev)` → render JSON `{ message, status }` with
the given HTTP status (default 500). In `bootstrap/app.php`'s exception handler,
also translate validation failures and "not found" into the legacy message/
status conventions. Keep stack traces out of production responses (Laravel
default), matching the Node prod handler.

### 3.8 Internal-only endpoints

Laravel apps typically run a single port. Options (decide in §6):
- **(a)** Replicate two listeners: serve a second port via a separate process /
  reverse-proxy and check the local port in `InternalOnly` middleware.
- **(b)** Replace port-based trust with a **shared secret header** / IP allowlist
  / network policy for the `(I)` routes. **Recommended** — port-based trust is
  brittle and not idiomatic; a signed internal token or allowlisted source is
  clearer and just as restrictive. Document the change for the Portal/audit
  callers.

### 3.9 Config

Move JSON config into Laravel `config/*.php` files reading secrets from `.env`:
`config/cognito.php` (kid, iss, algorithms, JWKS — JWKS may stay a JSON asset
or be fetched/cached), `config/audit.php` (host), `config/wpi.php` (roles,
templates, organizations, venues). Keep `roles`/`templates`/`organizations`/
`venues` as data (PHP arrays or bundled JSON read via a tiny repository class),
since they're static reference data, not secrets.

---

## 4. Phased execution plan

Each phase is independently reviewable; tests are added alongside, not at the end.

### Phase 0 — Tooling & scaffold
- [ ] Confirm decisions in §6 with the user (API-only vs React; Composer-only vs php.new; internal-port strategy).
- [ ] Install Composer + `laravel` installer (§2.1).
- [ ] Scaffold Laravel 13 into `wpi-hub-php/` with MySQL (§2.2), `--boost`.
- [ ] `php artisan install:api` if using API routes/Sanctum.
- [ ] Load Boost `CLAUDE.md`/`AGENTS.md`; configure Pint, Pest/PHPUnit.
- [ ] `.env` for DB + Cognito + audit; get `composer run dev` serving.

### Phase 1 — Schema & models
- [ ] Port all 8 Knex migrations into Laravel migrations producing the **final**
      schema (one clean migration per table is acceptable since this is a fresh
      port; preserve exact column names/types/indexes/enums).
- [ ] Implement `User`, `OrgUnit`, `Office`, `Token` Eloquent models with
      correct `$table`, `$timestamps=false`, casts, relationships.
- [ ] Implement `HasMaterializedPath` trait (`parents`, `getParents`,
      `getChildren`, `getChain`, `isChild`, `hasChild`, `insertWithPath`).
- [ ] Port seeders (`offices`, `org-units`, `users` defaults).
- [ ] **Unit tests** for the materialized-path trait and relationships.

### Phase 2 — Auth & middleware
- [ ] Cognito JWT verification + find-or-create (`AuthenticateCognito`/guard).
- [ ] Dev bypass tokens (`DEV`, numeric header) gated to non-production.
- [ ] `EnsureMember` (expired/suspended/etc. gate).
- [ ] `InternalOnly` middleware (per §3.8 decision).
- [ ] `PresetOffice` middleware (`?useOffice`).
- [ ] `UserException` + exception handler (legacy JSON shape).
- [ ] **Feature tests** for auth happy/again-path + dev bypass.

### Phase 3 — Permissions service
- [ ] Port `PermissionService` (`has`, `hasOverUser`, `hasOverUnit`,
      `hasOverOffice`, `prefetch`, `checkOfficeRoles`).
- [ ] **Unit tests** mirroring `test/permission.js` scenarios.

### Phase 4 — Org Unit & Office endpoints
- [ ] `OrgUnitController` (types/venues/organizations/list/show/create/update/delete)
      incl. chain assembly + depth trimming (`getChain`/`sortChain`/`setDepth`).
- [ ] `OfficeController` (show/create/me/roles/assign/update/assistant create+delete/verify*).
- [ ] `OfficeFactoryService.makeOfficeForUnit` + transactional org-unit create/delete.
- [ ] Resources: `OrgUnitResource`, `OfficeResource`.
- [ ] **Feature tests** porting `test/org-unit.js`, `test/office.js`.

### Phase 5 — User endpoints
- [ ] `UserController` (list/show/update/approve/assign/suspend/create/portal/internal).
- [ ] `MembershipNumberService` (`prepareApproveMember`, `makeMembershipNumber`
      incl. MySQL `max` query + retry).
- [ ] Cognito/portal field normalization on user save.
- [ ] `UserResource` (public/private/offices/children).
- [ ] **Feature tests** porting `test/user.js`.

### Phase 6 — Cross-cutting
- [ ] `AuditService` (HTTP POST, bypass/testing short-circuit) wired into mutations.
- [ ] CORS config; `/v1` welcome route; 404 handling parity.
- [ ] Config: `cognito.php`, `audit.php`, `wpi.php` + `.env.example`.

### Phase 7 — Dev tooling & cutover (optional / final)
- [ ] Optionally port `/dev` switch/list helpers (non-production only).
- [ ] Decide whether to port the one-off `/dev/migrate*` legacy importers
      (likely **skip** — they target retired `crd`/`apps` databases).
- [ ] Full test suite green; `pint` clean.
- [ ] Parity check old vs new responses on a representative request set.
- [ ] Update README; deployment to Laravel Cloud.

---

## 5. Test strategy

The Node suite (`test/`, Mocha/Supertest) is the behavioral spec. Port it to
**Pest/PHPUnit feature tests** preserving cases from `auth.js`, `user.js`,
`org-unit.js`, `office.js`, `permission.js`. The dev numeric-`Authorization`
bypass is what lets tests act as specific users (admin=1, nc=2, user=5,
expired=6, suspended=11 per `test/index.js`) — keep that mechanism. Seed the
same fixture hierarchy. Run against a MySQL test database (not SQLite) due to
raw-SQL/enum usage.

---

## 6. Open questions / decisions for the user

1. **Scaffold shape:** API-only (recommended, no SPA) vs. the guide's default
   React starter kit (unused UI)? A: API Only
2. **Toolchain install:** Composer-only (keeps existing PHP 8.4, recommended)
   vs. full `php.new` (would also install PHP 8.5)? A: Composer only, keep php 8.5
3. **Internal endpoints:** keep dual-port trust, or replace with a shared-secret
   header / IP allowlist (recommended)? This affects the Portal & audit callers. A: make a shared secret guard for these endpoints
4. **Column naming:** keep existing camelCase columns (recommended, zero client
   churn) vs. snake_case-ify to Laravel conventions (cleaner, but a breaking
   schema change and more mapping)? A: keep camel case
5. **Auth library:** `firebase/php-jwt` (simple) vs. `web-token/jwt-framework`
   (full JWKS support)? A: Full support. This will be later switching to using Keycloak instead of Cognito
6. **Legacy `/dev/migrate*` importers:** port or drop? (Recommend drop — they
   read retired databases.) A: drop
7. **`tokens` table:** keep for dev/switch parity, or drop now that Cognito JWT
   is the real auth path? A: keep. This will be later switching to using Keycloak instead of Cognito

---

## 7. Risks & gotchas

- **Materialized-path correctness** is the highest-risk area (insert-then-rewrite,
  prefix LIKE matching, chain split into parents/children, depth trimming). Test
  heavily before building endpoints on top.
- **MySQL-specific SQL**: `orderByRaw('CAST(SUBSTRING(membershipNumber, n) AS UNSIGNED) DESC')`
  in membership-number generation and `CURRENT_TIMESTAMP` comparisons must stay
  MySQL — do not develop/test on SQLite.
- **Enum columns** differ between MySQL and other drivers; keep them as DB enums
  to match production and the validation lists.
- **Promise semantics → synchronous PHP**: several flows rely on Bluebird
  `tap`/`join`/`any` and fire-and-forget (`audit`, `Token.removeExpired`). Map
  `any` to "succeed if any check passes"; keep audit failures non-fatal where the
  Node code ignored them, fatal where it rejected.
- **Find-or-create on auth** can create users as a side effect of a GET — preserve
  this behavior (Portal onboarding depends on it) but be deliberate about it.
- **Self vs. permission branches** in user update/assign and office assign have
  subtle special cases (own org unit, leaving a chapter, own assistant) — port
  branch-for-branch and cover each with a test.
- **`req.token.get('user')` is sometimes an object, sometimes an id** in the Node
  code (inconsistent). Normalize to the authenticated `User` model and audit each
  call site during the port.
- **Date handling**: `membershipExpiration` compared as a JS `Date` in `expired`;
  use Carbon and watch the `0000-00-00` sentinel some legacy rows may carry.
- **Serialization parity**: clients depend on exact field presence/absence
  (public vs private, dropped `parentPath`/`portalID`, escaped `defDoc`). Lock
  this down with response-shape assertions.

---

## 8. Summary

The port is a **faithful re-implementation**, not a redesign: same tables (same
camelCase columns), same endpoints under `/v1`, same office-based permission
semantics, same Cognito JWT auth, same JSON response shapes. Laravel idioms
(Eloquent, Form Requests, API Resources, middleware, services, Pest tests)
replace the Bookshelf/Express/Bluebird/validate.js machinery. The materialized-
path hierarchy and the permission service are the core logic to get exactly
right; the membership-number generator and the find-or-create auth flow are the
trickiest individual pieces. Begin only after confirming the §6 decisions.
