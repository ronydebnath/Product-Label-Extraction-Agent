<?php

namespace App\Providers;

use App\Llm\LlmClient;
use App\Llm\OpenAiResponsesClient;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The one place the application is told which model vendor it is talking to. Tests bind a
        // fake over this in tests/Pest.php, so nothing else in the codebase names OpenAI.
        $this->app->bind(LlmClient::class, OpenAiResponsesClient::class);
    }

    public function boot(): void
    {
        // Resources are used as Inertia page props as well as JSON bodies, and a "data" wrapper
        // would mean the two shapes disagree. One shape everywhere is easier to type on the
        // client and easier to assert on in tests.
        JsonResource::withoutWrapping();
    }
}
