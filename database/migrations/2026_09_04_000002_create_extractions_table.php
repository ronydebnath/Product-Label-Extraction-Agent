<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kept apart from uploads on purpose: the uploads row is polled every few seconds and
        // stays small, while this is written once and read on the detail page. The unique
        // upload_id is the database-enforced guarantee that a job running twice cannot double-write.
        Schema::create('extractions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('upload_id')->unique()->constrained()->cascadeOnDelete();

            // Denormalised from uploads so identical bytes can reuse a result with one indexed lookup.
            // model + prompt_version are part of the key: change either and old results stop matching.
            $table->char('content_hash', 64);
            $table->string('model', 64);
            $table->unsignedSmallInteger('prompt_version');

            // Only schema-validated documents are ever written here.
            $table->jsonb('data');

            // Cost and latency per file; what you look at when the OpenAI bill arrives.
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['content_hash', 'model', 'prompt_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extractions');
    }
};
