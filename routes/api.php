<?php

use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\DumpsController;
use App\Http\Controllers\Api\LogsController;
use App\Http\Controllers\Api\MailController;
use App\Http\Controllers\Api\PhpController;
use App\Http\Controllers\Api\ServicesController;
use App\Http\Controllers\Api\SitesController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\TasksController;
use App\Http\Controllers\Api\XdebugController;
use Illuminate\Support\Facades\Route;

Route::get('/status', [StatusController::class, 'show'])->name('api.status');

Route::get('/sites', [SitesController::class, 'index'])->name('api.sites.index');
Route::post('/sites/link', [SitesController::class, 'link'])->name('api.sites.link');
Route::get('/sites/{name}', [SitesController::class, 'show'])->name('api.sites.show');
Route::post('/sites/{name}/secure', [SitesController::class, 'secure'])->name('api.sites.secure');
Route::post('/sites/{name}/unsecure', [SitesController::class, 'unsecure'])->name('api.sites.unsecure');
Route::post('/sites/{name}/isolate', [SitesController::class, 'isolate'])->name('api.sites.isolate');
Route::post('/sites/{name}/unisolate', [SitesController::class, 'unisolate'])->name('api.sites.unisolate');
Route::post('/sites/{name}/init', [SitesController::class, 'init'])->name('api.sites.init');
Route::delete('/sites/{name}/link', [SitesController::class, 'unlink'])->name('api.sites.unlink');

Route::get('/tasks', [TasksController::class, 'index'])->name('api.tasks.index');
Route::get('/tasks/{id}', [TasksController::class, 'show'])->name('api.tasks.show');

Route::get('/php', [PhpController::class, 'index'])->name('api.php.index');
Route::post('/php/{version}/use', [PhpController::class, 'use'])->where('version', '\d+\.\d+')->name('api.php.use');
Route::post('/php/{version}/install', [PhpController::class, 'install'])->where('version', '\d+\.\d+')->name('api.php.install');
Route::post('/php/{version}/update', [PhpController::class, 'update'])->where('version', '\d+\.\d+')->name('api.php.update');

Route::get('/services', [ServicesController::class, 'index'])->name('api.services.index');
Route::get('/services/types', [ServicesController::class, 'types'])->name('api.services.types');
Route::get('/services/adoptable', [ServicesController::class, 'adoptable'])->name('api.services.adoptable');
Route::post('/services/adopt', [ServicesController::class, 'adopt'])->name('api.services.adopt');
Route::post('/services', [ServicesController::class, 'store'])->name('api.services.store');
Route::get('/services/{name}', [ServicesController::class, 'show'])->name('api.services.show');
Route::post('/services/{name}/start', [ServicesController::class, 'start'])->name('api.services.start');
Route::post('/services/{name}/stop', [ServicesController::class, 'stop'])->name('api.services.stop');
Route::post('/services/{name}/restart', [ServicesController::class, 'restart'])->name('api.services.restart');
Route::post('/services/{name}/clone', [ServicesController::class, 'clone'])->name('api.services.clone');
Route::delete('/services/{name}', [ServicesController::class, 'destroy'])->name('api.services.destroy');

Route::get('/mail/status', [MailController::class, 'status'])->name('api.mail.status');
Route::get('/mail/tags', [MailController::class, 'tags'])->name('api.mail.tags');
Route::get('/mail/messages', [MailController::class, 'messages'])->name('api.mail.messages');
Route::get('/mail/messages/{id}', [MailController::class, 'message'])->name('api.mail.message');
Route::delete('/mail/messages', [MailController::class, 'destroy'])->name('api.mail.destroy');

Route::get('/logs/sources', [LogsController::class, 'sources'])->name('api.logs.sources');
Route::get('/logs/tail', [LogsController::class, 'tail'])->name('api.logs.tail');
Route::delete('/logs', [LogsController::class, 'truncate'])->name('api.logs.truncate');

Route::get('/dumps/status', [DumpsController::class, 'status'])->name('api.dumps.status');
Route::get('/dumps/requests', [DumpsController::class, 'requests'])->name('api.dumps.requests');
Route::get('/dumps/header', [DumpsController::class, 'header'])->name('api.dumps.header');
Route::get('/dumps', [DumpsController::class, 'index'])->name('api.dumps.index');
Route::post('/dumps/capture', [DumpsController::class, 'capture'])->name('api.dumps.capture');
Route::delete('/dumps', [DumpsController::class, 'destroy'])->name('api.dumps.destroy');

Route::get('/xdebug', [XdebugController::class, 'index'])->name('api.xdebug.index');
Route::post('/xdebug/install', [XdebugController::class, 'install'])->name('api.xdebug.install');
Route::post('/xdebug/mode', [XdebugController::class, 'mode'])->name('api.xdebug.mode');

Route::get('/doctor', [DoctorController::class, 'index'])->name('api.doctor');
Route::post('/update', [DoctorController::class, 'update'])->name('api.update');
