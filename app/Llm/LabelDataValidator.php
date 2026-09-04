<?php

namespace App\Llm;

use App\Enums\FailureCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Turns whatever the model said into either a valid document or a permanent failure.
 *
 * Both steps are first-class failures rather than exceptions to be swallowed: text that is not
 * JSON, and JSON that is not our JSON. There is no repair-retry -- see DECISIONS.md.
 */
class LabelDataValidator
{
    /** @return array<string, mixed> the validated document */
    public function validate(string $text): array
    {
        $decoded = $this->decode($text);

        $result = (new Validator)->validate(
            // The validator wants stdClass, and the schema forbids additional properties, so the
            // round-trip through json_decode without associative arrays is the honest input.
            json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), false),
            json_encode(LabelDataSchema::schema(), JSON_THROW_ON_ERROR),
        );

        if (! $result->isValid()) {
            $error = $result->error();

            $this->reject('schema', $text, $error === null
                ? 'unknown schema violation'
                : json_encode((new ErrorFormatter)->format($error), JSON_THROW_ON_ERROR));
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function decode(string $text): array
    {
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->reject('parse', $text, $e->getMessage());
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->reject('parse', $text, 'top level value is not a JSON object');
        }

        return $decoded;
    }

    /**
     * The excerpt is capped because the whole point of logging it is to make a malformed response
     * debuggable, not to copy an unbounded reply from an external service into our log store.
     */
    private function reject(string $stage, string $text, string $detail): never
    {
        Log::warning('llm.invalid_output', [
            'stage' => $stage,
            'detail' => Str::limit($detail, 500),
            'excerpt' => Str::limit($text, 2000),
        ]);

        throw new LlmPermanentException(
            FailureCode::LlmInvalidOutput,
            "LLM output rejected at {$stage}: {$detail}",
        );
    }
}
