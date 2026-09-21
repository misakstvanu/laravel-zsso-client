<?php

namespace Misakstvanu\ZssoClient\Exceptions;

/**
 * A `prompt=none` authorization request could not finish without the user:
 * there is no session on zSSO, or zSSO would have to show them something (a
 * consent screen, the "link SkautIS" page of an app that requires one). The
 * silent login middleware turns this into "carry on as a guest", not into an
 * error; `$error` says which of `AuthorizationFailedException::INTERACTION_ERRORS`
 * it was.
 */
class LoginRequiredException extends AuthorizationFailedException
{
    //
}
