<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('overall_rating'); // Q1: 1-5
            $table->string('recommendation_accuracy'); // Q2
            $table->json('problems')->nullable(); // Q3: checkboxes
            $table->string('tone_feedback'); // Q4
            $table->json('business_impact')->nullable(); // Q5: checkboxes
            $table->string('best_feature'); // Q6
            $table->string('missing_feature'); // Q7
            $table->string('willingness_to_pay'); // Q8
            $table->tinyInteger('nps_score'); // Q9: 0-10
            $table->text('open_comment')->nullable(); // Q10
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
