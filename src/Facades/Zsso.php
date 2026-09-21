<?php

namespace Misakstvanu\ZssoClient\Facades;

use Illuminate\Support\Facades\Facade;
use Misakstvanu\ZssoClient\Zsso as ZssoManager;

/**
 * @method static \Misakstvanu\ZssoClient\IntegrationClient integration(string $app)
 * @method static \Misakstvanu\ZssoClient\SkautisClient skautis(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Misakstvanu\ZssoClient\Webhooks\WebhookDispatcher webhook(string $type, array $data = [])
 * @method static void onWebhook(string $type, string|callable $handler)
 * @method static string tokenFor(\Illuminate\Contracts\Auth\Authenticatable $user)
 *
 * @see ZssoManager
 */
class Zsso extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ZssoManager::class;
    }
}
