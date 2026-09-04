<?php

use App\Actions\Uploads\ListUploads;
use App\Actions\Uploads\ShowUpload;
use App\Actions\Uploads\StoreUploads;
use App\Actions\Uploads\UploadStatuses;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/uploads');

Route::middleware('auth')->group(function () {
    Route::get('/uploads', ListUploads::class)->name('uploads.index');
    Route::post('/uploads', StoreUploads::class)->name('uploads.store');
    // whereUuid keeps a malformed id from reaching the database at all: it simply does not match
    // the route, which is a 404 for free.
    Route::get('/uploads/{upload}', ShowUpload::class)->whereUuid('upload')->name('uploads.show');

    // Polled by the browser, so it must answer 401 rather than redirect when a session expires.
    Route::get('/api/uploads', UploadStatuses::class)->name('uploads.statuses');
});
