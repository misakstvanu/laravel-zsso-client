<?php

namespace Misakstvanu\ZssoClient\Exceptions;

use RuntimeException;

/**
 * The app refused to provision a zSSO user (not a member, blocked, an
 * incomplete profile, …). The message is shown to the visitor on the app's
 * own login page, so write it for them.
 */
class ProvisioningDeniedException extends RuntimeException
{
    //
}
