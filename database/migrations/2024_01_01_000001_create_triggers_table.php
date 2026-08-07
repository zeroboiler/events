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
     * Get the triggers table name from config.
     */
    private function getTableName(): string
    {
        return config('events.table_names.triggers', 'triggers');
    }

    public function up(): void
    {
        Schema::create($this->getTableName(), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('event');
            $table->text('action');
            $table->json('conditions')->nullable();
            $table->boolean('async')->default(false);
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event', 'enabled']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->getTableName());
    }
};
