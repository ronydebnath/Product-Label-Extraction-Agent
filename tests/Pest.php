<?php

use App\Llm\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\MimeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeLlmClient;
use Tests\TestCase;

// Feature tests boot the app and run against the real Postgres test database inside the
// container (see phpunit.xml). RefreshDatabase wraps each test in a transaction.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Bound for every feature test, not just the ones that mean to use it: an unbound LlmClient
    // would let a forgotten expectation reach the real API and spend real money.
    ->beforeEach(fn () => app()->instance(LlmClient::class, new FakeLlmClient))
    ->in('Feature');

/** The scripted model bound for the current test. */
function fakeLlm(): FakeLlmClient
{
    return app(LlmClient::class);
}

/**
 * A real UploadedFile wrapping real bytes on disk.
 *
 * Deliberately not `UploadedFile::fake()`: the fake reports a mime type guessed from the filename,
 * which is the exact thing the upload path must ignore. Sniffing can only be tested against a file
 * that actually has contents.
 */
function uploadedBytes(string $bytes, string $as, ?string $clientMimeType = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, $bytes);

    return new UploadedFile(
        $path,
        $as,
        // What a browser would declare, which is to say: whatever the extension implies.
        $clientMimeType ?? MimeType::from($as),
        null,
        test: true,
    );
}

/** The same, with the bytes taken from tests/Fixtures and presented under any name. */
function sampleFile(string $fixture, string $as, ?string $clientMimeType = null): UploadedFile
{
    return uploadedBytes(file_get_contents(base_path("tests/Fixtures/{$fixture}")), $as, $clientMimeType);
}

/** @param  array<int, UploadedFile>  $files */
function postUploads(array $files): TestResponse
{
    return test()->postJson('/uploads', ['files' => $files]);
}

/** A schema-valid extraction document; pass overrides to break exactly one thing. */
function validDocument(array $overrides = []): array
{
    return array_merge([
        'document_type' => 'product_spec_sheet',
        'product_name' => 'Battered Hoki Fillets',
        'brand' => 'Coldwater Bay',
        'ingredients' => ['Fish (Hoki) (58%)', 'Water', 'Wheat Flour'],
        'allergens' => ['contains' => ['Fish', 'Wheat'], 'may_contain' => []],
        'net_weight' => ['value' => 800, 'unit' => 'g', 'raw' => '800 g'],
        'warnings' => ['Allergens inferred from the ingredient list.'],
    ], $overrides);
}
