<?php

namespace Misakstvanu\ZssoClient;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Skautis\Skautis;

/**
 * A user's SkautIS login as zSSO holds it (contract C-5):
 * `{ login_id, role_id, unit_id, logout_at, user_id }`.
 *
 * `loginId` is the SOAP token itself, so it only ever reaches a token that
 * carries the `skautis:session` scope, and `userId` is the SkautIS user id —
 * not the zSSO `sub`. `applyTo()` hands the whole thing to a SOAP client, so
 * an app talks to SkautIS as the user without ever logging them in itself.
 */
final readonly class SkautisSessionData
{
    public function __construct(
        public string $loginId,
        public ?int $roleId = null,
        public ?int $unitId = null,
        public ?DateTimeImmutable $logoutAt = null,
        public int $userId = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public static function fromArray(array $session): self
    {
        $logoutAt = $session['logout_at'] ?? null;

        return new self(
            loginId: (string) ($session['login_id'] ?? ''),
            roleId: isset($session['role_id']) ? (int) $session['role_id'] : null,
            unitId: isset($session['unit_id']) ? (int) $session['unit_id'] : null,
            logoutAt: is_string($logoutAt) && $logoutAt !== '' ? Carbon::parse($logoutAt)->toImmutable() : null,
            userId: (int) ($session['user_id'] ?? 0),
        );
    }

    /**
     * Hydrate a `skautis/skautis` client with the login, the way the user's
     * own browser session would: every SOAP call the client makes afterwards
     * is made as that user, in the role zSSO holds for them.
     *
     * The package is a soft dependency — it is only needed by apps that talk
     * to SkautIS themselves, which is why nothing but this method mentions
     * it. A `Skautis` instance cannot exist without the class, so the type
     * hint is the whole guard: PHP resolves it when the method is called, not
     * when the class is loaded.
     */
    public function applyTo(Skautis $skautis): void
    {
        $skautis->getUser()->setLoginData(
            $this->loginId,
            $this->roleId,
            $this->unitId,
            $this->logoutAt === null ? null : \DateTime::createFromInterface($this->logoutAt),
        );
    }

    /**
     * Whether SkautIS still holds the login: after `logoutAt` the token is
     * dead and the user has to sign in to SkautIS again.
     */
    public function isActive(): bool
    {
        return $this->logoutAt !== null && $this->logoutAt > new DateTimeImmutable;
    }
}
