<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hunch_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('classification_id')->constrained('hunch_classifications')->restrictOnDelete();
            $table->string('question');
            $table->json('answer');
            $table->string('labelled_by_type')->nullable();
            $table->string('labelled_by_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['classification_id', 'question', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hunch_labels');
    }
};
