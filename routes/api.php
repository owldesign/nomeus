<?php

use App\Http\Controllers\Api\PhpController;
use App\Http\Controllers\Api\SitesController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\TasksController;
use Illuminate\Support\Facades\Route;

Route::get('/status', [StatusController::class, 'show'])->name('api.status');

Route::get('/sites', [SitesController::class, 'index'])->name('api.sites.index');
Route::post('/sites/link', [SitesController::class, 'link'])->name('api.sites.link');
Route::get('/sites/{name}', [SitesController::class, 'show'])->name('api.sites.show');
Route::post('/sites/{name}/secure', [SitesController::class, 'secure'])->name('api.sites.secure');
Route::post('/sites/{name}/unsecure', [SitesController::class, 'unsecure'])->name('api.sites.unsecure');
Route::post('/sites/{name}/isolate', [SitesController::class, 'isolate'])->name('api.sites.isolate');
Route::post('/sites/{name}/unisolate', [SitesController::class, 'unisolate'])->name('api.sites.unisolate');
Route::delete('/sites/{name}/link', [SitesController::class, 'unlink'])->name('api.sites.unlink');

Route::get('/tasks', [TasksController::class, 'index'])->name('api.tasks.index');
Route::get('/tasks/{id}', [TasksController::class, 'show'])->name('api.tasks.show');

Route::get('/php', [PhpController::class, 'index'])->name('api.php.index');
Route::post('/php/{version}/use', [PhpController::class, 'use'])->where('version', '\d+\.\d+')->name('api.php.use');
Route::post('/php/{version}/install', [PhpController::class, 'install'])->where('version', '\d+\.\d+')->name('api.php.install');
Route::post('/php/{version}/update', [PhpController::class, 'update'])->where('version', '\d+\.\d+')->name('api.php.update');
