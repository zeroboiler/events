<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Database\Factories\SubscriptionFactory;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Represents an external webhook subscription to an event.
 *
 * Unlike internal triggers, subscriptions are designed for external systems
 * that want to receive HTTP POST notifications when events fire. Each
 * subscription has its own HMAC signing secret for payload verification.
 *
 * @property string $id
 * @property string $event
 * @property string $url
 * @property array<string, mixed>|null $conditions
 * @property int $priority
 * @property bool $active
 * @property string|null $secret
 * @property Carbon|null $last_fired_at
 * @property int $failure_count
 * @property int $delivery_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<Subscription>
 */
class Subscription extends Model
{
    use EscapesWildcardLike;
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;
    use SoftDeletes;

    #[\Override]
    public function getTable(): string
    {
        $table = config('events.table_names.subscriptions', 'event_subscriptions');

        return is_string($table) ? $table : 'event_subscriptions';
    }

    protected string $keyType = 'string';

    public bool $incrementing = false;

    /** @var list<string> */
    protected array $fillable = [
        'id',
        'event',
        'url',
        'conditions',
        'priority',
        'active',
        'secret',
        'last_fired_at',
        'failure_count',
        'delivery_count',
    ];

    /** @var array<int, string> */
    protected array $hidden = [
        'secret',
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
     * Scope a query to only include active subscriptions.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Scope a query to only include subscriptions for a specific event.
     *
     * Supports wildcard matching (e.g., "order.*", "order.**").
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeForEvent(Builder $query, string $event): Builder
    {
        $likePattern = $this->wildcardToLike($event);

        if ($likePattern !== null) {
            return $query->where('event', 'like', $likePattern);
        }

        return $query->where(function (Builder $q) use ($event): void {
            $q->where('event', $event)
                ->orWhere('event', 'like', '%*%');
        });
    }

    /**
     * Scope a query to order by priority (higher first).
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeOrderByPriority(Builder $query): Builder
    {
        /** @var Builder<Subscription> */
        return $query->orderByDesc('priority');
    }

    /**
     * Check if this subscription's event pattern matches a concrete event.
     *
     * Uses exact match for non-wildcard events; delegates to WildcardMatcher
     * for patterns containing `*` or `**`.
     *
     * @see WildcardMatcher::matches()
     */
    public function matchesEvent(string $event): bool
    {
        if (! str_contains($this->event, '*')) {
            return $this->event === $event;
        }

        // Delegate to WildcardMatcher for consistent wildcard semantics,
        // including ** (cross-segment) and * (single-segment) handling.
        return WildcardMatcher::matches($this->event, $event);
    }

    /**
     * Record a delivery attempt.
     *
     * Updates `last_fired_at` to now and increments `delivery_count`.
     */
    public function recordDelivery(): void
    {
        $this->update([
            'last_fired_at' => Carbon::now(),
            'delivery_count' => $this->delivery_count + 1,
        ]);
    }

    /**
     * Record a delivery failure and increment the failure counter.
     */
    public function recordFailure(): void
    {
        $this->increment('failure_count');
    }

    /**
     * Reset the failure counter to zero.
     */
    public function resetFailures(): void
    {
        $this->update(['failure_count' => 0]);
    }

    /**
     * Scope a query to only include subscriptions that have exceeded
     * the failure threshold and should be deactivated.
     *
     * Reads the threshold from `events.subscriptions.max_failures` config.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeExceededFailures(Builder $query): Builder
    {
        $threshold = Config::get('events.subscriptions.max_failures', 10);
        $max = is_int($threshold) ? $threshold : 10;

        return $query->where('failure_count', '>=', $max);
    }

    /**
     * Check if the subscription has exceeded the maximum failure threshold.
     *
     * Reads the default threshold from `events.subscriptions.max_failures` config
     * when no explicit max is provided.
     *
     * @param  int|null  $max  Explicit failure threshold override, or null to use config
     */
    public function hasExceededFailures(?int $max = null): bool
    {
        $threshold = $max ?? Config::get('events.subscriptions.max_failures', 10);

        return $this->failure_count >= (is_int($threshold) ? $threshold : 10);
    }

    /**
     * Generate an HMAC signature for a payload using this subscription's secret.
     *
     * The hash algorithm is configurable via `events.subscriptions.signature_algorithm`.
     */
    public function signPayload(string $payload): string
    {
        if ($this->secret === null || $this->secret === '') {
            return '';
        }

        $algorithm = Config::get('events.subscriptions.signature_algorithm', 'sha256');

        $result = hash_hmac(is_string($algorithm) ? $algorithm : 'sha256', $payload, $this->secret);

        return $result !== false ? $result : '';
    }

    /**
     * @return SubscriptionFactory<Subscription>
     */
    #[\Override]
    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'priority' => 'int',
            'active' => 'boolean',
            'failure_count' => 'int',
            'delivery_count' => 'int',
            'last_fired_at' => 'datetime',
        ];
    }
}
