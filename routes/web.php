<?php

use App\Livewire\StudioDashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth:web')->group(function () {
    Route::get('/studio', StudioDashboard::class)->name('studio');

    Route::get('/media/video-out/{filename}', function (string $filename) {
        $path = storage_path('app/video_out/'.basename($filename));
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'video/mp4',
        ]);
    })->name('media.video_out');
});
