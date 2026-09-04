<?php

namespace App\Llm;

/**
 * The contract with the model: the instruction it is given and the shape it must answer in.
 *
 * The same array is sent as the `json_schema` and used to validate what comes back. Strict mode
 * makes the model try to comply; validating again server-side is what makes non-compliance a
 * handled case rather than a corrupted row. A schema used for only one of the two would drift.
 */
final class LabelDataSchema
{
    /**
     * Part of the dedupe key, alongside the content hash and the model. Bump it whenever the
     * prompt or the schema changes: the same bytes asked a different question are a different
     * answer, and reusing the old one would be wrong.
     */
    public const int PROMPT_VERSION = 1;

    public const string NAME = 'label_data';

    public const int MAX_WARNINGS = 5;

    public const int MAX_WARNING_LENGTH = 200;

    public static function prompt(): string
    {
        // Written as rules rather than prose because each line exists to stop a specific mistake
        // seen in the sample documents or in a real call.
        return <<<'PROMPT'
        Extract the product data from this product label or specification sheet.

        Rules:
        - Report only what the document states. Anything not on the document is null, never an
          empty string and never a guess.
        - document_type is "other" if this is not a product label or product specification sheet.
        - ingredients: one entry per ingredient, in the order listed, keeping percentages and
          bracketed detail as printed.
        - allergens.contains: allergens the document declares to be present.
        - allergens.may_contain: ONLY allergens from an explicit "may contain", "may be present"
          or "traces of" statement. If there is no such statement it is an empty array. Never
          copy allergens.contains into allergens.may_contain.
        - If the allergen statement is absent, blank, or marked as not completed, infer the
          allergens from the ingredient list and add a warning saying they were inferred.
        - net_weight is the net content of a single retail unit, never an outer carton, case or
          shipper. raw is the weight exactly as printed on the document.
        - warnings: anything a human reviewer should check, such as inferred values, unreadable
          text, or conflicting figures.
        PROMPT;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'document_type', 'product_name', 'brand',
                'ingredients', 'allergens', 'net_weight', 'warnings',
            ],
            'properties' => [
                'document_type' => [
                    'type' => 'string',
                    'enum' => ['product_label', 'product_spec_sheet', 'other'],
                ],
                'product_name' => $nullableString,
                'brand' => $nullableString,
                'ingredients' => [
                    'type' => ['array', 'null'],
                    'items' => ['type' => 'string'],
                ],
                'allergens' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'required' => ['contains', 'may_contain'],
                    'properties' => [
                        'contains' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'may_contain' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'net_weight' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'required' => ['value', 'unit', 'raw'],
                    'properties' => [
                        'value' => ['type' => 'number'],
                        'unit' => ['type' => 'string', 'enum' => ['g', 'kg', 'ml', 'l', 'oz', 'lb']],
                        'raw' => ['type' => 'string'],
                    ],
                ],
                'warnings' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_WARNINGS,
                    'items' => ['type' => 'string', 'maxLength' => self::MAX_WARNING_LENGTH],
                ],
            ],
        ];
    }
}
