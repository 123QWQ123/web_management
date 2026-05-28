<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE domains SET mode = 'sw'    WHERE mode = 'sw_only'");
        DB::statement("UPDATE domains SET mode = 'cf_sw' WHERE mode = 'dns'");
        DB::statement("UPDATE domains SET mode = 'cf'    WHERE mode = 'cf_only'");
        DB::statement("UPDATE domains SET status = 'sw_backends' WHERE status = 'sw_only_backends'");
    }

    public function down(): void
    {
        DB::statement("UPDATE domains SET mode = 'sw_only' WHERE mode = 'sw'");
        DB::statement("UPDATE domains SET mode = 'dns'     WHERE mode = 'cf_sw'");
        DB::statement("UPDATE domains SET status = 'sw_only_backends' WHERE status = 'sw_backends'");
    }
};
