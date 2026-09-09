<?php

namespace Tests\Feature\Api\V2;

use App\Models\Device;
use App\Models\Point;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TokenApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    private function seller(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'seller',
            'login' => 'gozel',
            'password' => Hash::make('arome2026'),
            ...$attributes,
        ]);
    }

    public function test_it_issues_a_token_for_a_device(): void
    {
        $this->seller();

        $response = $this->postJson('/api/v2/tokens', [
            'login' => 'gozel',
            'password' => 'arome2026',
            'device' => 'Redmi 12',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('seller', $response->json('data.user.role'));
    }

    public function test_the_issued_token_opens_the_catalogue(): void
    {
        $this->seller();
        Product::factory()->create();

        $token = $this->postJson('/api/v2/tokens', [
            'login' => 'gozel',
            'password' => 'arome2026',
            'device' => 'Redmi 12',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v2/products')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_sign_in_is_written_into_the_journal(): void
    {
        $user = $this->seller(['name' => 'Гозель Аманова']);

        $this->postJson('/api/v2/tokens', [
            'login' => 'gozel',
            'password' => 'arome2026',
            'device' => 'Redmi 12',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'actor' => 'Гозель Аманова',
            'action' => 'Вход с устройства',
            'object' => 'Redmi 12',
            'kind' => 'auth',
        ]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_wrong_password_answers_with_a_code(): void
    {
        $this->seller();

        $this->postJson('/api/v2/tokens', [
            'login' => 'gozel',
            'password' => 'nepravilnyj',
            'device' => 'Redmi 12',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    /**
     * Несуществующий логин отвечает тем же кодом, что и неверный пароль: иначе
     * перебором узнают, какие учётные записи заведены.
     */
    public function test_an_unknown_login_is_indistinguishable_from_a_wrong_password(): void
    {
        $this->postJson('/api/v2/tokens', [
            'login' => 'nikto',
            'password' => 'arome2026',
            'device' => 'Redmi 12',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_the_request_is_validated(): void
    {
        $this->postJson('/api/v2/tokens', ['login' => 'gozel'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password', 'device']);
    }

    public function test_a_blocked_employee_gets_no_token(): void
    {
        $this->seller(['is_active' => false]);

        $this->postJson('/api/v2/tokens', [
            'login' => 'gozel',
            'password' => 'arome2026',
            'device' => 'Redmi 12',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_blocked');
    }

    /**
     * Один токен на устройство: повторный вход с того же телефона заменяет старый,
     * а не плодит их.
     */
    public function test_signing_in_again_from_the_same_device_replaces_the_token(): void
    {
        $user = $this->seller();

        $first = $this->postJson('/api/v2/tokens', ['login' => 'gozel', 'password' => 'arome2026', 'device' => 'Redmi 12'])->json('data.token');
        $second = $this->postJson('/api/v2/tokens', ['login' => 'gozel', 'password' => 'arome2026', 'device' => 'Redmi 12'])->json('data.token');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $user->tokens()->count());

        $this->forgetAuthenticatedUser();
        $this->withHeader('Authorization', "Bearer {$first}")->getJson('/api/v2/products')->assertUnauthorized();

        $this->forgetAuthenticatedUser();
        $this->withHeader('Authorization', "Bearer {$second}")->getJson('/api/v2/products')->assertOk();
    }

    /**
     * «Выйти на потерянном телефоне»: отзыв идёт по устройству, а не по сотруднику.
     */
    public function test_signing_out_revokes_only_this_device(): void
    {
        $this->seller();

        $phone = $this->postJson('/api/v2/tokens', ['login' => 'gozel', 'password' => 'arome2026', 'device' => 'Redmi 12'])->json('data.token');
        $tablet = $this->postJson('/api/v2/tokens', ['login' => 'gozel', 'password' => 'arome2026', 'device' => 'Планшет склада'])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->deleteJson('/api/v2/tokens')
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->forgetAuthenticatedUser();
        $this->withHeader('Authorization', "Bearer {$phone}")->getJson('/api/v2/products')->assertUnauthorized();

        $this->forgetAuthenticatedUser();
        $this->withHeader('Authorization', "Bearer {$tablet}")->getJson('/api/v2/products')->assertOk();
    }

    /**
     * Хранилище токенов у версий общее: приложение обновляется постепенно, и телефон,
     * вошедший через v2, не должен терять доступ к тем экранам, что ещё ходят в v1.
     */
    public function test_a_token_issued_by_v2_works_on_v1_as_well(): void
    {
        $this->seller();
        Product::factory()->create();

        $token = $this->postJson('/api/v2/tokens', ['login' => 'gozel', 'password' => 'arome2026', 'device' => 'Redmi 12'])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/products')
            ->assertOk();
    }

    /**
     * Блокировка сотрудника в панели рвёт сессию немедленно — это и обещает §10.
     */
    public function test_blocking_an_employee_kills_the_live_session(): void
    {
        $user = $this->seller();
        Device::factory()->create(['user_id' => $user->id, 'point_id' => Point::factory()]);

        $token = $this->postJson('/api/v2/tokens', ['login' => 'gozel', 'password' => 'arome2026', 'device' => 'Redmi 12'])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v2/products')->assertOk();

        $this->actingAs($this->admin())->patch("/users/{$user->id}/access")->assertRedirect();

        $this->forgetAuthenticatedUser();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v2/products')
            ->assertUnauthorized();
    }

    /**
     * Выдача токенов ограничена по частоте: перебор пароля с одного адреса упирается
     * в лимит маршрута, а не в скорость проверки хеша.
     */
    public function test_token_issuing_is_rate_limited(): void
    {
        $this->seller();

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->postJson('/api/v2/tokens', ['login' => 'nikto', 'password' => 'wrong', 'device' => 'Redmi 12']);
        }

        $this->postJson('/api/v2/tokens', ['login' => 'gozel', 'password' => 'arome2026', 'device' => 'Redmi 12'])
            ->assertStatus(429);
    }
}
