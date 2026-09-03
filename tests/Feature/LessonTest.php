<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    public function test_the_knowledge_base_is_closed_to_anyone_without_a_session(): void
    {
        $this->get('/lessons')->assertRedirect('/login');
        $this->get('/lessons/index.html')->assertRedirect('/login');
        $this->get('/lessons/audio/tovary/tovary-01.mp3')->assertRedirect('/login');
    }

    /**
     * Ссылки внутри материала относительные, поэтому базой должен стать сам файл, а
     * не корень сайта: иначе «assets/base.css» уходит в /assets и страница остаётся
     * без стилей.
     */
    public function test_the_section_opens_on_the_page_itself_so_relative_links_resolve(): void
    {
        $this->actingAs($this->seller())
            ->get('/lessons')
            ->assertRedirect('/lessons/index.html');
    }

    public function test_a_signed_in_seller_gets_the_contents_page(): void
    {
        $response = $this->actingAs($this->seller())->get('/lessons/index.html');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString('База знаний', $response->streamedContent());
    }

    /**
     * Страницы, стили, движок, обложки и озвучка — материал бесполезен, если хоть
     * один из этих типов отдаётся не тем заголовком: браузер не применит css и не
     * запустит mp3.
     *
     * @param  non-empty-string  $path
     * @param  non-empty-string  $type
     */
    #[DataProvider('files')]
    public function test_every_kind_of_file_in_the_material_is_served_with_its_own_type(string $path, string $type): void
    {
        $this->actingAs($this->seller())
            ->get('/lessons/'.$path)
            ->assertOk()
            ->assertHeader('Content-Type', $type);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function files(): array
    {
        return [
            'урок' => ['lessons/tovary.html', 'text/html; charset=UTF-8'],
            'стили' => ['assets/lesson.css', 'text/css; charset=UTF-8'],
            'движок' => ['assets/engine.js', 'text/javascript; charset=UTF-8'],
            'обложка' => ['assets/covers/1.jpg', 'image/jpeg'],
            'озвучка' => ['audio/tovary/tovary-01.mp3', 'audio/mpeg'],
        ];
    }

    /**
     * Путь приходит из адресной строки, поэтому «..» в нём — это попытка прочитать
     * файл вне каталога обучения, а не опечатка.
     *
     * @param  non-empty-string  $path
     */
    #[DataProvider('escapes')]
    public function test_a_path_leading_out_of_the_material_is_not_served(string $path): void
    {
        $this->actingAs($this->seller())
            ->get('/lessons/'.$path)
            ->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escapes(): array
    {
        return [
            'выход вверх' => ['..%2F..%2F.env'],
            'соседний каталог' => ['..%2Fviews%2Fapp.blade.php'],
            'абсолютный путь' => ['%2Fetc%2Fpasswd'],
            'каталог вместо файла' => ['assets/covers'],
            'несуществующий файл' => ['lessons/net-takogo.html'],
        ];
    }

    /**
     * Каталог пополняют копированием папки целиком, поэтому решает не то, что файла
     * нет, а то, что его тип не из белого списка.
     */
    public function test_a_file_of_a_foreign_type_inside_the_material_stays_unreachable(): void
    {
        file_put_contents(resource_path('lessons/tmp-secret.php'), '<?php echo 1;');

        try {
            $this->actingAs($this->seller())
                ->get('/lessons/tmp-secret.php')
                ->assertNotFound();
        } finally {
            @unlink(resource_path('lessons/tmp-secret.php'));
        }
    }

    /**
     * Раздел не спрятан ни за флагом модуля, ни за ролью: инструкция нужна прежде
     * всего продавцу, который в панели ничего не настраивает.
     */
    public function test_the_tab_is_shown_to_every_role(): void
    {
        $this->actingAs($this->seller())
            ->get('/products')
            ->assertInertia(fn ($page) => $page
                ->where('sections.7.key', 'lessons')
                ->where('sections.7.href', '/lessons')
                ->where('sections.7.external', true)
                ->where('sections.7.visible', true));
    }

    private function seller(): User
    {
        return User::factory()->create();
    }
}
