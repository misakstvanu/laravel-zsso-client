<?php

/*
|--------------------------------------------------------------------------
| zSSO client configuration
|--------------------------------------------------------------------------
|
| Every value an app needs to become a zSSO client. The three values an app
| really has to set are `server_url`, `client_id` and `client_secret`; they
| come from `php artisan zsso:app:register` on the zSSO server. The paths
| below describe this app, not the server.
|
*/

return [

    /*
     * The zSSO server, without a trailing slash. Everything else (authorize,
     * token, userinfo, JWKS, logout, apps) is read from its discovery
     * document, `<server_url>/.well-known/openid-configuration` (contract C-1).
     */
    'server_url' => env('ZSSO_SERVER_URL'),

    /*
     * OAuth client credentials of this app, printed once by
     * `zsso:app:register` on the server.
     */
    'client_id' => env('ZSSO_CLIENT_ID'),
    'client_secret' => env('ZSSO_CLIENT_SECRET'),

    /*
     * This app's slug on the server, the `app` field of a webhook envelope
     * (contract C-7) and the key other apps address it by.
     */
    'app_slug' => env('ZSSO_APP_SLUG'),

    /*
     * Scopes requested on login, space separated (contract C-2).
     */
    'scopes' => env('ZSSO_SCOPES', 'profile email'),

    /*
     * Where the server sends the visitor back. Must match one of the redirect
     * URIs registered for `client_id`.
     */
    'redirect_path' => '/auth/zsso/callback',

    /*
     * Where a visitor lands after a successful login, unless an intended URL
     * was stored.
     */
    'post_login_path' => '/',

    /*
     * Where the server sends the visitor after a front-channel logout.
     */
    'post_logout_path' => '/login',

    /*
     * This app's own login page: where a failed or denied login ends up, and
     * the path the silent login middleware never fires on.
     */
    'login_path' => '/login',

    /*
     * Try `prompt=none` once per visitor so a session that already exists on
     * zSSO signs them in without a prompt.
     */
    'silent_login' => true,

    /*
     * Base URL overrides per app slug, for talking to another app without
     * asking the server (`GET /api/apps`, contract C-4). Only for local
     * development and tests; empty means "ask the server".
     */
    'apps' => [],

    /*
     * Where this app receives webhooks (contract C-7). Registered on the
     * server as the app's `webhook_url`.
     */
    'webhook_path' => '/api/integration/v1/webhooks',

    /*
     * Seconds to cache the discovery document, the JWKS and the app list.
     */
    'cache_ttl' => 3600,

];
