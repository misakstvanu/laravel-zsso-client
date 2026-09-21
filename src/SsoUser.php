<?php

namespace Misakstvanu\ZssoClient;

use Laravel\Socialite\Contracts\User as SocialiteUser;
use RuntimeException;

/**
 * A zSSO user in the shape the userinfo endpoint describes (contract C-3),
 * together with the tokens the login exchanged. The server omits every key
 * the granted scopes do not cover, so only `sub` is always there.
 *
 * `name` / `nickname` are the profile the user built on zSSO;
 * `skautisName` / `skautisNickname` are what SkautIS holds (null until the
 * account is linked). An app picks the pair it wants to show.
 *
 * `skautisSex` (`muz` / `zena`) and `skautisBirthday` have no user-edited
 * counterpart: zSSO only ever copies them from SkautIS, which is what makes
 * them safe to decline an age on or to write grammatically gendered copy with.
 * `birthday` next to them is the one the user typed themselves.
 *
 * `skautisUnit` / `skautisStredisko` are the unit the last SkautIS login
 * happened in and its středisko (C-3 `unit` / `stredisko`), kept by zSSO on
 * the user: they come with the `skautis` scope but, unlike `skautis`, need no
 * live SkautIS session — the ids and registration numbers are what an app
 * matches its own units against.
 */
final readonly class SsoUser implements SocialiteUser
{
    /**
     * @param  string|null  $skautisSex  `muz`, `zena`, or null when SkautIS says nothing
     * @param  string|null  $skautisBirthday  `Y-m-d`, as the server sends it
     * @param  string|null  $birthday  `Y-m-d`, as the server sends it
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public string $sub,
        public ?string $email = null,
        public bool $emailVerified = false,
        public ?string $name = null,
        public ?string $nickname = null,
        public ?string $skautisName = null,
        public ?string $skautisNickname = null,
        public ?string $skautisSex = null,
        public ?string $skautisBirthday = null,
        public ?string $birthday = null,
        public ?string $street = null,
        public ?string $city = null,
        public ?string $zip = null,
        public ?string $avatarUrl = null,
        public ?SkautisInfo $skautis = null,
        public ?SkautisUnit $skautisUnit = null,
        public ?SkautisStredisko $skautisStredisko = null,
        public array $raw = [],
        public ?string $token = null,
        public ?string $refreshToken = null,
        public ?int $expiresIn = null,
        public array $scopes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $userinfo
     * @param  array<int, string>  $scopes
     */
    public static function fromUserinfo(
        array $userinfo,
        ?string $token = null,
        ?string $refreshToken = null,
        ?int $expiresIn = null,
        array $scopes = [],
    ): self {
        $sub = $userinfo['sub'] ?? null;

        if (! is_string($sub) || $sub === '') {
            throw new RuntimeException('The zSSO userinfo response carries no "sub"; the token is missing the "profile" scope.');
        }

        $skautis = $userinfo['skautis'] ?? null;
        $unit = $userinfo['unit'] ?? null;
        $stredisko = $userinfo['stredisko'] ?? null;

        return new self(
            sub: $sub,
            email: self::text($userinfo, 'email'),
            emailVerified: (bool) ($userinfo['email_verified'] ?? false),
            name: self::text($userinfo, 'name'),
            nickname: self::text($userinfo, 'nickname'),
            skautisName: self::text($userinfo, 'skautis_name'),
            skautisNickname: self::text($userinfo, 'skautis_nickname'),
            skautisSex: self::text($userinfo, 'skautis_sex'),
            skautisBirthday: self::text($userinfo, 'skautis_birthday'),
            birthday: self::text($userinfo, 'birthday'),
            street: self::text($userinfo, 'street'),
            city: self::text($userinfo, 'city'),
            zip: self::text($userinfo, 'zip'),
            avatarUrl: self::text($userinfo, 'avatar_url'),
            skautis: is_array($skautis) ? SkautisInfo::fromArray($skautis) : null,
            skautisUnit: is_array($unit) ? SkautisUnit::fromArray($unit) : null,
            skautisStredisko: is_array($stredisko) ? SkautisStredisko::fromArray($stredisko) : null,
            raw: $userinfo,
            token: $token,
            refreshToken: $refreshToken,
            expiresIn: $expiresIn,
            scopes: array_values(array_filter($scopes, static fn ($scope) => is_string($scope) && $scope !== '')),
        );
    }

    public function getId(): string
    {
        return $this->sub;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getAvatar(): ?string
    {
        return $this->avatarUrl;
    }

    /**
     * @param  array<string, mixed>  $userinfo
     */
    protected static function text(array $userinfo, string $key): ?string
    {
        $value = $userinfo[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
