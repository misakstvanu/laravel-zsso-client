<?php

namespace Misakstvanu\ZssoClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a guest to zSSO once with `prompt=none`, so a visitor who is already
 * signed in there lands on the page they asked for without ever seeing a
 * login screen. When nobody is signed in there the server answers
 * `login_required`, the callback carries on as a guest and the app's own
 * guard decides what to show.
 *
 * The `zsso_silent` cookie is what makes this happen once instead of
 * forever: it is set before the redirect and never cleared, so the guest who
 * comes back unauthenticated passes straight through.
 *
 * Put it on the routes a visitor may land on with a bookmark (the SPA shell,
 * the dashboard), never on the whole app: every request it fires on costs a
 * round trip to the server.
 */
class EnsureSilentLoginAttempted
{
    /**
     * The cookie that says this visitor's one silent login has been tried.
     */
    public const COOKIE = 'zsso_silent';

    /**
     * How long that answer is remembered.
     */
    public const COOKIE_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldAttempt($request)) {
            return $next($request);
        }

        return redirect()
            ->to(route('zsso.redirect', ['redirect' => $request->fullUrl(), 'prompt' => 'none']))
            ->withCookie(cookie(self::COOKIE, '1', self::COOKIE_MINUTES));
    }

    /**
     * Only a guest opening a page in a browser, once, and only on a request
     * this app can send the visitor back to afterwards.
     */
    protected function shouldAttempt(Request $request): bool
    {
        return (bool) config('zsso.silent_login')
            && $request->isMethod('GET')
            && $request->acceptsHtml()
            && ! $request->expectsJson()
            && ! $request->hasCookie(self::COOKIE)
            && ! $this->isExcludedPath($request)
            && $request->user() === null;
    }

    /**
     * The app's own login page (where a guest is meant to end up), the
     * package's login routes (redirecting them to themselves would loop) and
     * the API (no browser, nothing to gain from a session).
     */
    protected function isExcludedPath(Request $request): bool
    {
        $patterns = ['auth/zsso/*', 'api/*'];

        $login = trim((string) config('zsso.login_path'), '/');

        if ($login !== '') {
            $patterns[] = $login;
            $patterns[] = $login.'/*';
        }

        return $request->is(...$patterns);
    }
}
