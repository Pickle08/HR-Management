<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->string('code', 2)->primary();
            $table->string('name', 150);
        });

        Schema::create('regencies', function (Blueprint $table) {
            $table->string('code', 5)->primary();
            $table->string('province_code', 2);
            $table->string('name', 150);

            $table->index('province_code');
            $table->foreign('province_code')->references('code')->on('provinces')->restrictOnDelete();
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->string('code', 8)->primary();
            $table->string('regency_code', 5);
            $table->string('name', 150);

            $table->index('regency_code');
            $table->foreign('regency_code')->references('code')->on('regencies')->restrictOnDelete();
        });

        Schema::create('villages', function (Blueprint $table) {
            $table->string('code', 13)->primary();
            $table->string('district_code', 8);
            $table->string('name', 150);

            $table->index('district_code');
            $table->foreign('district_code')->references('code')->on('districts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('villages');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('regencies');
        Schema::dropIfExists('provinces');
    }
};
