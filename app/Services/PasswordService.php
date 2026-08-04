<?php

namespace App\Services;

class PasswordService
{
    /**
     * @var list<string>
     */
    private const SYLLABLES = ['ba', 'ru', 'me', 'ko', 'sa', 'ni', 'tu', 'le', 'da', 'vi', 'no', 'ze'];

    /**
     * A handed-out password in the `слог+слог-слог+слог-NNN` shape, e.g. `sani-tule-482`.
     */
    public function generate(): string
    {
        return sprintf(
            '%s%s-%s%s-%d',
            $this->syllable(),
            $this->syllable(),
            $this->syllable(),
            $this->syllable(),
            random_int(100, 999),
        );
    }

    /**
     * The three rules shown as a live checklist next to the manual password field.
     *
     * @return array{length: bool, alnum: bool, nospace: bool}
     */
    public function rules(string $password): array
    {
        return [
            'length' => mb_strlen($password) >= 8,
            'alnum' => preg_match('/[a-zA-Z]/', $password) === 1 && preg_match('/\d/', $password) === 1,
            'nospace' => preg_match('/\s/', $password) !== 1,
        ];
    }

    /**
     * A manual password is accepted only when all three rules pass.
     */
    public function isAcceptable(string $password): bool
    {
        return ! in_array(false, $this->rules($password), true);
    }

    private function syllable(): string
    {
        return self::SYLLABLES[random_int(0, count(self::SYLLABLES) - 1)];
    }
}
