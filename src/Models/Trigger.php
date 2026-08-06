<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ZeroBoiler\Events\Database\Factories\TriggerFactory;

/**
 * @property string $id
 * @property string $name
 * @property string $event
 * @property string $action
 * @property array<string, mixed> $conditions
 * @property bool $async
 * @property int $priority
 * @property bool $enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, EventLog> $eventLogs
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<Trigger>
 */
class Trigger extends Model
{
    /** @use HasFactory<TriggerFactory> */
    use HasFactory;
    use SoftDeletes;

    /** @var string */
    protected $table = 'triggers';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var array<int, string> */
    protected $fillable = [
        'id',
        'name',
        'event',
        'action',
        'conditions',
        'async',
        'priority',
        'enabled',
    ];

    /** @var array<int, string> */
    protected $hidden = [
        'deleted_at',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the event logs for this trigger.
     *
     * @return HasMany<EventLog, $this>
     */
    public function eventLogs(): HasMany
    {
        return $this->hasMany(EventLog::class, 'trigger_id');
    }

    /**
     * Scope a query to only include enabled triggers.
     *
     * @param  Builder<Trigger>  $query
     * @return Builder<Trigger>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope a query to only include async triggers.
     *
     * @param  Builder<Trigger>  $query
     * @return Builder<Trigger>
     */
    public function scopeAsync(Builder $query): Builder
    {
        return $query->where('async', true);
    }

    /**
     * Scope a query to order by priority (higher priority first).
     *
     * @param  Builder<Trigger>  $query
     * @return Builder<Trigger>
     */
    public function scopeOrderByPriority(Builder $query): Builder
    {
        /** @var Builder<Trigger> */
        return $query->orderByDesc('priority');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return TriggerFactory<Trigger>
     */
    protected static function newFactory(): TriggerFactory
    {
        return TriggerFactory::new();
    }

    /**
     * @return array<string, string> */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'async' => 'boolean',
            'enabled' => 'boolean',
            'priority' => 'int',
        ];
    }
}
