<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain');

            $table->string('mode')->default('cf');

            // cf | cf_sw

            $table->string('status')->default('init');

            $table->string('cloudflare_zone_id')->nullable();

            $table->string('cloudflare_dns_id')->nullable();

            $table->string('stormwall_domain_id')->nullable();

            $table->string('server_ip')->nullable();

            $table->string('stormwall_ip')->nullable();

            $table->integer('retries')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('domains');
    }
};
