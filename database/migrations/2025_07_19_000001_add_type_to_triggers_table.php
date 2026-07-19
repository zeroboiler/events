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
    public function up(): void
    {
        Schema::table('triggers', function (Blueprint $table) {
            $table->string('type')->default('integration')->after('event');
            $table->index(['event', 'type', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('triggers', function (Blueprint $table) {
            $table->dropIndex(['event', 'type', 'enabled']);
            $table->dropColumn('type');
        });
    }
};
