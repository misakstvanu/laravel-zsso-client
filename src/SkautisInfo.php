<?php

namespace Misakstvanu\ZssoClient;

use DateTimeImmutable;
use Illuminate\Support\Carbon;

/**
 * The `skautis` block of a userinfo response (contract C-3). It is present
 * only while the user has a live SkautIS session on zSSO, and `loginId` only
 * when the token also carries the `skautis:session` scope.
 */
final readonly class SkautisInfo
{
    /**
     * @param  array<int, array<string, mixed>>  $roles
     */
    public function __construct(
        public int $userId,
        public ?int $roleId = null,
        public ?int $unitId = null,
        public ?DateTimeImmutable $logoutAt = null,
        public array $roles = [],
        public ?string $loginId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $skautis
     */
    public static function fromArray(array $skautis): self
    {
        $logoutAt = $skautis['logout_at'] ?? null;

        return new self(
            userId: (int) ($skautis['user_id'] ?? 0),
            roleId: isset($skautis['role_id']) ? (int) $skautis['role_id'] : null,
            unitId: isset($skautis['unit_id']) ? (int) $skautis['unit_id'] : null,
            logoutAt: is_string($logoutAt) && $logoutAt !== '' ? Carbon::parse($logoutAt)->toImmutable() : null,
            roles: array_values(array_filter((array) ($skautis['roles'] ?? []), 'is_array')),
            loginId: isset($skautis['login_id']) ? (string) $skautis['login_id'] : null,
        );
    }
}
