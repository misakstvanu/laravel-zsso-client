<?php

namespace Misakstvanu\ZssoClient\Exceptions;

use RuntimeException;

/**
 * This app can no longer act for the user against zSSO: it holds no token
 * for them, or the refresh token was refused. The user has to sign in again.
 */
class SessionLostException extends RuntimeException
{
    //
}
