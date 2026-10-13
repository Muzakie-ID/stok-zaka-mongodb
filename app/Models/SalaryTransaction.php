<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MongoDB\Laravel\Eloquent\Model;

class SalaryTransaction extends Model
{
    public const TYPE_EARN = 'earn';

    public const TYPE_PAID = 'paid';

    protected $connection = 'mongodb';

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'admin_id',
        'type',
        'amount',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'user_id' => 'string',
            'admin_id' => 'string',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** Saldo saat ini untuk seorang user (earn minus paid). */
    public static function saldoFor(string|int $userId): float
    {
        $pipeline = [
            [
                '$match' => ['user_id' => (string) $userId],
            ],
            [
                '$group' => [
                    '_id' => null,
                    'saldo' => [
                        '$sum' => [
                            '$cond' => [
                                ['$eq' => ['$type', self::TYPE_EARN]],
                                '$amount',
                                ['$multiply' => ['$amount', -1]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = static::raw(function ($collection) use ($pipeline) {
            return $collection->aggregate($pipeline);
        })->first();

        return (float) ($result->saldo ?? 0);
    }
}
