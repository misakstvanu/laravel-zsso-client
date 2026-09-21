<?php

namespace Misakstvanu\ZssoClient\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Misakstvanu\ZssoClient\SsoUser;

/**
 * The one thing a zSSO client app has to write itself: how a user zSSO
 * describes (contract C-3) becomes a local user of this app.
 *
 * Bind an implementation in a service provider:
 *
 *     $this->app->bind(ProvisionsSsoUser::class, ProvisionUser::class);
 *
 * The callback resolves it out of the container and hands it the `SsoUser`
 * the login exchanged; return the local model to sign in, or throw a
 * `ProvisioningDeniedException` to send the visitor back to `login_path`
 * with a message.
 */
interface ProvisionsSsoUser
{
    public function provision(SsoUser $user): Authenticatable;
}
