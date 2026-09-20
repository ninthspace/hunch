<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hunch_calibration', function (Blueprint $table) {
            $table->id();
            $table->string('question_set_hash');
            $table->string('driver');
            $table->string('provider');
            $table->string('model')->default('');
            $table->string('sampling');
            $table->string('question');
            $table->string('answer');
            $table->string('bucket');
            $table->unsignedInteger('n');
            $table->unsignedInteger('correct');
            $table->double('accuracy');
            $table->double('lower_bound');
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(
                ['question_set_hash', 'driver', 'provider', 'model', 'sampling', 'question', 'answer', 'bucket'],
                'hunch_calibration_cell_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hunch_calibration');
    }
};
