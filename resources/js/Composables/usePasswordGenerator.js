const SYLLABLES = [
    'ba', 'ru', 'me', 'ko', 'sa', 'ni', 'tu', 'le', 'da', 'vi', 'no', 'ze',
    'ka', 'li', 'mo', 'pa', 're', 'su', 'ti', 'vo', 'xa', 'yu', 'za', 'be',
    'ci', 'fo', 'gu', 'hi', 'jo', 'lu', 'na', 'pe', 'qi', 'ro', 'se', 'wa',
]

const pick = () => SYLLABLES[Math.floor(Math.random() * SYLLABLES.length)]

/**
 * Пять слогов и четыре цифры, например `sanitulebako4821`. Как PasswordService.
 */
export function generatePassword() {
    const digits = String(Math.floor(Math.random() * 10000)).padStart(4, '0')

    return `${pick()}${pick()}${pick()}${pick()}${pick()}${digits}`
}

export function passwordIsAcceptable(password) {
    return password.length >= 8 && /[a-zA-Z]/.test(password) && /\d/.test(password) && !/\s/.test(password)
}
