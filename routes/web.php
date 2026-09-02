<?php

use Illuminate\Support\Facades\Route;

// SPA shell. Anything not under /api or /up is client-side routed.
Route::view('/{any?}', 'app')
    ->where('any', '(?!api(/|$)|up(/|$)).*')
    ->name('spa');
