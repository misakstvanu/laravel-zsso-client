<?php

namespace Misakstvanu\ZssoClient\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Misakstvanu\ZssoClient\Contracts\ProvisionsSsoUser;
use Misakstvanu\ZssoClient\SsoUser;

/**
 * What an app's own binding looks like: match on `sub`, then on e-mail,
 * create otherwise.
 */
class ProvisionFixtureUser implements ProvisionsSsoUser
{
    public function provision(SsoUser $user): Authenticatable
    {
        return User::query()->firstOrCreate(
            ['email' => (string) $user->email],
            ['name' => (string) $user->name, 'password' => 'x'],
        );
    }
}
