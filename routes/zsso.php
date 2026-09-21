<?php

use Illuminate\Support\Facades\Route;
use Misakstvanu\ZssoClient\Http\Controllers\ZssoLoginController;

/*
|--------------------------------------------------------------------------
| zSSO client routes
|--------------------------------------------------------------------------
|
| Loaded by `ZssoClientServiceProvider` inside the `web` middleware group.
| The callback sits on `zsso.redirect_path`, which is what the app registered
| on the server as its redirect URI.
|
*/

Route::get('/auth/zsso/redirect', [ZssoLoginController::class, 'redirect'])->name('zsso.redirect');

Route::get(config('zsso.redirect_path', '/auth/zsso/callback'), [ZssoLoginController::class, 'callback'])->name('zsso.callback');

Route::post('/auth/zsso/logout', [ZssoLoginController::class, 'logout'])->name('zsso.logout');
