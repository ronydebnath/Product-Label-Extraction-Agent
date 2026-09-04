<?php

use App\Enums\FailureCode;
use App\Models\Extraction;
use App\Models\Upload;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('guests (FR-2)', function () {
    it('sends a guest to the login page rather than the list', function () {
        $this->get('/uploads')->assertRedirect('/login');
    });

    it('answers the polling endpoint with 401 instead of a redirect', function () {
        // The poller is JavaScript. A 302 to an HTML login page would be parsed as a status
        // payload and fail confusingly; 401 lets the client say "your session expired".
        $this->getJson('/api/uploads?ids=whatever')->assertStatus(401);
    });

    it('sends a guest away from a detail page', function () {
        $upload = Upload::factory()->create();

        $this->get("/uploads/{$upload->id}")->assertRedirect('/login');
    });
});

describe('the list (FR-24)', function () {
    it('renders the index page with the user uploads, newest first', function () {
        $older = Upload::factory()->for($this->user)->create(['created_at' => now()->subHour()]);
        $newer = Upload::factory()->for($this->user)->create(['created_at' => now()]);

        $this->actingAs($this->user)->get('/uploads')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Uploads/Index')
                ->has('uploads', 2)
                ->where('uploads.0.id', $newer->id)
                ->where('uploads.1.id', $older->id));
    });

    it('shows nobody else uploads', function () {
        Upload::factory()->for($this->user)->create();
        Upload::factory()->create();

        $this->actingAs($this->user)->get('/uploads')
            ->assertInertia(fn (Assert $page) => $page->has('uploads', 1));
    });

    it('renders an empty list rather than failing when there is nothing yet (FR-28)', function () {
        $this->actingAs($this->user)->get('/uploads')
            ->assertInertia(fn (Assert $page) => $page->has('uploads', 0));
    });

    it('passes the upload limits so the UI quotes the same numbers we enforce (FR-5)', function () {
        $this->actingAs($this->user)->get('/uploads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('limits.max_files', config('uploads.max_files_per_request'))
                ->where('limits.max_file_bytes', config('uploads.max_file_bytes'))
                ->has('limits.accepted_extensions'));
    });
});

describe('the detail page (FR-27)', function () {
    it('shows the extracted data for a completed upload', function () {
        $upload = Upload::factory()->for($this->user)->completed()->create();
        $extraction = Extraction::factory()->for($upload)->create();

        $this->actingAs($this->user)->get("/uploads/{$upload->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Uploads/Show')
                ->where('upload.id', $upload->id)
                ->where('extraction.data.document_type', $extraction->data['document_type'])
                ->where('extraction.model', $extraction->model)
                ->has('extraction.duration_ms'));
    });

    it('shows the human reason for a failed upload and never the internal one (FR-29)', function () {
        $upload = Upload::factory()->for($this->user)->failed(FailureCode::LlmInvalidOutput)->create([
            'last_error' => 'Opis\\JsonSchema violation at /net_weight/unit',
        ]);

        $response = $this->actingAs($this->user)->get("/uploads/{$upload->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('upload.message', FailureCode::LlmInvalidOutput->message())
            ->missing('upload.last_error'));

        $response->assertDontSee('Opis', escape: false);
    });

    it('has no extraction for an upload that has not finished', function () {
        $upload = Upload::factory()->for($this->user)->create();

        $this->actingAs($this->user)->get("/uploads/{$upload->id}")
            ->assertInertia(fn (Assert $page) => $page->where('extraction', null));
    });

    it('returns 404 for another user upload rather than admitting it exists (FR-2)', function () {
        $other = Upload::factory()->create();

        $this->actingAs($this->user)->get("/uploads/{$other->id}")->assertNotFound();
    });

    it('returns 404 for an id that is not a uuid at all', function () {
        $this->actingAs($this->user)->get('/uploads/not-a-uuid')->assertNotFound();
    });
});

describe('the polling endpoint (FR-26, FR-29)', function () {
    it('returns status, attempts and the human message for the given ids', function () {
        $queued = Upload::factory()->for($this->user)->create(['attempts' => 2]);
        $failed = Upload::factory()->for($this->user)->failed(FailureCode::LlmUnavailable)->create();

        $this->actingAs($this->user)
            ->getJson('/api/uploads?ids='.implode(',', [$queued->id, $failed->id]))
            ->assertOk()
            ->assertJsonCount(2, 'uploads')
            ->assertJsonPath('uploads.0.status', 'queued')
            ->assertJsonPath('uploads.0.attempts', 2)
            ->assertJsonPath('uploads.0.message', null)
            ->assertJsonPath('uploads.1.status', 'failed')
            ->assertJsonPath('uploads.1.message', FailureCode::LlmUnavailable->message());
    });

    it('never returns the internal error text', function () {
        $upload = Upload::factory()->for($this->user)->failed()->create([
            'last_error' => 'SQLSTATE[08006] connection to server at "postgres" failed',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/uploads?ids={$upload->id}");

        $response->assertOk()->assertJsonMissingPath('uploads.0.last_error');
        expect($response->getContent())->not->toContain('SQLSTATE');
    });

    it('silently omits ids belonging to somebody else', function () {
        $mine = Upload::factory()->for($this->user)->create();
        $theirs = Upload::factory()->create();

        $this->actingAs($this->user)
            ->getJson('/api/uploads?ids='.implode(',', [$mine->id, $theirs->id]))
            ->assertOk()
            ->assertJsonCount(1, 'uploads')
            ->assertJsonPath('uploads.0.id', $mine->id);
    });

    it('answers an empty ids list with an empty result, not an error', function () {
        $this->actingAs($this->user)->getJson('/api/uploads?ids=')->assertOk()->assertJsonCount(0, 'uploads');
    });

    it('ignores junk ids without a 500', function () {
        $this->actingAs($this->user)->getJson('/api/uploads?ids=not-a-uuid,also-junk')
            ->assertOk()
            ->assertJsonCount(0, 'uploads');
    });
});
