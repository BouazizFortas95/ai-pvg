<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth:web')->group(function () {
    Route::get('/studio', \App\Livewire\StudioDashboard::class)->name('studio');
});
