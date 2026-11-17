import { mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { wibDateKey } from './util.js'

/**
 * Messages sent today (WIB). The count is stored next to the session keys so a restart
 * of the gateway does not reset the daily cap; logging out removes it with the session.
 */
export function createDailyCounter({ file = null, now = Date.now, logger = null } = {}) {
    let state = { day: wibDateKey(now()), count: 0 }

    if (file) {
        try {
            const saved = JSON.parse(readFileSync(file, 'utf8'))
            if (typeof saved?.day === 'string' && Number.isInteger(saved.count) && saved.count >= 0) {
                state = { day: saved.day, count: saved.count }
            }
        } catch {
            // No file yet (first run) or unreadable: start from zero.
        }
    }

    const persist = () => {
        if (!file) {
            return
        }
        try {
            mkdirSync(dirname(file), { recursive: true })
            const temporary = `${file}.tmp`
            writeFileSync(temporary, JSON.stringify(state))
            renameSync(temporary, file)
        } catch (error) {
            logger?.warn({ err: error?.message }, 'Gagal menyimpan hitungan pesan harian')
        }
    }

    const roll = () => {
        const today = wibDateKey(now())
        if (state.day !== today) {
            state = { day: today, count: 0 }
            persist()
        }
    }

    return {
        count() {
            roll()

            return state.count
        },
        increment() {
            roll()
            state.count += 1
            persist()

            return state.count
        },
        /** Forget the count, e.g. after the session was logged out and its folder removed. */
        reset() {
            state = { day: wibDateKey(now()), count: 0 }
        },
    }
}
