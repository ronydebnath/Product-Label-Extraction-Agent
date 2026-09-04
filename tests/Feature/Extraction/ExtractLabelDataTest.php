<?php

use App\Actions\Extraction\ExtractLabelData;
use App\Enums\FailureCode;
use App\Llm\LabelDataSchema;
use App\Llm\LlmPermanentException;
use App\Models\Extraction;
use App\Models\Upload;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;

beforeEach(function () {
    Storage::fake('local');
});

/** An upload whose bytes really are on the fake disk, as they would be after a real request. */
function storedUpload(array $state = []): Upload
{
    $upload = Upload::factory()->create($state);
    Storage::disk('local')->put($upload->storage_path, PdfFixture::pages(2));

    return $upload;
}

it('turns a valid response into an extraction row (FR-17, FR-23)', function () {
    $upload = storedUpload();
    fakeLlm()->returns(validDocument());

    $extraction = ExtractLabelData::run($upload);

    expect($extraction->upload_id)->toBe($upload->id)
        ->and($extraction->content_hash)->toBe($upload->content_hash)
        ->and($extraction->model)->toBe(config('llm.model'))
        ->and($extraction->prompt_version)->toBe(LabelDataSchema::PROMPT_VERSION)
        ->and($extraction->data['product_name'])->toBe('Battered Hoki Fillets')
        ->and($extraction->input_tokens)->toBe(1000)
        ->and($extraction->output_tokens)->toBe(200)
        ->and($extraction->duration_ms)->toBe(42);
});

it('sends the stored bytes, the document type and our schema (FR-16)', function () {
    $upload = storedUpload();
    fakeLlm()->returns(validDocument());

    ExtractLabelData::run($upload);

    $request = fakeLlm()->lastRequest();

    expect($request->contents)->toBe(PdfFixture::pages(2))
        ->and($request->mimeType)->toBe($upload->mime_type)
        ->and($request->filename)->toBe($upload->original_name)
        ->and($request->model)->toBe(config('llm.model'))
        ->and($request->schema)->toBe(LabelDataSchema::schema());
});

it('fails permanently when the file is gone from the disk (FR-20)', function () {
    // The row says the file exists and the volume disagrees. Retrying cannot conjure it back.
    $upload = Upload::factory()->create();

    expect(fn () => ExtractLabelData::run($upload))
        ->toThrow(function (LlmPermanentException $e) {
            expect($e->failureCode)->toBe(FailureCode::FileMissing);
        });

    expect(fakeLlm()->calls())->toBe(0);
});

it('fails with no_label_found rather than storing an empty document (FR-21)', function () {
    $upload = storedUpload();
    fakeLlm()->returns(validDocument(['document_type' => 'other']));

    expect(fn () => ExtractLabelData::run($upload))
        ->toThrow(function (LlmPermanentException $e) {
            expect($e->failureCode)->toBe(FailureCode::NoLabelFound);
        });

    // A row of nulls looks like a successful extraction to everyone downstream. Better to fail.
    expect(Extraction::count())->toBe(0);
});

it('rejects unparsable output as invalid (FR-20)', function () {
    $upload = storedUpload();
    fakeLlm()->returnsRaw('the document appears to be a fish');

    expect(fn () => ExtractLabelData::run($upload))
        ->toThrow(function (LlmPermanentException $e) {
            expect($e->failureCode)->toBe(FailureCode::LlmInvalidOutput);
        });

    expect(Extraction::count())->toBe(0);
});

it('rejects schema-invalid output as invalid (FR-17, FR-20)', function () {
    $upload = storedUpload();
    fakeLlm()->returns(validDocument(['net_weight' => ['value' => 800, 'unit' => 'furlong', 'raw' => 'x']]));

    expect(fn () => ExtractLabelData::run($upload))
        ->toThrow(LlmPermanentException::class);

    expect(Extraction::count())->toBe(0);
});

describe('reuse (FR-14)', function () {
    it('reuses an extraction for identical bytes, model and prompt, without calling the model', function () {
        $first = storedUpload();
        fakeLlm()->returns(validDocument());
        ExtractLabelData::run($first);

        $second = storedUpload(['content_hash' => $first->content_hash]);
        $extraction = ExtractLabelData::run($second);

        // One call in total. The second upload cost nothing.
        expect(fakeLlm()->calls())->toBe(1)
            ->and($extraction->upload_id)->toBe($second->id)
            ->and($extraction->data)->toBe($first->extraction->data)
            ->and(Extraction::count())->toBe(2);
    });

    it('records a reused extraction as having cost nothing', function () {
        $first = storedUpload();
        fakeLlm()->returns(validDocument());
        ExtractLabelData::run($first);

        $extraction = ExtractLabelData::run(storedUpload(['content_hash' => $first->content_hash]));

        expect($extraction->input_tokens)->toBe(0)
            ->and($extraction->output_tokens)->toBe(0)
            ->and($extraction->duration_ms)->toBe(0);
    });

    it('does not reuse across a different model', function () {
        $first = storedUpload();
        fakeLlm()->returns(validDocument());
        ExtractLabelData::run($first);

        config(['llm.model' => 'some-other-model']);
        ExtractLabelData::run(storedUpload(['content_hash' => $first->content_hash]));

        expect(fakeLlm()->calls())->toBe(2);
    });

    it('does not reuse across a different prompt version', function () {
        $upload = storedUpload();
        Extraction::factory()->create([
            'content_hash' => $upload->content_hash,
            'model' => config('llm.model'),
            'prompt_version' => LabelDataSchema::PROMPT_VERSION + 1,
        ]);

        fakeLlm()->returns(validDocument());
        ExtractLabelData::run($upload);

        // The same bytes asked a different question are a different answer.
        expect(fakeLlm()->calls())->toBe(1);
    });
});
