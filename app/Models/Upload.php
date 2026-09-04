<?php

namespace App\Models;

use App\Enums\FailureCode;
use App\Enums\UploadStatus;
use Database\Factories\UploadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    /** @use HasFactory<UploadFactory> */
    use HasFactory, HasUuids;

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
     * Claim this row for processing, or report that somebody else already has it.
     *
     * A single conditional UPDATE, never read-then-save: two workers handed the same job by a
     * redelivery both run this, the database serialises them, and exactly one sees a row affected.
     * The other is told no and does nothing. A read followed by a save would let both through.
     *
     * A row already in `processing` is claimable once its lease has expired, which is how the work
     * of a worker killed mid-job (OOM, deploy, SIGKILL) gets picked up rather than stranded.
     */
    public function claimForProcessing(?int $leaseSeconds = null): bool
    {
        $now = now();
        $staleBefore = $now->copy()->subSeconds($leaseSeconds ?? (int) config('llm.lease_seconds'));

        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where(function (Builder $query) use ($staleBefore) {
                $query->where('status', UploadStatus::Queued->value)
                    ->orWhere(fn (Builder $stale) => $stale
                        ->where('status', UploadStatus::Processing->value)
                        ->where('processing_started_at', '<', $staleBefore));
            })
            ->update([
                'status' => UploadStatus::Processing->value,
                'processing_started_at' => $now,
                // Counted here rather than on success, so a worker that dies mid-call still
                // burns an attempt and a poisonous file cannot loop forever.
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return false;
        }

        $this->refresh();

        return true;
    }

    /** processing -> completed. Guarded, so a stale worker cannot overwrite a newer outcome. */
    public function markCompleted(): bool
    {
        return $this->transition(
            [UploadStatus::Queued, UploadStatus::Processing],
            ['status' => UploadStatus::Completed->value, 'completed_at' => now()],
        );
    }

    /**
     * processing -> queued, for a failure worth retrying. The lease is cleared because the row is
     * nobody's now; the error is kept so an operator can see why it is going round again.
     */
    public function returnToQueue(string $error): bool
    {
        return $this->transition(
            [UploadStatus::Processing],
            [
                'status' => UploadStatus::Queued->value,
                'processing_started_at' => null,
                'last_error' => Str::limit($error, 500),
            ],
        );
    }

    /**
     * Move the row to a terminal failure. `last_error` keeps the technical detail for operators;
     * `failure_code` is the only thing the UI ever reads, which is what stops an exception message
     * from reaching a browser (FR-29).
     */
    public function markFailed(FailureCode $code, string $error): bool
    {
        // Only from a non-terminal state: whatever failed first is the real cause, and a later
        // hook firing after the fact must not rewrite it into something less specific.
        return $this->transition([UploadStatus::Queued, UploadStatus::Processing], [
            'status' => UploadStatus::Failed->value,
            'failure_code' => $code->value,
            // Capped rather than storing a whole stack trace: this column is read in list queries.
            'last_error' => Str::limit($error, 500),
            'failed_at' => now(),
        ]);
    }

    /**
     * One conditional UPDATE plus an affected-rows check, with the in-memory model kept in step.
     *
     * @param  array<int, UploadStatus>  $from
     * @param  array<string, mixed>  $values
     */
    private function transition(array $from, array $values): bool
    {
        $values['updated_at'] = now();

        $affected = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(fn (UploadStatus $s) => $s->value, $from))
            ->update($values);

        if ($affected === 0) {
            return false;
        }

        $this->forceFill($values)->syncOriginal();

        return true;
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
