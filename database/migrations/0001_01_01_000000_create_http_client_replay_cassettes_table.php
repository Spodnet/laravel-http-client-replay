<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table = config('http-client-replay.drivers.database.table', 'http_client_replay_cassettes');

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->unique();
            $table->string('domain')->nullable()->index();
            $table->string('signature')->index();
            $table->unsignedSmallInteger('status')->default(200);
            $table->longText('data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = config('http-client-replay.drivers.database.table', 'http_client_replay_cassettes');

        Schema::dropIfExists($table);
    }
};
