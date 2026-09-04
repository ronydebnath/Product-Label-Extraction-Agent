<?php

namespace App\Models;

use App\Enums\FailureCode;
use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int $user_id
 * @property string $original_name
 * @property string $mime_type
 * @property string $kind
 * @property int $size_bytes
 * @property int|null $page_count
 * @property string $content_hash
 * @property string $storage_path
 * @property UploadStatus $status
 * @property int $attempts
 * @property Carbon|null $processing_started_at
 * @property string|null $last_error
 * @property string|null $failure_code
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Upload extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'original_name',
        'mime_type',
        'kind',
        'size_bytes',
        'page_count',
        'content_hash',
        'storage_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => UploadStatus::class,
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'attempts' => 'integer',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Move the row to a terminal failure. `last_error` keeps the technical detail for operators;
     * `failure_code` is the only thing the UI ever reads, which is what stops an exception message
     * from reaching a browser (FR-29).
     */
    public function markFailed(FailureCode $code, string $error): void
    {
        $this->forceFill([
            'status' => UploadStatus::Failed,
            'failure_code' => $code->value,
            // The column is indexed and shown in logs, so cap it rather than storing a stack trace.
            'last_error' => Str::limit($error, 500),
            'failed_at' => now(),
        ])->save();
    }

    /** The sentence a user is shown for a failed upload, or null while it can still succeed. */
    public function failureMessage(): ?string
    {
        return $this->failure_code === null
            ? null
            : FailureCode::from($this->failure_code)->message();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasOne<Extraction, $this> */
    public function extraction(): HasOne
    {
        return $this->hasOne(Extraction::class);
    }
}
