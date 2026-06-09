<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Admin UI for Settings and Domain management
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('settings', [App\Http\Controllers\Admin\SettingController::class, 'index'])->name('settings.index');
    Route::post('settings', [App\Http\Controllers\Admin\SettingController::class, 'store'])->name('settings.store');

    Route::get('domains', [App\Http\Controllers\Admin\DomainController::class, 'index'])->name('domains.index');
    Route::get('domains/api', [App\Http\Controllers\Admin\DomainController::class, 'apiIndex'])->name('domains.api');
    Route::get('domains/create', [App\Http\Controllers\Admin\DomainController::class, 'create'])->name('domains.create');
    Route::post('domains', [App\Http\Controllers\Admin\DomainController::class, 'store'])->name('domains.store');
    Route::delete('domains/{domain}', [App\Http\Controllers\Admin\DomainController::class, 'destroy'])->name('domains.destroy');

    Route::post('domains/{domain}/switch-traffic', [App\Http\Controllers\Admin\SwitchTrafficController::class, 'switchTraffic'])->name('domains.switch-traffic');
    Route::post('domains/{domain}/revert-traffic', [App\Http\Controllers\Admin\SwitchTrafficController::class, 'revertTraffic'])->name('domains.revert-traffic');
    Route::post('domains/{domain}/sync-cf-dns', [App\Http\Controllers\Admin\SwitchTrafficController::class, 'syncCfDns'])->name('domains.sync-cf-dns');
    Route::post('domains/{domain}/refresh-cf-status', [App\Http\Controllers\Admin\DomainController::class, 'refreshCfZoneStatus'])->name('domains.refresh-cf-status');
    Route::post('domains/{domain}/retry', [App\Http\Controllers\Admin\DomainController::class, 'retry'])->name('domains.retry');
});
