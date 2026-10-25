<?php

use App\Livewire\StudioDashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth:web')->group(function () {
    Route::get('/studio', StudioDashboard::class)->name('studio');
});
