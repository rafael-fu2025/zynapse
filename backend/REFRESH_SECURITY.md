# Refresh-session security

## Absolute lifetime

A new authenticated family receives `session_expires_at = now (UTC) +
JWT_SESSION_MAX_AGE_SECONDS` (default **43200 seconds / 12 hours**). Every
rotation copies that exact deadline; changing configuration does not reset
existing families. `expires_at` is the lesser of the deadline and now plus
`JWT_REFRESH_TTL_SECONDS` (default 30 days). Both clocks reject at **<= now**.
Missing or malformed family deadlines fail closed. These are UTC instants,
not Manila calendar-day partitions.

Lifetime settings must be positive integer seconds, at most 315360000.
Invalid configuration raises an error rather than silently disabling expiry.
Only `issue(userId)` starts a new family; descendant minting is private to
rotation. No refresh-token plaintext is stored.

## Access-token signing and validation

All controller-issued session access tokens, including grace responses, call:

```php
$jwt->sign($userId, $epoch, ['session_exp' => $deadlineUnixTimestamp]);
```

The signer preserves this integer claim and enforces
`exp = min(now + configured access TTL, session_exp)`, rejecting an already
expired deadline. Verification also rejects `exp > session_exp`. Session access
tokens use `session_exp` without an additional `auth_time` claim. `expires_in`
is derived from the signed token's actual expiry, not an assumed 900-second
TTL. The feature regression test uses a 120-second family to exercise the cap
on login, rotation, and grace responses.

Issuer validation is strict: `iss` must exactly match `JWT_ISSUER`, defaulting
to the configured application `baseURL` when unset. `aud` must match
`JWT_AUDIENCE` (default **synapse-api**), either as a string or as an entry in
an audience array. Missing issuer or audience claims are rejected. The signer
owns `iss`, `aud`, `sub`, `iat`, `exp`, `jti`, and `epoch`; attempts to supply
these reserved fields through `extraClaims` throw rather than override them.

HMAC keys must meet the configured algorithm's minimum byte length: **32 for
HS256, 48 for HS384, 64 for HS512**. Unsupported algorithms or shorter keys
fail closed. Use suitably random key material; length alone does not establish
entropy. Tokens must also satisfy the signer's identity, timestamp, token-ID,
and epoch validation.

## Cross-tab grace: an intentional bounded replay allowance

`JWT_REFRESH_GRACE_SECONDS` defaults to **5**, accepts integers **0–5**, and can
be set to **0** to disable grace in production. It cannot enlarge the maximum.
A previously rotated token may obtain another access token only within this
window, while its immediate replacement is unrevoked and unexpired, in the
same user/family with the identical absolute deadline. The old token must
itself still be unexpired and before the family deadline. A future revocation
timestamp does not qualify. Grace neither mints a refresh row nor overwrites
the cookie; the racing rotation response supplies the replacement cookie.
A lost rotation response therefore cannot recover the plaintext via grace.

This is intentionally not bound to IP or User-Agent: neither reliably identifies
a browser, and adding these checks would not establish possession of the same
client. Someone holding a stolen old token can exploit this small grace window.
Outside grace, replay revokes the family and increments the user's token epoch.
Expired/missing-deadline tokens are simply rejected, without renewing anything.

## Transactions, logout, and cookies

Issuance, rotation, and revocation use transactions and lock the user before
refresh-token rows. This serializes descendant insertion against logout and
avoids opposing token/user lock order between these operations. Failed begin,
queries/inserts, transaction status, or commit cannot return credentials.
Errors propagate to the API error handler rather than returning success.

Logout revokes all live refresh rows and increments the access-token epoch in
one transaction. The epoch captured under the rotation lock travels with the
mint to JWT signing: a delayed refresh response must not read a newer epoch
and accidentally bless a pre-logout token. An in-flight response can still
arrive after logout, but its old epoch is rejected and its refresh row is
revoked. This is not a guarantee about arbitrary external writers that do not
follow the same locking protocol.

Logout expires the cookie with exactly the issuance name, path, Secure,
HttpOnly, and SameSite settings (and the same framework-default domain/prefix).
Issuance cookie expiry is the persisted capped refresh expiry. Both use absolute
DateTime values because CI4 interprets numeric `setCookie` expiry as relative
seconds. Secure defaults to true; SameSite defaults to Strict.

## Deployment / rollback

`2026-10-18-000020_RefreshSessionDeadline.php` adds a nullable DATETIME column
for schema compatibility and **revokes every live predeployment refresh row**.
Existing rows keep a NULL deadline and their original `expires_at`; no legacy
expiry is extended. New live rows must always have a deadline at the service
boundary. Already-revoked legacy rows also cannot qualify for grace.

Deploy with authentication traffic quiesced and old workers drained; run the
migration before enabling the updated authentication code. Clear any CI4
file-discovery cache so the migration is discovered. Users must log in again
when their legacy refresh is rejected. Old access JWTs lacking `aud`, or not
matching the configured issuer/audience, are also rejected and require re-login.
This migration does not increment user epochs: an already-issued JWT that meets
current validation may remain valid until its existing expiry unless independently
revoked. The migration cannot retroactively add audience or session claims to a
signed token.

Rollback drops the column but does not restore revoked sessions. No production
migration is performed by this implementation task; validation runs only through
the dedicated MySQL/MariaDB test bootstrap.

## Verification

Run from `backend/` using the existing test environment (never print credentials):

```sh
php vendor/bin/phpunit tests/unit/RefreshSessionPolicyTest.php tests/unit/RefreshTokenTransactionTest.php
php vendor/bin/phpunit -c phpunit.feature.xml tests/Feature/AuthenticationFlowTest.php
composer test
composer test:feature
composer scan:tenancy
```

The feature tests pin login/rotation/grace access bounds, immutable deadlines
across configuration changes, exact-boundary expiry for live and grace tokens,
NULL legacy deadlines, logout cookie attributes, descendant revocation, and
existing replay/password-change behavior. Dedicated transaction tests cover
failed begins, insert failure, poisoned transaction status, and commit failure.
The delayed-response test checks that a pre-logout rotation retains a revoked
epoch. The feature bootstrap enforces a
separate test schema; never run a live `spark migrate` as a test substitute.
