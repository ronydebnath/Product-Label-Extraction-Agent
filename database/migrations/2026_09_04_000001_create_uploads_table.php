<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            // UUID v7: time-ordered like an auto-increment, but unguessable in URLs and safe to
            // generate before the insert. It doubles as the correlation id in every log line.
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Display only. Never used to build a path; storage_path is uuid-based.
            $table->string('original_name');
            // Sniffed from the bytes, never taken from the client.
            $table->string('mime_type', 64);
            $table->string('kind', 8);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('page_count')->nullable();
            // sha256 of the bytes: the only identity a client cannot lie about. Drives extraction reuse.
            $table->char('content_hash', 64);
            $table->string('storage_path');

            $table->string('status', 16);
            // Our own count of LLM attempts, independent of the queue's attempt counter.
            $table->unsignedSmallInteger('attempts')->default(0);
            // A worker holds the row while this is fresh; a stale value means that worker died.
            $table->timestampTz('processing_started_at')->nullable();
            // Sanitised, operator-facing note ("openai http 503"). Never rendered in the UI.
            $table->string('last_error')->nullable();
            // Enum value; the user-facing text is derived from it in code, so nothing internal leaks.
            $table->string('failure_code', 32)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            // List filters and the stuck-upload sweeper both query by status and age.
            $table->index(['status', 'created_at']);
            $table->index('content_hash');
        });

        // Invariants the application relies on, enforced where a bug cannot bypass them.
        // A varchar + CHECK rather than a Postgres enum: adding a value is one constraint swap,
        // whereas enum values cannot be removed or reordered.
        DB::statement("ALTER TABLE uploads ADD CONSTRAINT uploads_status_check CHECK (status IN ('queued', 'processing', 'completed', 'failed'))");
        DB::statement("ALTER TABLE uploads ADD CONSTRAINT uploads_kind_check CHECK (kind IN ('image', 'pdf'))");
        DB::statement('ALTER TABLE uploads ADD CONSTRAINT uploads_size_bytes_check CHECK (size_bytes > 0)');
        DB::statement("ALTER TABLE uploads ADD CONSTRAINT uploads_failed_has_code_check CHECK ((status = 'failed') = (failure_code IS NOT NULL))");
        DB::statement("ALTER TABLE uploads ADD CONSTRAINT uploads_processing_has_lease_check CHECK (status <> 'processing' OR processing_started_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
