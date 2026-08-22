<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Models;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 *
 * @see \ZeroBoiler\Events\TriggerBuilder
 */
final class Trigger extends Model
{
    /** @use HasFactory<TriggerFactory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * Get the table name from config with type-safe fallback.
     *
     * Uses the container's ConfigRepository for testability instead of
     * the static config() facade.
     * @since 1.0.0
     */
    public function getTable(): string
    {
        $config = app('config');

        if ($config instanceof ConfigRepository) {
            $table = $config->get('events.table_names.triggers', 'triggers');

            if (is_string($table) && $table !== '') {
                return $table;
            }
        }

        return 'triggers';
    }

    protected $keyType = 'string';

    public bool $incrementing = false;

    /** @var list<string> */
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

    /**
     * The "booted" method of the model.
     *
     * Auto-generates a UUID primary key when creating a new model
     * instance without an explicit ID.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if ($model->id === '' || $model->id === null) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the event logs for this trigger.
     *
     * @return HasMany<EventLog, covariant $this>
     * @since 1.0.0
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
     * @since 1.0.0
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
     * @since 1.0.0
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
     * @since 1.0.0
     */
    public function scopeOrderByPriority(Builder $query): Builder
    {
        /** @var Builder<Trigger> */
        return $query->orderByDesc('priority');
    }

    /**
     * @return TriggerFactory<Trigger>
     */
    protected static function newFactory(): TriggerFactory
    {
        return TriggerFactory::new();
    }

    /**
     * @return array<string, string>
     */
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
