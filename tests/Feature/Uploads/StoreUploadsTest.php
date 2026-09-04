<?php

use App\Enums\FailureCode;
use App\Enums\UploadStatus;
use App\Jobs\ProcessUploadJob;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('rejection', function () {
    it('rejects a text file renamed to .jpg, on sniffed type not extension (FR-4)', function () {
        $response = postUploads([sampleFile('not-an-image.txt', 'label.jpg')]);

        $response->assertStatus(422)
            ->assertJsonPath('rejected.0.original_name', 'label.jpg')
            ->assertJsonPath('rejected.0.code', FailureCode::UnsupportedType->value)
            ->assertJsonPath('rejected.0.message', FailureCode::UnsupportedType->message());

        expect(Upload::count())->toBe(0);
        expect(Storage::disk('local')->allFiles())->toBeEmpty();
        Queue::assertNothingPushed();
    });

    it('rejects an empty file (FR-6)', function () {
        $response = postUploads([uploadedBytes('', 'empty.pdf')]);

        $response->assertStatus(422)->assertJsonPath('rejected.0.code', FailureCode::FileEmpty->value);
        expect(Upload::count())->toBe(0);
    });

    it('rejects a file over the size cap before sniffing its type (FR-5)', function () {
        $megabytes = (int) (config('uploads.max_file_bytes') / 1024 / 1024);

        $response = postUploads([uploadedBytes(str_repeat('a', ($megabytes * 1024 * 1024) + 1), 'huge.pdf')]);

        $response->assertStatus(422)->assertJsonPath('rejected.0.code', FailureCode::FileTooLarge->value);
        expect(Upload::count())->toBe(0);
    });

    it('rejects the whole request when it carries too many files (FR-5)', function () {
        $files = array_map(
            fn (int $i) => sampleFile('1x1.png', "label-{$i}.png"),
            range(1, config('uploads.max_files_per_request') + 1),
        );

        $response = postUploads($files);

        $response->assertStatus(422)->assertJsonPath('code', FailureCode::TooManyFiles->value);

        // Nothing is processed: the cap exists to stop the work, not to report on it afterwards.
        expect(Upload::count())->toBe(0);
        expect(Storage::disk('local')->allFiles())->toBeEmpty();
        Queue::assertNothingPushed();
    });

    it('rejects a PDF that has the right magic bytes but does not parse (FR-6)', function () {
        $response = postUploads([uploadedBytes(PdfFixture::corrupt(), 'broken.pdf')]);

        $response->assertStatus(422)->assertJsonPath('rejected.0.code', FailureCode::CorruptFile->value);
    });

    it('rejects a PNG that sniffs as an image but has no readable header (FR-6)', function () {
        $response = postUploads([sampleFile('corrupt.png', 'broken.png')]);

        $response->assertStatus(422)->assertJsonPath('rejected.0.code', FailureCode::CorruptFile->value);
    });

    it('rejects a PDF with more pages than the cap (FR-5)', function () {
        $pages = config('uploads.max_pdf_pages') + 1;

        $response = postUploads([
            uploadedBytes(PdfFixture::pages($pages), 'long.pdf'),
        ]);

        $response->assertStatus(422)->assertJsonPath('rejected.0.code', FailureCode::TooManyPages->value);
    });

    it('rejects an image over the megapixel cap without decoding it (FR-5)', function () {
        $response = postUploads([sampleFile('6000x6000.png', 'huge.png')]);

        $response->assertStatus(422)->assertJsonPath('rejected.0.code', FailureCode::ImageTooLarge->value);
    });

    it('rejects SVG and HEIC even when the client calls them PNG (FR-4, NFR-2)', function () {
        $svg = uploadedBytes('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'vector.png', 'image/png');
        $heic = uploadedBytes("\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1", 'photo.png', 'image/png');

        $response = postUploads([$svg, $heic]);

        $response->assertStatus(422)
            ->assertJsonPath('rejected.0.code', FailureCode::UnsupportedType->value)
            ->assertJsonPath('rejected.1.code', FailureCode::UnsupportedType->value);

        expect(Upload::count())->toBe(0);
    });

    it('returns 422 and no rows when every file in the batch is invalid (FR-7)', function () {
        $response = postUploads([
            sampleFile('not-an-image.txt', 'one.jpg'),
            uploadedBytes('', 'two.pdf'),
        ]);

        $response->assertStatus(422)
            ->assertJsonCount(0, 'accepted')
            ->assertJsonCount(2, 'rejected');

        expect(Upload::count())->toBe(0);
        Queue::assertNothingPushed();
    });
});

describe('acceptance', function () {
    it('accepts every supported format and records what it sniffed (FR-4, FR-8, FR-9)', function () {
        $response = postUploads([
            sampleFile('1x1.jpg', 'a.jpg'),
            sampleFile('1x1.png', 'b.png'),
            sampleFile('1x1.webp', 'c.webp'),
            uploadedBytes(PdfFixture::pages(3), 'd.pdf'),
        ]);

        $response->assertStatus(201)->assertJsonCount(4, 'accepted')->assertJsonCount(0, 'rejected');

        expect(Upload::count())->toBe(4);

        $pdf = Upload::where('original_name', 'd.pdf')->sole();
        expect($pdf->mime_type)->toBe('application/pdf')
            ->and($pdf->kind)->toBe('pdf')
            ->and($pdf->page_count)->toBe(3)
            ->and($pdf->status)->toBe(UploadStatus::Queued)
            ->and($pdf->attempts)->toBe(0)
            ->and($pdf->user_id)->toBe($this->user->id)
            ->and($pdf->content_hash)->toBe(hash('sha256', PdfFixture::pages(3)));

        // Stored under a uuid path on the private disk, never under the client's filename.
        expect($pdf->storage_path)->toStartWith('uploads/')
            ->and($pdf->storage_path)->not->toContain('d.pdf');
        Storage::disk('local')->assertExists($pdf->storage_path);

        $png = Upload::where('original_name', 'b.png')->sole();
        expect($png->mime_type)->toBe('image/png')->and($png->kind)->toBe('image')
            ->and($png->page_count)->toBeNull();
    });

    it('accepts a PDF exactly at the page cap (FR-5)', function () {
        $pages = config('uploads.max_pdf_pages');

        postUploads([uploadedBytes(PdfFixture::pages($pages), 'cap.pdf')])
            ->assertStatus(201);

        expect(Upload::sole()->page_count)->toBe($pages);
    });

    it('dispatches exactly one job per accepted file, carrying only the id (FR-9, NFR-4)', function () {
        postUploads([sampleFile('1x1.png', 'a.png'), sampleFile('1x1.jpg', 'b.jpg')])->assertStatus(201);

        Queue::assertPushed(ProcessUploadJob::class, 2);

        $ids = Upload::pluck('id')->all();
        Queue::assertPushed(ProcessUploadJob::class, fn ($job) => in_array($job->uploadId, $ids, true));
    });

    it('accepts the valid files in a mixed batch and reports the rest (FR-7)', function () {
        $response = postUploads([
            sampleFile('1x1.png', 'good.png'),
            sampleFile('not-an-image.txt', 'bad.jpg'),
            uploadedBytes(PdfFixture::pages(1), 'good.pdf'),
        ]);

        $response->assertStatus(201)
            ->assertJsonCount(2, 'accepted')
            ->assertJsonCount(1, 'rejected')
            ->assertJsonPath('rejected.0.original_name', 'bad.jpg');

        expect(Upload::count())->toBe(2);
        Queue::assertPushed(ProcessUploadJob::class, 2);
    });

    it('stores identical bytes uploaded twice as two rows sharing one content hash (FR-14)', function () {
        postUploads([sampleFile('1x1.png', 'first.png')])->assertStatus(201);
        postUploads([sampleFile('1x1.png', 'second.png')])->assertStatus(201);

        // Two rows, because two people asked. One hash, so Stage 3 can reuse the extraction
        // instead of paying the LLM twice.
        expect(Upload::count())->toBe(2)
            ->and(Upload::distinct()->pluck('content_hash'))->toHaveCount(1);
    });
});

describe('queue failure', function () {
    it('persists the row as failed when the queue cannot be reached (FR-10)', function () {
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('Connection refused [tcp://redis:6379]'));

        $response = postUploads([sampleFile('1x1.png', 'a.png')]);

        // The user is told, and the row is terminal rather than sitting in `queued` forever
        // waiting for a job that was never enqueued.
        $response->assertStatus(201)->assertJsonPath('accepted.0.status', 'failed');

        $upload = Upload::sole();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_code)->toBe(FailureCode::QueueUnavailable->value)
            ->and($upload->failed_at)->not->toBeNull();

        // The internal detail is kept for operators and never sent to the browser.
        expect($upload->last_error)->toContain('Connection refused');
        $response->assertDontSee('Connection refused');
    });
});

describe('access control', function () {
    it('refuses uploads from guests (FR-2)', function () {
        auth()->logout();

        postUploads([sampleFile('1x1.png', 'a.png')])->assertStatus(401);

        expect(Upload::count())->toBe(0);
    });

    it('records the upload against the authenticated user (FR-2)', function () {
        postUploads([sampleFile('1x1.png', 'a.png')])->assertStatus(201);

        expect(Upload::sole()->user_id)->toBe($this->user->id);
    });
});
