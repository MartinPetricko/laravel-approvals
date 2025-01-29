<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drafts', static function (Blueprint $table) {
            $table->id();

            $table->nullableMorphs('author');
            $table->nullableMorphs('reviewer');
            $table->morphs('draftable');

            $table->string('request_id');
            $table->string('status');
            $table->string('type');

            $table->text('old_data');
            $table->text('new_data');

            $table->text('message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};
