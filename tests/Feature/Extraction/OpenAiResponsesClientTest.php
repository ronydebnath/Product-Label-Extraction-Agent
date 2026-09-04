<?php

use App\Enums\FailureCode;
use App\Llm\LabelDataSchema;
use App\Llm\LlmPermanentException;
use App\Llm\LlmRequest;
use App\Llm\LlmTransientException;
use App\Llm\OpenAiResponsesClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function llmRequest(string $mimeType = 'application/pdf'): LlmRequest
{
    return new LlmRequest(
        model: 'gpt-5.4-mini',
        prompt: LabelDataSchema::prompt(),
        filename: 'spec.pdf',
        mimeType: $mimeType,
        contents: 'PDF-BYTES',
        schema: LabelDataSchema::schema(),
        schemaName: LabelDataSchema::NAME,
    );
}

function okBody(string $text = '{"document_type":"other"}'): array
{
    return [
        'status' => 'completed',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]],
        'usage' => ['input_tokens' => 3050, 'output_tokens' => 186],
    ];
}

describe('transient failures (FR-19)', function () {
    it('maps 429 to transient and honours Retry-After', function () {
        Http::fake(fn () => Http::response(['error' => ['message' => 'slow down']], 429, ['Retry-After' => '17']));

        expect(fn () => app(OpenAiResponsesClient::class)->extract(llmRequest()))
            ->toThrow(function (LlmTransientException $e) {
                expect($e->retryAfterSeconds)->toBe(17);
            });
    });

    it('maps 429 without a Retry-After header to transient with no hint', function () {
        Http::fake(fn () => Http::response([], 429));

        expect(fn () => app(OpenAiResponsesClient::class)->extract(llmRequest()))
            ->toThrow(function (LlmTransientException $e) {
                expect($e->retryAfterSeconds)->toBeNull();
            });
    });

    it('maps server errors and timeouts to transient', function (int $status) {
        Http::fake(fn () => Http::response([], $status));

        expect(fn () => app(OpenAiResponsesClient::class)->extract(llmRequest()))
            ->toThrow(LlmTransientException::class);
    })->with([408, 500, 502, 503, 504]);

    it('maps a connection failure to transient', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        expect(fn () => app(OpenAiResponsesClient::class)->extract(llmRequest()))
            ->toThrow(LlmTransientException::class);
    });
});

describe('permanent failures (FR-20)', function () {
    it('maps request-level rejections to a permanent failure', function (int $status) {
        Http::fake(fn () => Http::response(['error' => ['message' => 'nope']], $status));

        expect(fn () => app(OpenAiResponsesClient::class)->extract(llmRequest()))
            ->toThrow(function (LlmPermanentException $e) {
                expect($e->failureCode)->toBe(FailureCode::LlmRejectedRequest);
            });
    })->with([400, 401, 403, 404, 413, 422]);

    it('treats a truncated response as invalid output, not as something to retry', function () {
        Http::fake(fn () => Http::response([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [],
        ]));

        expect(fn () => app(OpenAiResponsesClient::class)->extract(llmRequest()))
            ->toThrow(function (LlmPermanentException $e) {
                expect($e->failureCode)->toBe(FailureCode::LlmInvalidOutput);
            });
    });

    it('treats a refusal as invalid output', function () {
        Http::fake(fn () => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that.']]]],
        ]));

        expect(fn () => app(OpenAiResponsesClient::class)->extract(llmRequest()))
            ->toThrow(function (LlmPermanentException $e) {
                expect($e->failureCode)->toBe(FailureCode::LlmInvalidOutput);
            });
    });

    it('treats a completed response with no text at all as invalid output', function () {
        Http::fake(fn () => Http::response(['status' => 'completed', 'output' => []]));

        expect(fn () => app(OpenAiResponsesClient::class)->extract(llmRequest()))
            ->toThrow(LlmPermanentException::class);
    });
});

describe('the request it builds (FR-16, FR-17)', function () {
    it('sends a PDF as a file so the model sees every page', function () {
        Http::fake(fn () => Http::response(okBody()));

        app(OpenAiResponsesClient::class)->extract(llmRequest('application/pdf'));

        Http::assertSent(function ($request) {
            $content = $request['input'][0]['content'];

            expect($content[0]['type'])->toBe('input_text')
                ->and($content[1]['type'])->toBe('input_file')
                ->and($content[1]['filename'])->toBe('spec.pdf')
                ->and($content[1]['file_data'])->toBe('data:application/pdf;base64,'.base64_encode('PDF-BYTES'));

            return true;
        });
    });

    it('sends an image as an image', function () {
        Http::fake(fn () => Http::response(okBody()));

        app(OpenAiResponsesClient::class)->extract(llmRequest('image/png'));

        Http::assertSent(function ($request) {
            $content = $request['input'][0]['content'];

            expect($content[1]['type'])->toBe('input_image')
                ->and($content[1]['image_url'])->toBe('data:image/png;base64,'.base64_encode('PDF-BYTES'));

            return true;
        });
    });

    it('asks for strict structured output against our schema', function () {
        Http::fake(fn () => Http::response(okBody()));

        app(OpenAiResponsesClient::class)->extract(llmRequest());

        Http::assertSent(function ($request) {
            expect($request['model'])->toBe('gpt-5.4-mini')
                ->and($request['text']['format']['type'])->toBe('json_schema')
                ->and($request['text']['format']['strict'])->toBeTrue()
                ->and($request['text']['format']['name'])->toBe(LabelDataSchema::NAME)
                ->and($request['text']['format']['schema'])->toBe(LabelDataSchema::schema());

            return true;
        });
    });

    it('never retries internally: one call means one HTTP request (NFR-3)', function () {
        Http::fake(fn () => Http::response([], 503));

        try {
            app(OpenAiResponsesClient::class)->extract(llmRequest());
        } catch (LlmTransientException) {
            // expected
        }

        // Retrying is the queue's job. A client that retries on its own makes the row's attempt
        // count a lie and multiplies the real timeout by however many attempts it hid.
        Http::assertSentCount(1);
    });
});

describe('a good response (FR-23)', function () {
    it('returns the text and the token usage', function () {
        Http::fake(fn () => Http::response(okBody('{"document_type":"product_label"}')));

        $response = app(OpenAiResponsesClient::class)->extract(llmRequest());

        expect($response->text)->toBe('{"document_type":"product_label"}')
            ->and($response->inputTokens)->toBe(3050)
            ->and($response->outputTokens)->toBe(186)
            ->and($response->durationMs)->toBeGreaterThanOrEqual(0);
    });

    it('joins text across multiple output items', function () {
        Http::fake(fn () => Http::response([
            'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'content' => []],
                ['type' => 'message', 'content' => [
                    ['type' => 'output_text', 'text' => '{"a":'],
                    ['type' => 'output_text', 'text' => '1}'],
                ]],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
        ]));

        expect(app(OpenAiResponsesClient::class)->extract(llmRequest())->text)->toBe('{"a":1}');
    });
});
