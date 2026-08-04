const SYLLABLES = ['ba', 'ru', 'me', 'ko', 'sa', 'ni', 'tu', 'le', 'da', 'vi', 'no', 'ze']

const pick = () => SYLLABLES[Math.floor(Math.random() * SYLLABLES.length)]

/**
 * `слог+слог-слог+слог-NNN`, e.g. `sani-tule-482`. Mirrors PasswordService on the
 * server so a password suggested here passes the same validation there.
 */
export function generatePassword() {
    return `${pick()}${pick()}-${pick()}${pick()}-${Math.floor(100 + Math.random() * 900)}`
}

export function passwordIsAcceptable(password) {
    return password.length >= 8 && /[a-zA-Z]/.test(password) && /\d/.test(password) && !/\s/.test(password)
}
