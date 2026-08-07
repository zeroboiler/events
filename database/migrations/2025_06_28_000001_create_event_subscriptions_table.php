<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores external webhook subscriptions for events.
 *
 * These are high-level subscription records that complement the triggers
 * table — each webhook subscription can be managed independently (paused,
 * deleted, retried) without affecting internal trigger logic.
 */
return new class extends Migration
{
    /**
     * Get the subscriptions table name from config.
     */
    private function getTableName(): string
    {
        return config('events.table_names.subscriptions', 'event_subscriptions');
    }

    public function up(): void
    {
        Schema::create($this->getTableName(), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event');
            $table->string('url');
            $table->json('conditions')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->string('secret')->nullable()->comment('HMAC signing secret for webhook verification');
            $table->timestamp('last_fired_at')->nullable();
            $table->integer('failure_count')->default(0);
            $table->integer('delivery_count')->default(0)->comment('Total successful deliveries');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['event', 'active']);
            $table->index('url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->getTableName());
    }
};
