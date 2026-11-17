import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { test } from 'node:test'
import { createDailyCounter } from '../src/daily-counter.js'

test('sent_today kembali ke nol tepat tengah malam WIB', () => {
    let now = Date.UTC(2026, 10, 17, 16, 59, 58) // 23:59:58 WIB
    const counter = createDailyCounter({ now: () => now })
    counter.increment()
    counter.increment()
    assert.equal(counter.count(), 2)

    now += 1000 // 23:59:59 WIB
    assert.equal(counter.count(), 2)

    now += 1000 // 00:00:00 WIB
    assert.equal(counter.count(), 0)
    assert.equal(counter.increment(), 1)
})

test('hitungan bertahan saat gateway dijalankan ulang di hari yang sama', (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'satset-counter-'))
    t.after(() => rmSync(dir, { recursive: true, force: true }))
    const file = join(dir, 'auth_info', 'satset-sent-today.json')
    const now = () => Date.UTC(2026, 10, 17, 3, 0, 0)

    const first = createDailyCounter({ file, now })
    first.increment()
    first.increment()
    first.increment()
    assert.deepEqual(JSON.parse(readFileSync(file, 'utf8')), { day: '2026-11-17', count: 3 })

    assert.equal(createDailyCounter({ file, now }).count(), 3)
    assert.equal(createDailyCounter({ file, now: () => Date.UTC(2026, 10, 17, 17, 0, 0) }).count(), 0)
})

test('reset() mengosongkan hitungan setelah sesi dihapus', () => {
    const counter = createDailyCounter({ now: () => 0 })
    counter.increment()
    counter.reset()
    assert.equal(counter.count(), 0)
})
