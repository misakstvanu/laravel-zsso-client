<?php

namespace Misakstvanu\ZssoClient;

/**
 * The `stredisko` block of a userinfo response (contract C-3): the středisko
 * the user's SkautIS unit belongs to (the unit itself when it is one), found
 * by zSSO on the last SkautIS login. Like `SkautisUnit` it needs no live
 * SkautIS session, only the `skautis` scope.
 */
final readonly class SkautisStredisko
{
    public function __construct(
        public int $id,
        public ?string $name = null,
        public ?string $registrationNumber = null,
    ) {}

    /**
     * @param  array<string, mixed>  $stredisko
     */
    public static function fromArray(array $stredisko): self
    {
        return new self(
            id: (int) ($stredisko['id'] ?? 0),
            name: isset($stredisko['name']) ? (string) $stredisko['name'] : null,
            registrationNumber: isset($stredisko['registration_number']) ? (string) $stredisko['registration_number'] : null,
        );
    }
}
