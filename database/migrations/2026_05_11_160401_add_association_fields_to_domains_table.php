<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('domain');
            $table->unsignedBigInteger('preland_id')->nullable()->after('project_id');
            $table->unsignedBigInteger('traffic_flow_id')->nullable()->after('preland_id');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['project_id', 'preland_id', 'traffic_flow_id']);
        });
    }
};
