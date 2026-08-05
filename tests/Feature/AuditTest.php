<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    public function test_the_journal_shows_one_page_of_entries_at_a_time(): void
    {
        $this->writeEntries(20);

        $this->actingAs($this->admin())
            ->get('/audit')
            ->assertInertia(fn ($page) => $page
                ->component('Audit/Index')
                ->has('entries.data', config('aroma.per_page'))
                ->where('entries.current_page', 1)
                ->where('entries.last_page', 2)
                ->where('entries.total', 20));
    }

    public function test_the_second_page_carries_on_where_the_first_stopped(): void
    {
        $this->writeEntries(20);

        $this->actingAs($this->admin())
            ->get('/audit?page=2')
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 5)
                ->where('entries.current_page', 2)
                // Свежие записи идут первыми, поэтому на последней странице — самые старые.
                ->where('entries.data.0.object', 'Товар 5')
                ->where('entries.data.4.object', 'Товар 1'));
    }

    /**
     * Постраничность считает только те записи, которые прошли фильтр — иначе футер
     * обещает страницы, на которых ничего нет.
     */
    public function test_the_filter_narrows_the_page_count_too(): void
    {
        $this->writeEntries(20);
        $this->writeEntries(3, 'price');

        $this->actingAs($this->admin())
            ->get('/audit?kind=price')
            ->assertInertia(fn ($page) => $page
                ->where('filter', 'price')
                ->has('entries.data', 3)
                ->where('entries.last_page', 1)
                ->where('entries.total', 3));
    }

    private function writeEntries(int $count, string $kind = 'product'): void
    {
        foreach (range(1, $count) as $index) {
            AuditLog::create([
                'happened_at' => now()->addMinutes($index),
                'actor' => 'Администратор',
                'action' => 'изменил',
                'object' => 'Товар '.$index,
                'kind' => $kind,
            ]);
        }
    }
}
