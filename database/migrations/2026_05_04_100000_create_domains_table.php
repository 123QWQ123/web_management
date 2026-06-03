<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain');

            // Association with project, preland and traffic flow
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('preland_id')->nullable();
            $table->unsignedBigInteger('traffic_flow_id')->nullable();

            // Routing mode: cf | sw | cf_sw
            $table->string('mode')->default('cf');

            // Previous mode snapshot for one-click revert
            $table->string('previous_mode')->nullable();
            $table->json('previous_config')->nullable();

            // Which service currently handles inbound HTTP traffic (cf | sw)
            $table->string('active_traffic_receiver')->default('cf');

            // Workflow step tracking
            $table->string('status')->default('init');

            // Cloudflare identifiers
            $table->string('cloudflare_zone_id')->nullable();
            $table->json('cloudflare_nameservers')->nullable();
            $table->string('cloudflare_dns_id')->nullable();

            // StormWall identifiers
            $table->string('stormwall_domain_id')->nullable();

            // IPs
            $table->string('server_ip')->nullable();
            $table->string('stormwall_ip')->nullable();

            // SSL lifecycle timestamps (StormWall)
            $table->timestamp('ssl_requested_at')->nullable();
            $table->timestamp('ssl_ready_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();

            $table->integer('retries')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
