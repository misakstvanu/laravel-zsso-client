<?php

namespace Misakstvanu\ZssoClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Misakstvanu\ZssoClient\Contracts\ProvisionsSsoUser;
use Misakstvanu\ZssoClient\Discovery;
use Misakstvanu\ZssoClient\Events\SsoLoginCompleted;
use Misakstvanu\ZssoClient\Exceptions\AuthorizationFailedException;
use Misakstvanu\ZssoClient\Exceptions\LoginRequiredException;
use Misakstvanu\ZssoClient\Exceptions\ProvisioningDeniedException;
use Misakstvanu\ZssoClient\Models\ZssoToken;
use Misakstvanu\ZssoClient\Socialite\ZssoProvider;
use Misakstvanu\ZssoClient\SsoUser;

/**
 * The whole login dance an app gets for free: send the visitor to zSSO, take
 * them back, hand the `SsoUser` to the app's `ProvisionsSsoUser` binding and
 * sign the returned user in.
 */
class ZssoLoginController extends Controller
{
    /**
     * The session key a failed login flashes its message under.
     */
    public const ERROR_KEY = 'error';

    /**
     * `GET /auth/zsso/redirect`: off to zSSO's authorize endpoint. A safe,
     * same-origin `?redirect=` is remembered as the intended URL, and
     * `?prompt=none` asks zSSO not to show anything (silent login).
     */
    public function redirect(Request $request): RedirectResponse
    {
        $intended = $this->safeIntended($request);

        if ($intended !== null) {
            $request->session()->put('url.intended', $intended);
        }

        /** @var ZssoProvider $driver */
        $driver = Socialite::driver('zsso');

        if ($request->query('prompt') === 'none') {
            $driver->with(['prompt' => 'none']);
        }

        return $driver->redirect();
    }

    /**
     * `GET /auth/zsso/callback`: exchange the code, provision and sign in.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            /** @var SsoUser $ssoUser */
            $ssoUser = Socialite::driver('zsso')->user();
        } catch (LoginRequiredException) {
            // A silent login found no session on zSSO: carry on as a guest,
            // the app's own guard decides what the visitor sees next.
            return redirect()->intended($this->path('post_login_path'));
        } catch (AuthorizationFailedException $exception) {
            return $this->denied($exception->getMessage());
        }

        try {
            $user = app(ProvisionsSsoUser::class)->provision($ssoUser);
        } catch (ProvisioningDeniedException $exception) {
            return $this->denied($exception->getMessage());
        }

        ZssoToken::storeFor($user, $ssoUser);

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        SsoLoginCompleted::dispatch($user, $ssoUser);

        return redirect()->intended($this->path('post_login_path'));
    }

    /**
     * `POST /auth/zsso/logout`: end the local session and hand the visitor
     * on to zSSO's front-channel logout, which ends the session there and on
     * every other app.
     */
    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            ZssoToken::forUser($user)?->delete();
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $redirect = $this->serverLogoutUrl();

        return $request->expectsJson()
            ? response()->json(['redirect' => $redirect])
            : redirect()->away($redirect);
    }

    /**
     * zSSO's `end_session_endpoint`, told where to send the visitor back and
     * which client is signing them out.
     */
    protected function serverLogoutUrl(): string
    {
        $query = http_build_query([
            'post_logout_redirect_uri' => url($this->path('post_logout_path')),
            'client_id' => (string) config('zsso.client_id'),
        ]);

        return app(Discovery::class)->url('end_session_endpoint').'?'.$query;
    }

    /**
     * Back to the app's own login page with the reason flashed.
     */
    protected function denied(string $message): RedirectResponse
    {
        return redirect()->to($this->path('login_path'))->with(self::ERROR_KEY, $message);
    }

    /**
     * A `?redirect=` this app may send the visitor to afterwards: a path or
     * an absolute URL on this very host, never somewhere else.
     */
    protected function safeIntended(Request $request): ?string
    {
        $target = $request->query('redirect');

        if (! is_string($target) || trim($target) === '') {
            return null;
        }

        $target = trim($target);

        // `//host` and `/\host` are off-origin to a browser, whatever the
        // leading slash suggests.
        if (str_starts_with($target, '//') || str_starts_with($target, '/\\')) {
            return null;
        }

        $host = parse_url($target, PHP_URL_HOST);

        if ($host === null || $host === false) {
            return str_starts_with($target, '/') ? $target : null;
        }

        $scheme = parse_url($target, PHP_URL_SCHEME);

        return $host === $request->getHost() && in_array($scheme, ['http', 'https'], true)
            ? $target
            : null;
    }

    protected function path(string $key): string
    {
        return (string) config("zsso.{$key}");
    }
}
