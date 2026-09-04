<?php

use App\Actions\Uploads\StoreUploads;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::post('/uploads', StoreUploads::class)->name('uploads.store');
});
