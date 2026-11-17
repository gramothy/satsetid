import assert from 'node:assert/strict'
import { describe, test } from 'node:test'
import { createSendQueue, SendError } from '../src/send-queue.js'

const settle = async () => {
    for (let i = 0; i < 10; i++) {
        await new Promise((resolve) => setImmediate(resolve))
    }
}

const memoryCounter = (start = 0) => {
    let count = start

    return { count: () => count, increment: () => ++count, reset: () => { count = 0 } }
}

/** Fake timers for setTimeout and Date; the queue uses its real sleep(). */
const queueWithFakeTimers = (t, options = {}) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 0 })
    const sent = []
    const randoms = [...(options.randoms ?? [])]
    const queue = createSendQueue({
        minDelayMs: 3000,
        maxDelayMs: 8000,
        dailyCap: 200,
        counter: memoryCounter(),
        isConnected: () => true,
        sendText: async (to, text) => {
            sent.push({ to, text, at: Date.now() })

            return `WAID${sent.length}`
        },
        random: () => (randoms.length > 0 ? randoms.shift() : 0),
        now: () => Date.now(),
        ...options,
    })

    return { queue, sent }
}

describe('pacing (fake timer)', () => {
    test('setiap pesan menunggu jeda acak dulu, satu per satu sesuai urutan', async (t) => {
        // random 0 -> 3000 ms, 0.999999 -> 8000 ms, 0.5 -> 5500 ms
        const { queue, sent } = queueWithFakeTimers(t, { randoms: [0, 0.999999, 0.5] })
        const first = queue.submit({ to: '6281111111111', text: 'satu', clientRef: 'log:1' })
        const second = queue.submit({ to: '6282222222222', text: 'dua', clientRef: 'log:2' })
        const third = queue.submit({ to: '6283333333333', text: 'tiga', clientRef: 'log:3' })
        await settle()
        assert.equal(queue.pending(), 3)

        t.mock.timers.tick(2999)
        await settle()
        assert.equal(sent.length, 0, 'belum boleh terkirim sebelum jeda 3 detik')

        t.mock.timers.tick(1)
        await settle()
        assert.deepEqual(sent.map((item) => [item.text, item.at]), [['satu', 3000]])
        assert.deepEqual(await first, { status: 200, body: { message_id: 'WAID1' } })

        t.mock.timers.tick(7999)
        await settle()
        assert.equal(sent.length, 1, 'pesan kedua menunggu jeda 8 detik setelah pesan pertama')

        t.mock.timers.tick(1)
        await settle()
        assert.deepEqual(await second, { status: 200, body: { message_id: 'WAID2' } })

        t.mock.timers.tick(5500)
        await settle()
        assert.deepEqual(await third, { status: 200, body: { message_id: 'WAID3' } })
        assert.deepEqual(sent.map((item) => [item.text, item.at]), [['satu', 3000], ['dua', 11000], ['tiga', 16500]])
        assert.equal(queue.pending(), 0)
    })

    test('pesan yang baru bisa mulai setelah batas waktu jawaban ditolak "busy" tanpa dikirim', async (t) => {
        const { queue, sent } = queueWithFakeTimers(t, { randoms: [0.999999, 0.999999, 0.999999] })
        const results = [1, 2, 3].map((n) => queue.submit({ to: '6281111111111', text: `pesan ${n}`, clientRef: `log:${n}` }))
        for (let i = 0; i < 4; i++) {
            t.mock.timers.tick(8000)
            await settle()
        }
        assert.deepEqual((await Promise.all(results)).map((result) => result.status), [200, 200, 503])
        assert.deepEqual((await results[2]).body, { error: 'busy' })
        assert.equal(sent.length, 2)
    })

    test('client_ref yang sama tidak pernah dikirim dua kali', async (t) => {
        const { queue, sent } = queueWithFakeTimers(t)
        const first = queue.submit({ to: '6281111111111', text: 'halo', clientRef: 'log:7' })
        const duplicateWhileQueued = queue.submit({ to: '6281111111111', text: 'halo', clientRef: 'log:7' })
        t.mock.timers.tick(3000)
        await settle()
        assert.deepEqual(await first, await duplicateWhileQueued)
        const retried = await queue.submit({ to: '6281111111111', text: 'halo', clientRef: 'log:7' })
        assert.deepEqual(retried, { status: 200, body: { message_id: 'WAID1' } })
        assert.equal(sent.length, 1)
    })
})

describe('batas harian dan status koneksi', () => {
    test('batas harian dihitung bersama pesan yang masih antre', async (t) => {
        const counter = memoryCounter(199)
        const { queue, sent } = queueWithFakeTimers(t, { counter })
        const allowed = queue.submit({ to: '6281111111111', text: 'a', clientRef: 'log:1' })
        assert.deepEqual(await queue.submit({ to: '6281111111111', text: 'b', clientRef: 'log:2' }), { status: 429, body: { error: 'daily_cap' } })
        t.mock.timers.tick(3000)
        await settle()
        assert.equal((await allowed).status, 200)
        assert.equal(counter.count(), 200)
        assert.equal((await queue.submit({ to: '6281111111111', text: 'c', clientRef: 'log:3' })).status, 429)
        assert.equal(sent.length, 1)
    })

    test('tidak tersambung: 503 not_connected dan tidak ada yang dikirim', async (t) => {
        let connected = false
        const { queue, sent } = queueWithFakeTimers(t, { isConnected: () => connected })
        assert.deepEqual(await queue.submit({ to: '6281111111111', text: 'a' }), { status: 503, body: { error: 'not_connected' } })

        connected = true
        const result = queue.submit({ to: '6281111111111', text: 'b' })
        await settle()
        connected = false
        t.mock.timers.tick(3000)
        await settle()
        assert.deepEqual(await result, { status: 503, body: { error: 'not_connected' } })
        assert.equal(sent.length, 0)
    })

    test('close() menolak pesan yang belum dikirim', async (t) => {
        const { queue, sent } = queueWithFakeTimers(t)
        const first = queue.submit({ to: '6281111111111', text: 'a' })
        const second = queue.submit({ to: '6281111111111', text: 'b' })
        await settle()
        queue.close()
        assert.deepEqual(await second, { status: 503, body: { error: 'not_connected' } })
        t.mock.timers.tick(3000)
        await settle()
        assert.deepEqual(await first, { status: 503, body: { error: 'not_connected' } })
        await queue.idle()
        assert.equal(sent.length, 0)
    })
})

describe('jawaban saat gagal kirim', () => {
    const failingQueue = (t, error) => queueWithFakeTimers(t, { sendText: async () => { throw error } }).queue

    const outcome = async (t, error) => {
        const queue = failingQueue(t, error)
        const result = queue.submit({ to: '6281111111111', text: 'a', clientRef: 'log:1' })
        t.mock.timers.tick(3000)
        await settle()

        return result
    }

    test('nomor tidak terdaftar di WhatsApp: 422', async (t) => {
        assert.deepEqual(await outcome(t, new SendError('not_on_whatsapp', 'not_on_whatsapp')), { status: 422, body: { error: 'not_on_whatsapp' } })
    })

    test('gagal sebelum terkirim: 503 (aman dicoba lagi)', async (t) => {
        assert.deepEqual(await outcome(t, new SendError('not_sent', 'lookup_failed')), { status: 503, body: { error: 'lookup_failed' } })
    })

    test('error lain setelah Baileys mulai mengirim: 409 send_uncertain', async (t) => {
        assert.deepEqual(await outcome(t, new Error('Timed Out')), { status: 409, body: { error: 'send_uncertain' } })
    })
})
