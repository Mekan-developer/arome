<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModuleTest extends TestCase
{
    use RefreshDatabase;

    private ModuleService $modules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modules = app(ModuleService::class);
    }

    public function test_switching_points_off_cascades_to_its_dependants(): void
    {
        $this->seedModules(['points' => true, 'warehouses' => true, 'productPoints' => true]);

        $this->assertSame(
            ['points' => true, 'warehouses' => true, 'productPoints' => true],
            collect($this->modules->effective())->only(['points', 'warehouses', 'productPoints'])->all(),
        );

        $this->modules->toggle('points');

        $this->assertSame(
            ['points' => false, 'warehouses' => false, 'productPoints' => false],
            collect($this->modules->effective())->only(['points', 'warehouses', 'productPoints'])->all(),
            'Warehouses and per-point stock depend on points and must follow it down.',
        );
    }

    public function test_a_dependant_stays_off_until_its_parent_is_back(): void
    {
        $this->seedModules(['points' => false, 'warehouses' => true]);

        $this->assertFalse($this->modules->enabled('warehouses'));
        $this->assertTrue($this->modules->raw()['warehouses'], 'Its own flag is untouched — only the effective state is off.');

        $this->modules->toggle('points');

        $this->assertTrue($this->modules->enabled('warehouses'));
    }

    /**
     * A hidden section must be unreachable by direct URL, not merely absent from the tabs.
     */
    #[DataProvider('guardedSections')]
    public function test_a_hidden_section_answers_404_by_direct_url(string $module, string $url): void
    {
        $this->seedModules([$module => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->get($url)->assertNotFound();

        $this->seedModules([$module => true]);

        $this->actingAs($admin)->get($url)->assertOk();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function guardedSections(): array
    {
        return [
            'points' => ['points', '/points'],
            'import' => ['import', '/import'],
            'devices' => ['devices', '/devices'],
            'audit' => ['audit', '/audit'],
        ];
    }

    public function test_the_tab_strip_drops_the_hidden_sections(): void
    {
        $this->seedModules(['points' => false, 'import' => false]);

        $visible = collect($this->modules->sections($this->admin()))->where('visible', true)->pluck('key')->all();

        $this->assertSame(['products', 'users', 'rights', 'devices', 'audit'], $visible);
    }

    public function test_module_state_is_shared_with_every_page(): void
    {
        $this->seedModules(['points' => true]);

        $this->actingAs($this->admin())
            ->get('/products')
            ->assertInertia(fn ($page) => $page
                ->where('modules.points', true)
                ->where('modules.warehouses', false));
    }

    public function test_a_dependency_cycle_cannot_hang_the_resolver(): void
    {
        $this->seedModules();
        Module::where('key', 'points')->update(['is_enabled' => true, 'depends_on' => 'warehouses']);
        Module::where('key', 'warehouses')->update(['is_enabled' => true, 'depends_on' => 'points']);
        Cache::flush();

        $this->assertFalse($this->modules->enabled('points'));
    }

    public function test_only_a_superadmin_reaches_the_service_console(): void
    {
        $this->seedModules();

        $this->actingAs($this->admin())->get('/su')->assertNotFound();
        $this->actingAs(User::factory()->superadmin()->create())->get('/su')->assertOk();
    }

    public function test_the_console_toggles_a_module_and_writes_the_journal(): void
    {
        $this->seedModules(['points' => false]);
        $root = User::factory()->superadmin()->create();

        $this->actingAs($root)->post('/su/modules/points')->assertRedirect();

        $this->assertTrue($this->modules->enabled('points'));

        $this->actingAs($root)
            ->get('/su')
            ->assertInertia(fn ($page) => $page->where(
                'journal.0.text',
                'Модуль «Точки продаж» включён — разделы и поля вернулись в панель администратора',
            ));
    }
}
