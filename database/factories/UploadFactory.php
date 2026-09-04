<?php

namespace Database\Factories;

use App\Enums\FailureCode;
use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Upload> */
class UploadFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'original_name' => fake()->slug(3).'.pdf',
            'mime_type' => 'application/pdf',
            'kind' => 'pdf',
            'size_bytes' => fake()->numberBetween(10_000, 500_000),
            'page_count' => fake()->numberBetween(1, 10),
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'storage_path' => 'uploads/2026/09/'.fake()->uuid().'.pdf',
            'status' => UploadStatus::Queued,
            'attempts' => 0,
        ];
    }

    public function image(): static
    {
        return $this->state(fn () => [
            'mime_type' => 'image/png',
            'kind' => 'image',
            'page_count' => null,
            'original_name' => fake()->slug(3).'.png',
            'storage_path' => 'uploads/2026/09/'.fake()->uuid().'.png',
        ]);
    }

    /**
     * A row a worker has claimed. `stale: true` puts the lease beyond the point where another
     * worker is entitled to take it over, which is how a killed worker's job gets picked up.
     */
    public function processing(bool $stale = false, int $attempts = 1): static
    {
        return $this->state(fn () => [
            'status' => UploadStatus::Processing,
            'attempts' => $attempts,
            'processing_started_at' => $stale
                ? now()->subSeconds((int) config('llm.lease_seconds') + 60)
                : now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => UploadStatus::Completed,
            'attempts' => 1,
            'completed_at' => now(),
        ]);
    }

    public function failed(FailureCode $code = FailureCode::LlmUnavailable): static
    {
        return $this->state(fn () => [
            'status' => UploadStatus::Failed,
            'attempts' => 5,
            'failure_code' => $code->value,
            'last_error' => 'recorded for operators',
            'failed_at' => now(),
        ]);
    }
}
