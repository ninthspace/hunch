<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hunch_classifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->char('state_hash', 64);
            $table->string('question_set_hash')->index();
            $table->string('version')->nullable();
            $table->string('driver');
            $table->string('provider');
            $table->string('model_requested')->nullable();
            $table->string('model_reported')->nullable();
            $table->string('sampling');
            $table->string('status');
            $table->text('error')->nullable();
            $table->unsignedInteger('samples_requested');
            $table->unsignedInteger('samples_valid');
            $table->unsignedInteger('samples_invalid');
            $table->json('usage');
            $table->json('answers')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hunch_classifications');
    }
};
