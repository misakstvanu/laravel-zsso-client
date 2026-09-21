<?php

namespace Misakstvanu\ZssoClient\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Misakstvanu\ZssoClient\SsoUser;

/**
 * The zSSO tokens this app holds for one local user.
 *
 * Both tokens use the `encrypted` cast, so the database never carries a
 * usable credential. `user_id` is the primary key: a user has one zSSO
 * session per app.
 *
 * @property string $user_id
 * @property string $sub
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property array<int, string> $scopes
 */
class ZssoToken extends Model
{
    /**
     * Seconds before `expires_at` at which the access token counts as spent,
     * so a call made right after `tokenFor()` still has a live token.
     */
    public const EXPIRY_LEEWAY_SECONDS = 60;

    protected $table = 'zsso_tokens';

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'sub',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    /**
     * The row of a local user, if this app holds one.
     */
    public static function forUser(Authenticatable $user): ?self
    {
        return static::query()->find(static::keyFor($user));
    }

    /**
     * Write what the login (or a refresh) got back from zSSO.
     */
    public static function storeFor(Authenticatable $user, SsoUser $ssoUser): self
    {
        return static::query()->updateOrCreate(
            ['user_id' => static::keyFor($user)],
            [
                'sub' => $ssoUser->sub,
                'access_token' => (string) $ssoUser->token,
                'refresh_token' => $ssoUser->refreshToken,
                'expires_at' => $ssoUser->expiresIn !== null ? now()->addSeconds($ssoUser->expiresIn) : null,
                'scopes' => $ssoUser->scopes,
            ],
        );
    }

    /**
     * A token zSSO said nothing about never expires on its own; the first
     * `401` from an API is what ends it.
     */
    public function isExpired(): bool
    {
        return $this->expires_at?->subSeconds(self::EXPIRY_LEEWAY_SECONDS)->isPast() === true;
    }

    protected static function keyFor(Authenticatable $user): string
    {
        return (string) $user->getAuthIdentifier();
    }
}
