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
        Schema::table('employees', function (Blueprint $table) {
            $table->string('province_code', 2)->nullable()->after('address');
            $table->string('regency_code', 5)->nullable()->after('province_code');
            $table->string('district_code', 8)->nullable()->after('regency_code');
            $table->string('village_code', 13)->nullable()->after('district_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['province_code', 'regency_code', 'district_code', 'village_code']);
        });
    }
};
