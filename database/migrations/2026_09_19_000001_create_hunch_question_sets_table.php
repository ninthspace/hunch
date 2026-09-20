<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hunch_question_sets', function (Blueprint $table) {
            $table->id();
            $table->string('hash')->unique();
            $table->string('version')->nullable();
            $table->longText('canonical');
            $table->json('definition')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hunch_question_sets');
    }
};
