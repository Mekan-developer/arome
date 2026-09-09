<?php

use App\Http\Controllers\Api\V2\ProductApiController;
use App\Http\Controllers\Api\V2\SyncApiController;
use App\Http\Controllers\Api\V2\TokenApiController;
use Illuminate\Support\Facades\Route;

/*
 * v2 API приложения продавца. Внутри опубликованной версии допустимы только
 * добавления — телефон в зале нельзя заставить обновиться.
 *
 * Слои версии: маршрут -> контроллер -> сервис -> репозиторий, ответ — через ресурс.
 * Контроллеры v2 не содержат правил: права роли, порядок сортировки, запись скана,
 * проверка пароля и форма отказа живут в `App\Services\V2`, SQL — в
 * `App\Repositories\V2`, тело ответа — в `App\Http\Resources\V2`. Файлы v1 при этом
 * не трогаются: у опубликованной версии свои копии слоёв, и правка v2 не может
 * изменить то, что уже отвечает телефонам в зале.
 *
 * Аутентификация — токен Sanctum на устройство: отзыв идёт по конкретному телефону,
 * поэтому «выйти на потерянном устройстве» работает, а блокировка сотрудника рвёт
 * сессию немедленно. Каталог не публичен: матрица прав решает, какие колонки получит
 * роль, а это бессмысленно, пока неизвестно, кто спрашивает.
 */

Route::post('tokens', [TokenApiController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('api.v2.tokens.store');

Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::get('products', [ProductApiController::class, 'index'])->name('api.v2.products.index');

});
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::delete('tokens', [TokenApiController::class, 'destroy'])->name('api.v2.tokens.destroy');
    /*
     * Выгрузка каталога целиком — отдельным путём, а не режимом `products`: у списка
     * свой договор с приложением (страницы, фильтры, `meta.has_more`), и трогать его
     * ради полной выгрузки нельзя.
     */
    Route::get('products_all', [ProductApiController::class, 'all'])->name('api.v2.products.all');

    /*
     * История сканирований объявлена выше `products/{barcode}`: пересечься они не
     * могут — штрихкод ограничен цифрами, — но порядок здесь читается как правило.
     */
    Route::get('products/recent', [ProductApiController::class, 'recent'])->name('api.v2.products.recent');
    Route::delete('products/recent', [ProductApiController::class, 'clearRecent'])->name('api.v2.products.recent.clear');

    Route::get('products/{barcode}', [ProductApiController::class, 'show'])
        ->where('barcode', '[0-9]{8,13}')
        ->name('api.v2.products.show');

    Route::post('sync', [SyncApiController::class, 'store'])->name('api.v2.sync');
});
