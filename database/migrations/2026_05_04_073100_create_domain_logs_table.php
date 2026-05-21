<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('domain_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();

            $table->string('step');

            $table->text('request')->nullable();

            $table->text('response')->nullable();

            $table->boolean('success')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('domain_logs');
    }
};
