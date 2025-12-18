<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('category_aliases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->index();

            $table->string('phrase', 255);
            $table->string('phrase_norm', 255)->index();

            $table->unsignedTinyInteger('weight')->default(1);
            $table->string('source', 50)->nullable()->index(); // full_path/segment/token
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->unique(['category_id', 'phrase_norm'], 'cat_alias_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_aliases');
    }
};
