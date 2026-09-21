<?php

namespace Misakstvanu\ZssoClient;

/**
 * The `unit` block of a userinfo response (contract C-3): the unit the user's
 * last SkautIS login happened in. zSSO keeps it on the user, so it is there
 * with the `skautis` scope whether or not a SkautIS session is live.
 *
 * `id` is the login's own `skautIS_IDUnit`; the other three come from
 * `UnitDetail` and are `null` when that lookup failed on zSSO.
 */
final readonly class SkautisUnit
{
    /**
     * @param  string|null  $type  SkautIS's `ID_UnitType` (`oddil`, `stredisko`, …)
     */
    public function __construct(
        public int $id,
        public ?string $name = null,
        public ?string $registrationNumber = null,
        public ?string $type = null,
    ) {}

    /**
     * @param  array<string, mixed>  $unit
     */
    public static function fromArray(array $unit): self
    {
        return new self(
            id: (int) ($unit['id'] ?? 0),
            name: isset($unit['name']) ? (string) $unit['name'] : null,
            registrationNumber: isset($unit['registration_number']) ? (string) $unit['registration_number'] : null,
            type: isset($unit['type']) ? (string) $unit['type'] : null,
        );
    }
}
