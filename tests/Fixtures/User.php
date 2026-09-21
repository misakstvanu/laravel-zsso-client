<?php

namespace Misakstvanu\ZssoClient\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A local user of the app under test, on Laravel's default `users` table.
 */
class User extends Authenticatable
{
    protected $table = 'users';

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password'];
}
