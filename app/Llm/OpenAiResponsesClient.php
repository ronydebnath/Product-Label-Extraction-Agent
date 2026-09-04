<?php

namespace App\Llm;

use App\Enums\FailureCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The real client, talking to the OpenAI Responses API.
 *
 * PDFs are sent whole as `input_file`: OpenAI rasterises them, so every page is seen in a single
 * request and this image needs no PDF tooling beyond the page count check at upload time.
 *
 * It never retries. Retrying belongs to the queue, which knows the attempt number, can back off
 * across process restarts, and records what happened on the row.
 */
class OpenAiResponsesClient implements LlmClient
{
    public function extract(LlmRequest $request): LlmResponse
    {
        $startedAt = microtime(true);

        try {
            $response = Http::baseUrl(config('llm.base_url'))
                ->withToken((string) config('llm.api_key'))
                ->connectTimeout((int) config('llm.connect_timeout'))
                ->timeout((int) config('llm.timeout'))
                ->asJson()
                ->post('/responses', $this->payload($request));
        } catch (ConnectionException $e) {
            // DNS failure, refused connection, or our own timeout tripping. All say "later".
            throw new LlmTransientException("LLM connection failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            $this->throwForStatus($response);
        }

        return new LlmResponse(
            text: $this->textFrom($response),
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /** @return array<string, mixed> */
    private function payload(LlmRequest $request): array
    {
        $encoded = base64_encode($request->contents);

        // Images and PDFs take different content types. Both go inline as data URIs rather than
        // through the Files API: the document is used once, so uploading it first would add a
        // round trip, a second failure mode, and something to clean up.
        $document = $request->isPdf()
            ? ['type' => 'input_file', 'filename' => $request->filename, 'file_data' => "data:{$request->mimeType};base64,{$encoded}"]
            : ['type' => 'input_image', 'image_url' => "data:{$request->mimeType};base64,{$encoded}"];

        return [
            'model' => $request->model,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $request->prompt],
                    $document,
                ],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $request->schemaName,
                    'strict' => true,
                    'schema' => $request->schema,
                ],
            ],
        ];
    }

    /**
     * The split that decides whether the user waits or gets an answer now. Retryable means the
     * request was fine and the service was not; permanent means sending it again buys nothing.
     */
    private function throwForStatus(Response $response): never
    {
        $status = $response->status();
        $detail = (string) ($response->json('error.message') ?? $response->body());

        if ($status === 429 || $status === 408 || $status >= 500) {
            $retryAfter = $response->header('Retry-After');

            throw new LlmTransientException(
                "LLM returned {$status}: {$detail}",
                retryAfterSeconds: is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        // 400, 401, 403, 404, 413, 422 and anything else in the 4xx range: a bad key, a model the
        // project cannot reach, a document too large for the API. All of these repeat forever.
        throw new LlmPermanentException(
            FailureCode::LlmRejectedRequest,
            "LLM rejected the request with {$status}: {$detail}",
        );
    }

    /** Pulls the model's answer out of the response, treating anything else as unusable. */
    private function textFrom(Response $response): string
    {
        // A truncated answer is not a transient failure. Sending the identical request again
        // produces the identical truncation, so it is reported as unusable output instead.
        if ($response->json('status') !== 'completed') {
            $reason = (string) ($response->json('incomplete_details.reason') ?? 'unknown');

            throw new LlmPermanentException(
                FailureCode::LlmInvalidOutput,
                "LLM response was not completed: {$reason}",
            );
        }

        $text = '';

        foreach ((array) $response->json('output', []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new LlmPermanentException(
                        FailureCode::LlmInvalidOutput,
                        'LLM refused the request: '.($content['refusal'] ?? ''),
                    );
                }

                if (($content['type'] ?? null) === 'output_text') {
                    $text .= (string) $content['text'];
                }
            }
        }

        if ($text === '') {
            throw new LlmPermanentException(FailureCode::LlmInvalidOutput, 'LLM returned no text.');
        }

        return $text;
    }
}
