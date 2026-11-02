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
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            // 0 = payroll asli, 1..n = koreksi yang merujuk payroll asli.
            $table->unsignedSmallInteger('revision_no')->default(0);
            $table->foreignId('adjusts_payroll_id')->nullable()->constrained('payrolls')->restrictOnDelete();
            $table->string('status', 10)->default('draft');
            $table->string('note', 500)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            // Penjaga sebenarnya: satu payroll asli per karyawan per bulan, dan nomor revisi tidak boleh kembar.
            $table->unique(['employee_id', 'year', 'month', 'revision_no']);
            $table->index(['year', 'month']);
            $table->index('status');
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label', 100)->nullable();
            // Rupiah bulat, bertanda: koreksi bisa bernilai negatif.
            $table->bigInteger('amount');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payrolls');
    }
};
