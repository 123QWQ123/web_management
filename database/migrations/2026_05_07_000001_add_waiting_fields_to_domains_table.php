<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->timestamp('ssl_requested_at')->nullable()->after('stormwall_ip');
            $table->timestamp('ssl_ready_at')->nullable()->after('ssl_requested_at');
            $table->timestamp('next_attempt_at')->nullable()->after('ssl_ready_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'ssl_requested_at',
                'ssl_ready_at',
                'next_attempt_at',
            ]);
        });
    }
};
