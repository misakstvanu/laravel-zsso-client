<?php

namespace Misakstvanu\ZssoClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Misakstvanu\ZssoClient\Webhooks\WebhookEnvelope;

/**
 * One received webhook id (contract C-7). The table is the de-duplication:
 * a sender that never got its 2xx retries, and the second delivery of an id
 * already in here is answered without doing anything again.
 *
 * @property string $id
 * @property string $type
 * @property Carbon $received_at
 */
class WebhookReceipt extends Model
{
    protected $table = 'zsso_webhook_receipts';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'type',
        'received_at',
    ];

    /**
     * Claim an envelope: `true` when this receipt is new, `false` when the id
     * was already recorded. The insert is what claims it, so two deliveries
     * arriving at once cannot both win.
     */
    public static function record(WebhookEnvelope $envelope): bool
    {
        return static::query()->insertOrIgnore([
            'id' => $envelope->id,
            'type' => $envelope->type,
            'received_at' => Date::now(),
        ]) > 0;
    }

    /**
     * Give the id back, so a delivery whose handlers threw is processed again
     * when the sender retries it.
     */
    public static function release(WebhookEnvelope $envelope): void
    {
        static::query()->whereKey($envelope->id)->delete();
    }
}
