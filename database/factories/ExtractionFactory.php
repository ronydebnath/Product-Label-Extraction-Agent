<?php

namespace Database\Factories;

use App\Llm\LabelDataSchema;
use App\Models\Extraction;
use App\Models\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Extraction> */
class ExtractionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'upload_id' => Upload::factory(),
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'model' => config('llm.model'),
            'prompt_version' => LabelDataSchema::PROMPT_VERSION,
            'data' => [
                'document_type' => 'product_spec_sheet',
                'product_name' => fake()->words(3, true),
                'brand' => fake()->company(),
                'ingredients' => ['Water', 'Salt'],
                'allergens' => ['contains' => [], 'may_contain' => []],
                'net_weight' => ['value' => 500, 'unit' => 'g', 'raw' => '500 g'],
                'warnings' => [],
            ],
            'input_tokens' => 3000,
            'output_tokens' => 200,
            'duration_ms' => 3400,
        ];
    }
}
