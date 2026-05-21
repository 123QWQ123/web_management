<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Traffic switcher: stores snapshot of the previous state for one-click revert
            $table->string('previous_mode')->nullable()->after('mode');
            $table->json('previous_config')->nullable()->after('previous_mode');

            // Which service currently handles HTTP traffic (cf | sw)
            $table->string('active_traffic_receiver')->default('cf')->after('previous_config');

            // Cloudflare anycast/proxy IP — used as StormWall backend in sw_cf mode
            $table->string('cf_proxy_ip')->nullable()->after('stormwall_ip');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['previous_mode', 'previous_config', 'active_traffic_receiver', 'cf_proxy_ip']);
        });
    }
};
