<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\PointController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RightsController;
use App\Http\Controllers\SuperadminController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/products');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:50,15');
});

Route::post('logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function (): void {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::post('products/hide', [ProductController::class, 'hide'])->name('products.hide');
    Route::post('products/barcode', [ProductController::class, 'barcode'])->name('products.barcode');

    Route::middleware('module:import')->group(function (): void {
        Route::get('import', [ImportController::class, 'index'])->name('import.index');
        Route::post('import', [ImportController::class, 'store'])->name('import.store');
        Route::post('import/confirm', [ImportController::class, 'confirm'])->name('import.confirm');
        Route::get('import/template', [ImportController::class, 'template'])->name('import.template');
        Route::get('import/backup', [ImportController::class, 'backup'])->name('import.backup');
    });

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::patch('users/{user}/access', [UserController::class, 'toggleAccess'])->name('users.access');
    Route::put('users/{user}/password', [UserController::class, 'changePassword'])->name('users.password');

    Route::get('rights', [RightsController::class, 'index'])->name('rights.index');
    Route::put('rights', [RightsController::class, 'update'])->name('rights.update');

    Route::get('lessons', [LessonController::class, 'index'])->name('lessons.index');
    Route::get('lessons/{path}', [LessonController::class, 'show'])->where('path', '.*')->name('lessons.show');

    Route::get('points', [PointController::class, 'index'])->middleware('module:points')->name('points.index');
    Route::get('devices', [DeviceController::class, 'index'])->middleware('module:devices')->name('devices.index');
    Route::get('audit', [AuditController::class, 'index'])->middleware('module:audit')->name('audit.index');

    Route::post('su/leave', [SuperadminController::class, 'leaveImpersonation'])->name('su.leave');
});

/*
 * The service console. There is no link to it from the admin panel — entry is by the
 * root login, the triple click on «v1.0» or the «СЛУЖЕБНЫЙ ВХОД» demo button.
 */
Route::middleware(['auth', 'superadmin'])->group(function (): void {
    Route::get('su', [SuperadminController::class, 'index'])->name('su.index');
    Route::post('su/modules/{key}', [SuperadminController::class, 'toggle'])->name('su.toggle');
    Route::post('su/impersonate', [SuperadminController::class, 'impersonate'])->name('su.impersonate');
});
