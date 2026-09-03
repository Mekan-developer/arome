import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Guards the accidental ways out of a modal or drawer — a click on the backdrop and
 * Escape. While the shell reports unsaved input, those two do not close the window:
 * they raise a prompt that names what is about to be lost. Explicit buttons inside the
 * window go through `requestClose` too, so there is one single exit for the user to
 * learn; a successful save closes the window by emitting `close` directly and never
 * sees the prompt.
 *
 * @param {() => boolean} isDirty Whether the window currently holds unsaved input.
 * @param {() => void} close Called once the exit is allowed.
 */
export function useCloseGuard(isDirty, close) {
    const asking = ref(false)

    const requestClose = () => {
        if (isDirty()) {
            asking.value = true

            return
        }

        close()
    }

    /** Escape inside the prompt is the safe answer: it takes the form back, not away. */
    const stay = () => {
        asking.value = false
    }

    const discard = () => {
        asking.value = false
        close()
    }

    const onKey = (event) => {
        if (event.key !== 'Escape') {
            return
        }

        if (asking.value) {
            stay()

            return
        }

        requestClose()
    }

    onMounted(() => document.addEventListener('keydown', onKey))
    onBeforeUnmount(() => document.removeEventListener('keydown', onKey))

    return { asking, requestClose, stay, discard }
}
