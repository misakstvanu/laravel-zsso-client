# laravel-zsso-client

Turns a Laravel application into a [zSSO](https://zsso.stvan.cloud) client: single sign-on through the
shared login server, plus the app-to-app integration calls and webhooks the zSSO apps use to talk to each
other.

The goal is that an app needs nothing beyond `composer require misakstvanu/laravel-zsso-client`, four env
values and one class that turns an SSO user into a local one.

## Adding a new app, in order

Every step has its own section below; this is the order they have to happen in.

1. **Register the app on zSSO** — `php artisan zsso:app:register` on the server prints the client id and
   the secret once ([Configuration](#configuration)).
2. **Install the package** and publish its config and migrations ([Installation](#installation)).
3. **Fill the four env keys** — `ZSSO_SERVER_URL`, `ZSSO_CLIENT_ID`, `ZSSO_CLIENT_SECRET`, `ZSSO_APP_SLUG`
   ([Configuration](#configuration)).
4. **Bind `ProvisionsSsoUser`** — the one class the app writes itself ([Logging in](#logging-in)).
5. **Point the app's login at `zsso.redirect`**, and its logout at `zsso.logout`
   ([From a SPA](#from-a-spa)).
6. **Put `zsso.silent` on the routes a visitor may land on** ([Silent login](#silent-login)).
7. **Expose `/api/integration/v1` behind `zsso.integration`** if other apps read this app's data
   ([Receiving integration calls](#receiving-integration-calls)).
8. **Register webhook handlers** for what this app has to react to, and emit its own
   ([Webhooks](#webhooks)). Webhook delivery is queued, so the app needs `php artisan queue:work`.
9. **Run `php artisan zsso:doctor`** and get a ✔ on every line ([Configuration](#configuration)).

Steps 7 and 8 are optional: an app that only signs people in stops after step 6.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

The package is developed inside the zSSO repository (`sso/packages/laravel-zsso-client`) and consumed as a
local path repository until it is published. Clone it under the app's `packages/` and add it to the app's
`composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "packages/laravel-zsso-client", "options": { "symlink": true } }
    ],
    "require": {
        "misakstvanu/laravel-zsso-client": "@dev"
    }
}
```

The service provider is auto-discovered. Publish the config and run the migrations:

```bash
php artisan vendor:publish --tag=zsso-config
php artisan vendor:publish --tag=zsso-migrations
php artisan migrate
```

## Configuration

Register the app on the zSSO server first — `php artisan zsso:app:register {slug} {name} {base_url}
--redirect=https://<app>/auth/zsso/callback` — and copy the client id and secret it prints once into the
app's `.env`. Add `--requires-skautis` when only accounts linked to SkautIS may use the app: zSSO then
keeps an unlinked user on its own "link SkautIS" page instead of sending them back with a code. A
`prompt=none` silent login answers `interaction_required` instead, which the package treats like
`login_required`: the visitor carries on as a guest until they log in deliberately.

```dotenv
ZSSO_SERVER_URL=https://zsso.localhost
ZSSO_CLIENT_ID=
ZSSO_CLIENT_SECRET=
ZSSO_APP_SLUG=zirafa
```

`config/zsso.php` holds everything else:

| key | default | meaning |
|---|---|---|
| `server_url` | `ZSSO_SERVER_URL` | the zSSO server, no trailing slash; every endpoint is read from its discovery document |
| `client_id` / `client_secret` | env | this app's OAuth client |
| `app_slug` | `ZSSO_APP_SLUG` | this app's slug on the server, and the `app` field of webhook envelopes |
| `scopes` | `profile email` | scopes requested on login, space separated |
| `redirect_path` | `/auth/zsso/callback` | must match a redirect URI registered for `client_id` |
| `post_login_path` | `/` | where a login lands when no intended URL was stored |
| `post_logout_path` | `/login` | where the server returns the visitor after a logout |
| `login_path` | `/login` | this app's own login page |
| `silent_login` | `true` | try `prompt=none` once per visitor |
| `apps` | `[]` | base URL overrides per app slug, for local development |
| `webhook_path` | `/api/integration/v1/webhooks` | where this app receives webhooks |
| `cache_ttl` | `3600` | seconds to cache discovery, JWKS and the app list |

Check the result with:

```
$ php artisan zsso:doctor

 ✔  zsso.server_url
 ✔  zsso.client_id
 ✔  zsso.client_secret
 ✔  zsso.app_slug
 ✔  discovery document
 ✔  signing keys
 ✔  client-credentials token
```

The last three lines each ask the server itself — the cached answers are dropped first, so an outage
cannot hide behind them — and say what went wrong when they fail:

| line | what it proves | ✘ means |
|---|---|---|
| `discovery document` | `GET <server_url>/.well-known/openid-configuration` answers (contract C-1) | the server is unreachable or not a zSSO server; the login still works off the conventional paths |
| `signing keys` | the document's `jwks_uri` publishes a usable RSA key | incoming integration tokens (contract C-6) cannot be verified |
| `client-credentials token` | the token endpoint mints this app a token with the `integration` scope | `client_id`/`client_secret` are wrong, or the client is not allowed the `client_credentials` grant — no app-to-app call and no webhook can go out |

The command exits `1` as soon as one check fails, so CI or a deploy step can run it.

## Logging in

The package ships the whole redirect / callback / logout dance on three routes in the `web` group:

| route | name | what it does |
|---|---|---|
| `GET /auth/zsso/redirect` | `zsso.redirect` | off to zSSO; a same-origin `?redirect=` is stored as the intended URL, `?prompt=none` asks for a silent login |
| `GET <redirect_path>` | `zsso.callback` | exchanges the code, provisions, signs the user in and follows the intended URL (else `post_login_path`) |
| `POST /auth/zsso/logout` | `zsso.logout` | ends the local session and hands the visitor to the server's front-channel logout |

The one class the app writes itself says how an SSO user becomes a local one. Bind it in a service
provider:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Misakstvanu\ZssoClient\Contracts\ProvisionsSsoUser;
use Misakstvanu\ZssoClient\Exceptions\ProvisioningDeniedException;
use Misakstvanu\ZssoClient\SsoUser;

class ProvisionUser implements ProvisionsSsoUser
{
    public function provision(SsoUser $user): Authenticatable
    {
        if (! $user->emailVerified) {
            // Back to `login_path` with the message flashed as `error`.
            throw new ProvisioningDeniedException('Nejdřív si ověř e-mail v zSSO.');
        }

        return User::updateOrCreate(
            ['zsso_sub' => $user->sub],
            ['name' => $user->name, 'email' => $user->email],
        );
    }
}

// AppServiceProvider::register()
$this->app->bind(ProvisionsSsoUser::class, ProvisionUser::class);
```

The callback signs the returned user in with `Auth::login($user, remember: true)`, regenerates the
session and stores the tokens in `zsso_tokens` (one row per user, both tokens encrypted). An
`error=` callback ends on `login_path` the same way a denied provisioning does, except
`login_required` — the answer to a silent login — which simply carries on as a guest.

Once the user is signed in the callback fires `Misakstvanu\ZssoClient\Events\SsoLoginCompleted`
with the local user and the `SsoUser`, which is where an app hangs everything its login needs beyond
the account itself:

```php
use Misakstvanu\ZssoClient\Events\SsoLoginCompleted;
use Misakstvanu\ZssoClient\Facades\Zsso;

class HydrateSkautisSession
{
    public function handle(SsoLoginCompleted $event): void
    {
        if ($event->ssoUser->skautis === null) {
            return;
        }

        Zsso::skautis($event->user)->session()?->applyTo(app(\Skautis\Skautis::class));
    }
}
```

The event is fired inside the callback request, after the session was regenerated, so a listener that
takes long should queue itself.

`POST /auth/zsso/logout` deletes the token row, ends the local session and answers
`{"redirect": "<server>/logout?post_logout_redirect_uri=…&client_id=…"}` for a SPA to follow (a
non-JSON request is redirected there directly). The server ends the session it holds and tells every
other app.

### From a SPA

Only three URLs matter to a frontend, and none of them is an XHR the SPA has to interpret: two are
full-page navigations, one is a `POST` whose answer says where to navigate.

| what the visitor does | what the SPA does |
|---|---|
| clicks "Sign in with zSSO" | `window.location.href = '/auth/zsso/redirect?redirect=' + encodeURIComponent(currentPath)` |
| comes back from zSSO | nothing — `zsso.callback` signs them in server-side and redirects to `?redirect=`, else `post_login_path` |
| clicks "Sign out" | `POST /auth/zsso/logout` with the CSRF token, then `window.location.href = response.redirect` |

```ts
// resources/js/auth.ts
export function login(next = window.location.pathname) {
    window.location.href = `/auth/zsso/redirect?redirect=${encodeURIComponent(next)}`
}

export async function logout() {
    const response = await fetch('/auth/zsso/logout', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')!.content,
        },
    })

    // Off to the server's front-channel logout, which ends the session every app shares.
    window.location.href = (await response.json()).redirect
}
```

Both navigations have to be real navigations: the login is an OAuth redirect chain to another origin, and
the logout ends on the zSSO server, which returns the visitor to `post_logout_path`. `?redirect=` is only
followed when it is same-origin, so an open redirect cannot be smuggled through it.

## Silent login

A visitor already signed in on zSSO should not see this app's login page at all. The `zsso.silent`
middleware arranges that: the first time a guest opens a page it sets the `zsso_silent` cookie (5
minutes) and sends them to `GET /auth/zsso/redirect?redirect=<current url>&prompt=none`. If a session
exists on the server they come back signed in, on the page they asked for. If none exists the server
answers `login_required`, the callback carries on as a guest and lands them on the same URL — where this
app's own `auth` middleware shows the login page as usual. The cookie is never cleared, so that happens
once per visitor, not on every request.

Put it on the routes a visitor may land on with a bookmark — the SPA shell, the dashboard — and **not**
globally: every request it fires on costs a round trip to the server, and a guest hitting a page inside
`auth` is redirected to the login page anyway.

```php
// routes/web.php
Route::middleware(['zsso.silent'])->get('/{any?}', SpaController::class)->where('any', '.*');
```

The middleware stays out of the way of requests it cannot help: it only fires on a guest `GET` that
accepts HTML, never under `login_path`, `/auth/zsso/*` or `/api/*`, and never when the cookie is already
there. Set `silent_login` to `false` in `config/zsso.php` to turn it off everywhere it is registered.

## Acting for a user

```php
use Misakstvanu\ZssoClient\Facades\Zsso;

$token = Zsso::tokenFor($user); // a live access token
```

`tokenFor()` returns the stored access token while it is live and spends the refresh token on a new one
when it is not (a minute before `expires_at` already counts as spent). When this app holds no token for
the user, or zSSO refuses the refresh token, it throws
`Misakstvanu\ZssoClient\Exceptions\SessionLostException` — the user has to sign in again.

## The user's SkautIS session

zSSO logs the user in to SkautIS, holds the SOAP token and keeps it alive. An app reads that login
instead of running one of its own (contract C-5):

```php
use Misakstvanu\ZssoClient\Facades\Zsso;

$session = Zsso::skautis($user)->session();   // ?SkautisSessionData
$session?->applyTo($skautis);                 // hydrate a \Skautis\Skautis client

Zsso::skautis($user)->refresh();              // SkautIS keep-alive
Zsso::skautis($user)->switchRole(679);        // activate another role
Zsso::skautis($user)->logout();               // end the SkautIS login
```

`SkautisSessionData` carries `loginId`, `roleId`, `unitId`, `logoutAt` and `userId` (the SkautIS user id,
not the zSSO `sub`), plus `isActive()`. `applyTo()` needs `skautis/skautis`, which is a suggested — not a
required — dependency: only an app that talks to SkautIS itself installs it.

Every call goes out with the user's own access token (`tokenFor()`, refreshed when spent), so the app has
to have been granted the `skautis:session` scope. A user with no SkautIS session is not an error:
`session()`, `refresh()` and `switchRole()` answer `null` for them and `logout()` answers `false`. SkautIS
not answering zSSO raises `Exceptions\SkautisUnavailableException` (worth retrying); any other error
status raises its parent, `Exceptions\SkautisRequestException`, with the server's answer on `$response`.

## Calling another app

```php
use Misakstvanu\ZssoClient\Facades\Zsso;

$events = Zsso::integration('zirafa')->get('events')->json('data');

Zsso::integration('zirafa')
    ->actingAs($user->zsso_sub)                 // X-Zsso-Acting-User
    ->post('attendees', ['event_id' => 42]);
```

`Zsso::integration('<slug>')` hands back Laravel's HTTP client with everything an app-to-app call
(contract C-6) needs already on it: the receiving app's `integration_url` as the base URL, this app's
client-credentials bearer (scope `integration`) and `Accept: application/json`. Every other
`PendingRequest` method — `withHeaders()`, `timeout()`, `withQueryParameters()`, … — works and keeps the
chain going.

The token is never the caller's business. It is minted once and cached until a minute before it expires,
so a hundred calls share one; a receiver that answers `401` (a revoked token, a rotated signing key) makes
the client drop the cached one, mint a fresh one and send the same call again, once. `actingAs($sub)`
names the zSSO user the call is made for — the receiver filters what it returns by their rights.

Any other error status raises `Misakstvanu\ZssoClient\Exceptions\IntegrationRequestException`, whose
`$response` is the receiver's answer (`$exception->response?->json('message')` is what it said) and whose
`$app` is the slug that was called. The same exception says that zSSO lists no such app, or would not mint
a token; a receiver that cannot be reached at all raises Laravel's own
`Illuminate\Http\Client\ConnectionException`.

Where an app lives is read from the server's app list (contract C-4), cached for `cache_ttl` seconds — a
slug that is not in the cached list is looked up once on a freshly fetched one, so an app registered a
minute ago is reachable. For local development, `zsso.apps` overrides that per slug:

```php
// config/zsso.php
'apps' => [
    'zirafa' => 'https://zirafa.localhost/api/integration/v1',
],
```

## Receiving integration calls

The other zSSO apps reach this app's `/api/integration/v1/*` routes with a client-credentials token
(contract C-6). `zsso.integration` verifies it and names the caller:

```php
// routes/api.php
Route::middleware('zsso.integration')->prefix('integration/v1')->group(function () {
    Route::get('events', [EventController::class, 'index']);

    // 403 unless the caller sends X-Zsso-Acting-User
    Route::middleware('zsso.integration:acting-user')->get('my-events', [EventController::class, 'mine']);
});
```

Nothing in that path calls zSSO per request: the token is an RS256 JWT verified against the server's
published keys (the `jwks_uri` of contract C-1) and both those keys and the app list are cached for
`cache_ttl` seconds. A token naming a `kid` the cached set does not hold — a rotated signing key — and a
client id the cached app list does not hold — a freshly registered app — each re-fetch once. The
middleware answers `401 {"message": …}` for a missing, unreadable, unsigned, expired or `integration`-less
token and for one issued to an app zSSO does not list.

Inside the route, the caller is on the request:

```php
use Misakstvanu\ZssoClient\IntegrationContext;

$context = IntegrationContext::current();

$context->callerSlug;    // 'zirafa'
$context->actingUserSub; // the zSSO uuid from X-Zsso-Acting-User, or null
$context->actsForUser(); // whether the caller named one
```

`callerSlug` is the caller's app slug, resolved from the token's `aud` (its OAuth client id) through the
server's app list (contract C-4). When `actingUserSub` is set, the endpoint MUST filter what it returns by
that user's rights; `zsso.integration:acting-user` is for endpoints that make no sense without one.

## Webhooks

Apps tell each other what happened with webhooks (contract C-7). Every envelope looks the same:

```json
{
    "id": "0192a3f4-…",
    "type": "zirafa.attendee.upserted",
    "app": "zirafa",
    "occurred_at": "2026-09-08T12:00:00+02:00",
    "data": { "attendee_id": 42 }
}
```

### Emitting

```php
use Misakstvanu\ZssoClient\Facades\Zsso;

Zsso::webhook('zirafa.attendee.upserted', ['attendee_id' => $attendee->id])->to('zskauting');

// or to every app that receives webhooks, this one excluded
Zsso::webhook('zirafa.attendee.upserted', ['attendee_id' => $attendee->id])->toAll();
```

Naming the receivers queues one `DeliverWebhook` job per receiver, all with the same envelope id, so a
receiver that is down only holds up its own delivery. The job posts the envelope to that app's
`webhook_url` (contract C-4, overridable per slug in `zsso.apps`) with this app's client-credentials
bearer — the same auth as any other app-to-app call.

Anything but a 2xx is retried on the contract's backoff, `1, 5, 30, 120, 600` seconds. When the last
attempt is spent the job logs an error and fires `WebhookDeadLettered`, which is where an app hooks its
own alerting:

```php
use Misakstvanu\ZssoClient\Events\WebhookDeadLettered;

Event::listen(function (WebhookDeadLettered $event) {
    report(new WebhookLost($event->app, $event->envelope->type, $event->envelope->id));
});
```

The jobs are queued, so the app needs a worker (`php artisan queue:work`).

### Receiving

The package answers `POST` on `zsso.webhook_path` (`/api/integration/v1/webhooks` by default) behind
`zsso.integration`, so a sender is an app zSSO knows before anything else happens. Register the handlers
from a service provider's `boot()`:

```php
use Misakstvanu\ZssoClient\Facades\Zsso;
use Misakstvanu\ZssoClient\Webhooks\WebhookEnvelope;

Zsso::onWebhook('zirafa.attendee.upserted', UpsertAttendee::class);

Zsso::onWebhook('zsso.session.logout', function (WebhookEnvelope $envelope) {
    // $envelope->get('sub'), $envelope->app, $envelope->occurredAt
});
```

`zsso.session.logout` is the one webhook every app gets without another app sending it: zSSO emits it
itself, from the slug `zsso`, whenever a user signs out — through the SPA or through the front-channel
`end_session_endpoint` — with `data: { sub }` naming them. Handle it by ending that user's local session,
and the browser they left behind stops being signed in here too.

A handler class is resolved from the container and called through its `handle()` method (or `__invoke()`)
with the envelope as its only argument, inline with the request; a handler that may take its time
dispatches its own queued job. An app that wants every webhook regardless of type listens for
`WebhookReceived` instead.

What the sender is told:

| answer | when |
|---|---|
| `200` | the envelope was stored and a registered handler ran |
| `200` | the envelope id was already received — the duplicate does nothing |
| `202` | the envelope was stored, but no handler is registered for its type (logged at `debug`) |
| `403` | the envelope's `app` is not the app the bearer token belongs to |
| `422` | the envelope is not the C-7 shape |

De-duplication is the `zsso_webhook_receipts` table (`php artisan vendor:publish --tag=zsso-migrations`),
keyed by the envelope id. A handler that throws gives the id back before the `500` reaches the sender, so
the retry that follows is processed rather than answered as a duplicate.

## The Socialite driver

The routes above are built on a Socialite driver the package registers, which an app can also drive itself:

```php
use Laravel\Socialite\Facades\Socialite;

return Socialite::driver('zsso')->redirect();

$user = Socialite::driver('zsso')->user(); // Misakstvanu\ZssoClient\SsoUser
```

The driver reads its endpoints from the server's discovery document (contract C-1), cached for
`cache_ttl` seconds; when the server cannot be reached it falls back to the conventional paths under
`server_url`, so a discovery outage does not take the login with it. Every request uses PKCE (S256) and
the OAuth `state`, both kept in the session, and asks for the configured `scopes`.

`->with(['prompt' => 'none'])` asks the server not to prompt: if nobody is signed in there, the callback
raises `Misakstvanu\ZssoClient\Exceptions\LoginRequiredException` instead of returning a user. Any other
`error=` in the callback raises its parent, `AuthorizationFailedException`, whose `$error` property
carries the OAuth code (`access_denied` and friends).

`SsoUser` is a readonly DTO of the userinfo shape (contract C-3) plus the tokens the login exchanged:

| property | notes |
|---|---|
| `sub` | the user's uuid on zSSO, the only value that is always there |
| `email`, `emailVerified` | with the `email` scope |
| `name`, `nickname`, `birthday`, `street`, `city`, `zip`, `avatarUrl` | with the `profile` scope; `birthday` is a `Y-m-d` string. `name` and `nickname` are the profile the user edits on zSSO |
| `skautisName`, `skautisNickname` | with the `profile` scope; the name and nickname SkautIS holds, refreshed on every SkautIS login and `null` until the account is linked. Use these when the app wants the SkautIS identity rather than the user's own |
| `skautisSex`, `skautisBirthday` | with the `profile` scope; `muz` / `zena` / `null` and a `Y-m-d` string, both straight from SkautIS and refreshed on every SkautIS login. Unlike the pair above they have no user-edited counterpart — zSSO's own profile form refuses to set a birthday once SkautIS supplies one — so these are the ones to gate an age on or to write grammatically gendered copy with. `null` until the account is linked, and whenever SkautIS holds nothing |
| `skautis` | a `SkautisInfo` (`userId`, `roleId`, `unitId`, `logoutAt`, `roles`, `loginId`) with the `skautis` scope, `null` without a live SkautIS session |
| `skautisUnit`, `skautisStredisko` | with the `skautis` scope; a `SkautisUnit` (`id`, `name`, `registrationNumber`, `type`) and a `SkautisStredisko` (`id`, `name`, `registrationNumber`) — the unit the last SkautIS login happened in and its středisko, kept by zSSO on the user and refreshed on every SkautIS login, so unlike `skautis` they need no live session. Match your own units against the ids or registration numbers. `null` until the account has been through a SkautIS login (and `skautisStredisko` also when zSSO could not find one); inside them `name`/`registrationNumber`/`type` are `null` when the SkautIS lookup failed, `id` is always there |
| `raw` | the untouched userinfo response |
| `token`, `refreshToken`, `expiresIn`, `scopes` | from the token response |

## Contracts

The shapes this package speaks are the numbered contracts in the
[zSSO PRD](../../tasks/prd-zsso-server.md#contracts) (`sso/tasks/prd-zsso-server.md`). They are shared by
every app: changing one means changing all of them, so treat this table as the map between a contract and
the part of the package that speaks it.

| contract | what it is | where the package uses it |
|---|---|---|
| C-1 | discovery — `GET /.well-known/openid-configuration` | `Discovery`; every endpoint URL the package calls comes from it, with the conventional path as the fallback |
| C-2 | the scope registry | `zsso.scopes` on login; `integration` is the client-credentials scope of every app-to-app call |
| C-3 | userinfo — `GET /api/userinfo` | `SsoUser`, what `Socialite::driver('zsso')->user()` returns and what `ProvisionsSsoUser` is handed |
| C-4 | the app list — `GET /api/apps` | `AppDirectory`: a slug's `integration_url` and `webhook_url`, and the `aud` → slug lookup a receiver names its caller with |
| C-5 | the SkautIS session endpoints | `Zsso::skautis($user)` and `SkautisSessionData` |
| C-6 | app-to-app calls | `Zsso::integration($slug)` outbound, `zsso.integration` + `IntegrationContext` inbound |
| C-7 | the webhook envelope | `Zsso::webhook()` / `WebhookEnvelope` outbound, `Zsso::onWebhook()` inbound |
| C-8 | the user export/import format | not the package's business — it is what each app's `<app>:export-users` writes for the server's `zsso:import-users` |

## Development

```bash
composer install
vendor/bin/pest
```

The tests run under [Orchestra Testbench](https://packages.tools/testbench), so no application and no
running zSSO server are needed.
