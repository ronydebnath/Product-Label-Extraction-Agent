<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $upload_id
 * @property string $content_hash
 * @property string $model
 * @property int $prompt_version
 * @property array<string, mixed> $data
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $duration_ms
 * @property \Illuminate\Support\Carbon $created_at
 */
class Extraction extends Model
{
    use HasUuids;

    // Written exactly once, never updated; there is nothing for updated_at to record.
    public const null UPDATED_AT = null;

    protected $fillable = [
        'upload_id',
        'content_hash',
        'model',
        'prompt_version',
        'data',
        'input_tokens',
        'output_tokens',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'prompt_version' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Upload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }
}
