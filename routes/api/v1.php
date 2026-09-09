<?php

use App\Http\Controllers\Api\V1\ProductApiController;
use App\Http\Controllers\Api\V1\SyncApiController;
use App\Http\Controllers\Api\V1\TokenApiController;
use Illuminate\Support\Facades\Route;

/*
 * v1 API приложения продавца. Внутри опубликованной версии допустимы только
 * добавления — телефон в зале нельзя заставить обновиться.
 *
 * Аутентификация — токен Sanctum на устройство: отзыв идёт по конкретному телефону,
 * поэтому «выйти на потерянном устройстве» работает, а блокировка сотрудника рвёт
 * сессию немедленно. Каталог не публичен: матрица прав решает, какие колонки получит
 * роль, а это бессмысленно, пока неизвестно, кто спрашивает.
 */

Route::post('tokens', [TokenApiController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('api.v1.tokens.store');

Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::get('products', [ProductApiController::class, 'index'])->name('api.v1.products.index');

});
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::delete('tokens', [TokenApiController::class, 'destroy'])->name('api.v1.tokens.destroy');
    /*
     * Выгрузка каталога целиком — отдельным путём, а не режимом `products`: у списка
     * свой договор с приложением (страницы, фильтры, `meta.has_more`), и трогать его
     * ради полной выгрузки нельзя.
     */
    Route::get('products_all', [ProductApiController::class, 'all'])->name('api.v1.products.all');

    /*
     * История сканирований объявлена выше `products/{barcode}`: пересечься они не
     * могут — штрихкод ограничен цифрами, — но порядок здесь читается как правило.
     */
    Route::get('products/recent', [ProductApiController::class, 'recent'])->name('api.v1.products.recent');
    Route::delete('products/recent', [ProductApiController::class, 'clearRecent'])->name('api.v1.products.recent.clear');

    Route::get('products/{barcode}', [ProductApiController::class, 'show'])
        ->where('barcode', '[0-9]{8,13}')
        ->name('api.v1.products.show');

    Route::post('sync', [SyncApiController::class, 'store'])->name('api.v1.sync');
});
