<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the event_logs table name from config.
     */
    private function getTableName(): string
    {
        return config('events.table_names.event_logs', 'event_logs');
    }

    /**
     * Get the triggers table name from config (for foreign key reference).
     */
    private function getTriggersTableName(): string
    {
        return config('events.table_names.triggers', 'triggers');
    }

    public function up(): void
    {
        Schema::create($this->getTableName(), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('trigger_id');
            $table->string('event');
            $table->json('payload');
            $table->enum('status', ['pending', 'dispatched', 'completed', 'failed'])->default('pending');
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('trigger_id')->references('id')->on($this->getTriggersTableName())->onDelete('cascade');
            $table->index(['trigger_id', 'status']);
            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->getTableName());
    }
};
