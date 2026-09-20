<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hunch_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('classification_id')->constrained('hunch_classifications')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->boolean('valid');
            $table->json('answers')->nullable();
            $table->string('invalid_reason')->nullable();
            $table->text('reason')->nullable();
            $table->json('band')->nullable();
            $table->string('seed')->nullable();
            $table->string('request_id')->nullable();
            $table->string('model')->nullable();
            $table->json('usage');
            $table->timestamps();

            $table->unique(['classification_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hunch_samples');
    }
};
