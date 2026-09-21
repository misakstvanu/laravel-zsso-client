<?php

use Illuminate\Support\Facades\Route;
use Misakstvanu\ZssoClient\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| zSSO webhook receiver
|--------------------------------------------------------------------------
|
| Loaded by `ZssoClientServiceProvider` behind `zsso.integration` and nothing
| else: a webhook (contract C-7) carries a bearer token, never a session or a
| CSRF token, so this route stays out of the `web` group.
|
*/

Route::post(config('zsso.webhook_path', '/api/integration/v1/webhooks'), WebhookController::class)->name('zsso.webhooks');
