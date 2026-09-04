<?php

namespace App\Actions\Extraction;

use App\Enums\FailureCode;
use App\Llm\LabelDataSchema;
use App\Llm\LabelDataValidator;
use App\Llm\LlmClient;
use App\Llm\LlmPermanentException;
use App\Llm\LlmRequest;
use App\Models\Extraction;
use App\Models\Upload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * One upload in, one extraction row out. Everything about talking to a model lives here; the job
 * around it deals only with queue mechanics, so this can be tested without a queue at all.
 */
class ExtractLabelData
{
    use AsAction;

    public function __construct(
        private readonly LlmClient $client,
        private readonly LabelDataValidator $validator,
    ) {}

    public function handle(Upload $upload): Extraction
    {
        $reusable = $this->reusableExtraction($upload);

        if ($reusable !== null) {
            return $this->reuse($upload, $reusable);
        }

        $response = $this->client->extract(new LlmRequest(
            model: (string) config('llm.model'),
            prompt: LabelDataSchema::prompt(),
            filename: $upload->original_name,
            mimeType: $upload->mime_type,
            contents: $this->contentsOf($upload),
            schema: LabelDataSchema::schema(),
            schemaName: LabelDataSchema::NAME,
        ));

        $document = $this->validator->validate($response->text);

        // A document the model does not recognise as a label is a failure, not a success with
        // empty fields: a row of nulls is indistinguishable downstream from a real extraction
        // of a blank label, and the user would have no idea their file was the wrong thing.
        if ($document['document_type'] === 'other') {
            throw new LlmPermanentException(
                FailureCode::NoLabelFound,
                "Model classified upload {$upload->id} as neither a label nor a spec sheet.",
            );
        }

        return Extraction::create([
            'upload_id' => $upload->id,
            'content_hash' => $upload->content_hash,
            'model' => (string) config('llm.model'),
            'prompt_version' => LabelDataSchema::PROMPT_VERSION,
            'data' => $document,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'duration_ms' => $response->durationMs,
        ]);
    }

    /**
     * The dedupe key is the content hash plus the model plus the prompt version. The hash is the
     * only identity a client cannot lie about -- filename and size are theirs to choose -- and the
     * other two are there because the same bytes asked a different question are a different answer.
     */
    private function reusableExtraction(Upload $upload): ?Extraction
    {
        return Extraction::query()
            ->where('content_hash', $upload->content_hash)
            ->where('model', (string) config('llm.model'))
            ->where('prompt_version', LabelDataSchema::PROMPT_VERSION)
            ->oldest()
            ->first();
    }

    private function reuse(Upload $upload, Extraction $existing): Extraction
    {
        Log::info('extraction.reused', [
            'upload_id' => $upload->id,
            'reused_from' => $existing->upload_id,
        ]);

        // Zero cost recorded, because that is what it cost. Copying the original's token counts
        // would inflate every usage total by every duplicate anyone ever uploaded.
        return Extraction::create([
            'upload_id' => $upload->id,
            'content_hash' => $existing->content_hash,
            'model' => $existing->model,
            'prompt_version' => $existing->prompt_version,
            'data' => $existing->data,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'duration_ms' => 0,
        ]);
    }

    private function contentsOf(Upload $upload): string
    {
        $disk = Storage::disk((string) config('uploads.disk'));

        // The row promised a file and the disk does not have it. That is an operational problem
        // (a lost volume, a bucket misconfiguration), and no number of retries will fix it.
        if (! $disk->exists($upload->storage_path)) {
            throw new LlmPermanentException(
                FailureCode::FileMissing,
                "Stored file missing for upload {$upload->id}: {$upload->storage_path}",
            );
        }

        return (string) $disk->get($upload->storage_path);
    }
}
