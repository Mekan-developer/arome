<?php

namespace Tests\Unit;

use App\Services\PasswordService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PasswordTest extends TestCase
{
    private PasswordService $passwords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->passwords = new PasswordService;
    }

    public function test_the_generator_produces_the_documented_shape(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertMatchesRegularExpression(
                '/^[a-z]{4}-[a-z]{4}-\d{3}$/',
                $this->passwords->generate(),
                'Expected the слог+слог-слог+слог-NNN shape, e.g. sani-tule-482.',
            );
        }
    }

    public function test_a_generated_password_always_passes_its_own_rules(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($this->passwords->isAcceptable($this->passwords->generate()));
        }
    }

    /**
     * A manual password is accepted only when all three rules pass.
     */
    #[DataProvider('manualPasswords')]
    public function test_a_manual_password_needs_all_three_rules(string $password, bool $acceptable): void
    {
        $this->assertSame($acceptable, $this->passwords->isAcceptable($password));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function manualPasswords(): array
    {
        return [
            'too short' => ['ab1', false],
            'no digit' => ['abcdefgh', false],
            'no letter' => ['12345678', false],
            'has a space' => ['abcd 1234', false],
            'all three rules pass' => ['sanitule482', true],
            'generated shape' => ['sani-tule-482', true],
        ];
    }

    public function test_it_reports_which_rule_failed(): void
    {
        $this->assertSame(
            ['length' => false, 'alnum' => false, 'nospace' => true],
            $this->passwords->rules('abc'),
        );

        $this->assertSame(
            ['length' => true, 'alnum' => true, 'nospace' => false],
            $this->passwords->rules('abcd 1234'),
        );
    }
}
