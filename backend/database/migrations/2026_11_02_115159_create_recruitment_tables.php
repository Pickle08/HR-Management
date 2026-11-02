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
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('headcount');
            $table->text('description')->nullable();
            $table->string('status', 10)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_opening_id')->constrained()->restrictOnDelete();
            $table->string('full_name', 150);
            $table->string('email', 190);
            $table->text('phone')->nullable(); // terenkripsi, jadi text
            $table->string('stage', 12)->default('applied');
            $table->foreignId('converted_employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['job_opening_id', 'email']);
            $table->index('stage');
        });

        Schema::create('candidate_stage_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->string('from_stage', 12)->nullable();
            $table->string('to_stage', 12);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_stage_histories');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('job_openings');
    }
};
