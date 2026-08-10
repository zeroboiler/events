<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ZeroBoiler\Events\Database\Factories\EventLogFactory;

/**
 * @property string $id
 * @property string $trigger_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $status
 * @property string|null $error
 * @property int|null $duration_ms
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Trigger $trigger
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<EventLog>
 */
class EventLog extends Model
{
    /** @use HasFactory<EventLogFactory> */
    use HasFactory;
    use SoftDeletes;

    #[\Override]
    public function getTable(): string
    {
        $table = config('events.table_names.event_logs', 'event_logs');

        return is_string($table) ? $table : 'event_logs';
    }

    protected string $keyType = 'string';

    public bool $incrementing = false;

    /** @var list<string> */
    protected array $fillable = [
        'id',
        'trigger_id',
        'event',
        'payload',
        'status',
        'error',
        'duration_ms',
    ];

    /** @var array<int, string> */
    protected array $hidden = [
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

    public const STATUS_PENDING = 'pending';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var array<int, string> */
    public static array $statuses = [
        self::STATUS_PENDING,
        self::STATUS_DISPATCHED,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * Get the trigger that owns this event log.
     *
     * @return BelongsTo<Trigger, covariant $this>
     */
    public function trigger(): BelongsTo
    {
        return $this->belongsTo(Trigger::class, 'trigger_id');
    }

    /**
     * Scope a query to only include logs with a specific status.
     *
     * @param  Builder<EventLog>  $query
     * @return Builder<EventLog>
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include failed logs.
     *
     * @param  Builder<EventLog>  $query
     * @return Builder<EventLog>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to only include pending logs.
     *
     * @param  Builder<EventLog>  $query
     * @return Builder<EventLog>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include completed logs.
     *
     * @param  Builder<EventLog>  $query
     * @return Builder<EventLog>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include pending event logs older than a threshold.
     *
     * Useful for identifying stuck event logs that may need manual
     * intervention (e.g., queue worker crash, DB connection lost).
     *
     * @param  Builder<EventLog>  $query
     * @return Builder<EventLog>
     */
    public function scopeStalePending(Builder $query, Carbon $before): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('created_at', '<', $before);
    }

    /**
     * Mark the log as completed.
     */
    public function markAsCompleted(int $durationMs): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Mark the log as failed.
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => $error,
        ]);
    }

    /**
     * @return EventLogFactory<EventLog>
     */
    #[\Override]
    protected static function newFactory(): EventLogFactory
    {
        return EventLogFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'duration_ms' => 'int',
            'error' => 'string',
        ];
    }
}
