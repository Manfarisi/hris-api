<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->decimal('in_lat', 10, 7)->nullable();
            $table->decimal('in_lng', 10, 7)->nullable();
            $table->decimal('out_lat', 10, 7)->nullable();
            $table->decimal('out_lng', 10, 7)->nullable();
            $table->enum('status', ['present', 'late', 'absent', 'leave'])->default('present');
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};