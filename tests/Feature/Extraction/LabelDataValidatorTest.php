<?php

use App\Enums\FailureCode;
use App\Llm\LabelDataSchema;
use App\Llm\LabelDataValidator;
use App\Llm\LlmPermanentException;

function expectRejected(string $text): void
{
    expect(fn () => (new LabelDataValidator)->validate($text))
        ->toThrow(function (LlmPermanentException $e) {
            expect($e->failureCode)->toBe(FailureCode::LlmInvalidOutput);
        });
}

it('accepts a document that matches the schema (FR-17)', function () {
    $document = validDocument();

    expect((new LabelDataValidator)->validate(json_encode($document)))->toBe($document);
});

it('accepts nulls for every field the document does not state (FR-17)', function () {
    $sparse = validDocument([
        'product_name' => null, 'brand' => null, 'ingredients' => null,
        'allergens' => null, 'net_weight' => null, 'warnings' => [],
    ]);

    expect((new LabelDataValidator)->validate(json_encode($sparse)))->toBe($sparse);
});

describe('output that is not JSON (FR-20)', function () {
    it('rejects prose', function () {
        expectRejected('I could not read that document, sorry.');
    });

    it('rejects truncated JSON', function () {
        expectRejected('{"document_type": "product_label", "product_name": "Batte');
    });

    it('rejects JSON fenced in markdown', function () {
        expectRejected("```json\n".json_encode(validDocument())."\n```");
    });

    it('rejects an empty response', function () {
        expectRejected('');
    });

    it('rejects a JSON array at the top level', function () {
        expectRejected(json_encode([validDocument()]));
    });
});

describe('JSON that is not our JSON (FR-17, FR-20)', function () {
    it('rejects a missing required key', function () {
        $document = validDocument();
        unset($document['net_weight']);

        expectRejected(json_encode($document));
    });

    it('rejects an unknown document_type', function () {
        expectRejected(json_encode(validDocument(['document_type' => 'invoice'])));
    });

    it('rejects a unit outside the allowed set', function () {
        expectRejected(json_encode(validDocument([
            'net_weight' => ['value' => 12, 'unit' => 'stone', 'raw' => '12 stone'],
        ])));
    });

    it('rejects a property we never asked for', function () {
        expectRejected(json_encode(validDocument(['confidence' => 0.9])));
    });

    it('rejects a net weight whose value is a string', function () {
        expectRejected(json_encode(validDocument([
            'net_weight' => ['value' => '800', 'unit' => 'g', 'raw' => '800 g'],
        ])));
    });

    it('rejects allergens missing may_contain', function () {
        expectRejected(json_encode(validDocument(['allergens' => ['contains' => ['Fish']]])));
    });

    it('rejects more warnings than the contract allows', function () {
        expectRejected(json_encode(validDocument([
            'warnings' => array_fill(0, LabelDataSchema::MAX_WARNINGS + 1, 'check this'),
        ])));
    });

    it('rejects a warning longer than the contract allows', function () {
        expectRejected(json_encode(validDocument([
            'warnings' => [str_repeat('a', LabelDataSchema::MAX_WARNING_LENGTH + 1)],
        ])));
    });
});
