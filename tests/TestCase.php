<?php

namespace Tests;

use App\Models\CatalogVersion;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    /**
     * Module flags as the demo ships them.
     *
     * @param  array<string, bool>  $overrides
     */
    protected function seedModules(array $overrides = []): void
    {
        $defaults = [
            'points' => [false, null],
            'warehouses' => [false, 'points'],
            'productPoints' => [false, 'points'],
            'import' => [true, null],
            'devices' => [true, null],
            'audit' => [true, null],
        ];

        foreach ($defaults as $key => [$enabled, $parent]) {
            Module::updateOrCreate(['key' => $key], [
                'is_enabled' => $overrides[$key] ?? $enabled,
                'depends_on' => $parent,
            ]);
        }

        // ModuleService and RightsService cache for the request; tests write straight
        // to the table, so the cache has to be dropped by hand.
        Cache::flush();
    }

    /**
     * Каталог на заданной ревизии — короткий путь вместо сотни правок товаров. Как и с
     * модулями, запись идёт прямо в таблицу, поэтому кеш сбрасывается руками.
     */
    protected function publishCatalogRevision(int $number): void
    {
        CatalogVersion::query()->firstOrCreate([], ['number' => 0])->update(['number' => $number]);

        Cache::flush();
    }

    /**
     * Администратор панели — корневая учётка из ADMIN_LOGIN: та, что видит раздел
     * «Пользователи». Обычный администратор без доступа к разделу — это
     * `User::factory()->admin()->create()`.
     */
    protected function admin(): User
    {
        return User::factory()->root()->create();
    }

    /**
     * Мобильный клиент ходит в /api/v1 с токеном устройства, а не с сессией —
     * тесты обращаются к API так же, как это делает телефон в зале.
     *
     * Токен настоящий и уезжает заголовком: подменённый через `Sanctum::actingAs`
     * токен — мок, у него нет ни имени устройства, ни строки в базе, а именно по ним
     * сервер решает, с какого телефона пришёл запрос.
     */
    protected function asDevice(User $user, string $device = 'Redmi 12'): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$user->createToken($device)->plainTextToken);
    }

    /**
     * Между вызовами внутри одного теста Laravel держит уже разрешённый guard, поэтому
     * отозванный токен продолжает «работать». В бою каждый HTTP-запрос получает свежий
     * контейнер — этот сброс воспроизводит именно это, а не обходит проверку.
     */
    protected function forgetAuthenticatedUser(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }
}
