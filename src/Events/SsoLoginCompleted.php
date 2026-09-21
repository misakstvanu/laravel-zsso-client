<?php

namespace Misakstvanu\ZssoClient\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Misakstvanu\ZssoClient\SsoUser;

/**
 * A visitor came back from zSSO, was turned into this app's own user by its
 * `ProvisionsSsoUser` binding and is signed in.
 *
 * Everything an app's login needs beyond the account itself hangs off this
 * event: hydrating a SkautIS SOAP client from the session zSSO holds
 * (contract C-5), syncing whatever the app caches per login, warming a
 * cache. It is fired inside the callback request, so a listener that talks
 * to the network should keep itself short or queue.
 */
class SsoLoginCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly SsoUser $ssoUser,
    ) {}
}
