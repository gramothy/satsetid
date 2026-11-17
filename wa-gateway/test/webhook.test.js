import assert from 'node:assert/strict'
import { createHmac } from 'node:crypto'
import { mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, test } from 'node:test'
import { classifyStatus, createDiskQueueStore, createWebhookSender, replayFailed, WEBHOOK_MAX_AGE_MS } from '../src/webhook.js'

const SECRET = 'rahasia-webhook-untuk-test'
const URL = 'http://127.0.0.1:8000/webhooks/baileys'

const tempDir = (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'satset-webhook-'))
    t.after(() => rmSync(dir, { recursive: true, force: true }))

    return dir
}

const files = (dir, folder) => readdirSync(join(dir, folder)).filter((name) => name.endsWith('.json'))

/** fetch stand-in answering with the given statuses (an Error means "no answer"), then 200. */
const recorder = (statuses = []) => {
    const calls = []
    const queue = [...statuses]
    const fetchImpl = async (url, init) => {
        calls.push({ url, ...init, event: JSON.parse(init.body) })
        const next = queue.length > 0 ? queue.shift() : 200
        if (next instanceof Error) {
            throw next
        }

        return new Response('{}', { status: next })
    }

    return { calls, fetchImpl }
}

const capture = () => {
    const logs = []
    const log = (level) => (context, message) => logs.push({ level, context, message })

    return { logs, logger: { warn: log('warn'), error: log('error'), info: log('info'), debug: log('debug') } }
}

const message = (id, text = 'ANTRE ayam-bakar') => ({ type: 'message', provider_message_id: id, from: '6281234567890', text, timestamp: 1763370000 })

describe('pengiriman webhook', () => {
    test('body ditandatangani dengan timestamp dan HMAC-SHA256 hex', async () => {
        const { calls, fetchImpl } = recorder()
        const webhooks = createWebhookSender({ url: URL, secret: SECRET, fetchImpl, now: () => 1763370000123 })
        webhooks.send(message('ABC', 'YA'))
        await webhooks.flush()

        assert.equal(calls.length, 1)
        const [call] = calls
        assert.equal(call.method, 'POST')
        assert.equal(call.headers['X-Satset-Timestamp'], '1763370000')
        assert.equal(call.headers['X-Satset-Signature'], createHmac('sha256', SECRET).update(`1763370000.${call.body}`).digest('hex'))
    })

    test('event dikirim berurutan satu per satu', async () => {
        const order = []
        let inFlight = 0
        const fetchImpl = async (url, init) => {
            inFlight += 1
            assert.equal(inFlight, 1, 'tidak boleh ada dua webhook bersamaan')
            await new Promise((resolve) => setImmediate(resolve))
            order.push(JSON.parse(init.body).provider_message_id)
            inFlight -= 1

            return new Response('{}', { status: 200 })
        }
        const webhooks = createWebhookSender({ url: URL, secret: SECRET, fetchImpl })
        for (const id of ['m1', 'm2', 'm3']) {
            webhooks.send(message(id))
        }
        await webhooks.flush()
        assert.deepEqual(order, ['m1', 'm2', 'm3'])
    })

    test('arti tiap jawaban Laravel', () => {
        assert.deepEqual([200, 204].map(classifyStatus), ['delivered', 'delivered'])
        assert.deepEqual([0, 403, 408, 429, 503].map(classifyStatus), Array(5).fill('unavailable'))
        assert.deepEqual([500, 502].map(classifyStatus), ['error', 'error'])
        assert.deepEqual([400, 401, 404, 413, 422].map(classifyStatus), Array(5).fill('rejected'))
    })
})

describe('antrean di disk (pesan masuk tidak hilang)', () => {
    test('event tersimpan di disk sebelum dikirim dan dihapus setelah diterima', async (t) => {
        const dir = tempDir(t)
        let release
        const fetchImpl = () => new Promise((resolve) => {
            release = () => resolve(new Response('{}', { status: 200 }))
        })
        const webhooks = createWebhookSender({ url: URL, secret: SECRET, store: createDiskQueueStore(dir), fetchImpl })

        webhooks.send(message('DISK1'))
        const [saved] = files(dir, 'pending')
        assert.equal(JSON.parse(readFileSync(join(dir, 'pending', saved), 'utf8')).event.provider_message_id, 'DISK1')
        assert.equal(webhooks.pending(), 1)

        await new Promise((resolve) => setImmediate(resolve))
        release()
        await webhooks.flush()
        assert.deepEqual(files(dir, 'pending'), [])
        assert.equal(webhooks.pending(), 0)
    })

    test('Laravel mati: dicoba terus dengan backoff sampai hidup lagi, urutan tetap, tanpa isi pesan di log', async (t) => {
        const dir = tempDir(t)
        const down = Array(6).fill(new TypeError('fetch failed'))
        const { calls, fetchImpl } = recorder(down)
        const { logs, logger } = capture()
        const sleeps = []
        const webhooks = createWebhookSender({
            url: URL, secret: SECRET, store: createDiskQueueStore(dir), fetchImpl, logger,
            sleep: async (ms) => { sleeps.push(ms) },
        })
        webhooks.send(message('M1', 'rahasia customer'))
        webhooks.send(message('M2', '2'))
        webhooks.send(message('M3', 'YA'))
        await webhooks.flush()

        assert.deepEqual(sleeps, [1000, 2000, 4000, 8000, 16000, 30000])
        assert.deepEqual(calls.slice(6).map((call) => call.event.provider_message_id), ['M1', 'M2', 'M3'])
        assert.deepEqual(files(dir, 'pending'), [])
        assert.equal(logs.filter((log) => log.level === 'error').length, 1, 'error dicatat sekali setelah 3 kali percobaan ulang')
        const all = JSON.stringify(logs)
        assert.ok(!all.includes('rahasia customer') && !all.includes('6281234567890'))
    })

    test('event tersimpan tetap terkirim setelah gateway dijalankan ulang', async (t) => {
        const dir = tempDir(t)
        const failing = async () => { throw new TypeError('fetch failed') }
        const first = createWebhookSender({
            url: URL, secret: SECRET, store: createDiskQueueStore(dir), fetchImpl: failing,
            sleep: () => new Promise((resolve) => setImmediate(resolve)),
        })
        first.send(message('R1'))
        first.send(message('R2'))
        first.send(message('R3'))
        await new Promise((resolve) => setTimeout(resolve, 20))
        first.stop()
        await first.flush(1000)
        assert.equal(files(dir, 'pending').length, 3)

        const { calls, fetchImpl } = recorder()
        const second = createWebhookSender({ url: URL, secret: SECRET, store: createDiskQueueStore(dir), fetchImpl })
        assert.equal(second.pending(), 3)
        await new Promise((resolve) => setImmediate(resolve))
        await second.flush()
        assert.deepEqual(calls.map((call) => call.event.provider_message_id), ['R1', 'R2', 'R3'])
        assert.deepEqual(files(dir, 'pending'), [])
    })

    test('ditolak Laravel (4xx): disisihkan ke failed/, antrean jalan terus', async (t) => {
        const dir = tempDir(t)
        const { calls, fetchImpl } = recorder([422])
        const webhooks = createWebhookSender({ url: URL, secret: SECRET, store: createDiskQueueStore(dir), fetchImpl })
        webhooks.send(message('BAD'))
        webhooks.send(message('GOOD'))
        await webhooks.flush()

        assert.deepEqual(calls.map((call) => call.event.provider_message_id), ['BAD', 'GOOD'])
        assert.equal(files(dir, 'failed').length, 1)
        assert.equal(webhooks.failedCount(), 1)
        assert.deepEqual(files(dir, 'pending'), [])
    })

    test('500 terus-menerus: disisihkan setelah 10 kali, tidak menahan event berikutnya', async (t) => {
        const dir = tempDir(t)
        const { calls, fetchImpl } = recorder(Array(10).fill(500))
        const webhooks = createWebhookSender({ url: URL, secret: SECRET, store: createDiskQueueStore(dir), fetchImpl, sleep: async () => {} })
        webhooks.send(message('POISON'))
        webhooks.send(message('NEXT'))
        await webhooks.flush()

        assert.equal(calls.filter((call) => call.event.provider_message_id === 'POISON').length, 10)
        assert.equal(calls.at(-1).event.provider_message_id, 'NEXT')
        assert.equal(webhooks.failedCount(), 1)
    })

    test('secret beda (403): dicatat jelas dan event tidak dibuang', async (t) => {
        const dir = tempDir(t)
        const { calls, fetchImpl } = recorder([403, 403])
        const { logs, logger } = capture()
        const webhooks = createWebhookSender({ url: URL, secret: SECRET, store: createDiskQueueStore(dir), fetchImpl, logger, sleep: async () => {} })
        webhooks.send(message('SIG'))
        await webhooks.flush()

        assert.equal(calls.length, 3)
        assert.match(logs[0].message, /WA_WEBHOOK_SECRET/)
        assert.equal(webhooks.failedCount(), 0)
    })

    test('event lebih dari 24 jam disisihkan tanpa dikirim', async (t) => {
        const dir = tempDir(t)
        const clock = 1_800_000_000_000
        const { calls, fetchImpl } = recorder()
        const store = createDiskQueueStore(dir)
        store.add({ queued_at: clock - WEBHOOK_MAX_AGE_MS - 1, event: message('OLD') })
        const webhooks = createWebhookSender({ url: URL, secret: SECRET, store, fetchImpl, now: () => clock })
        await new Promise((resolve) => setImmediate(resolve))
        await webhooks.flush()

        assert.equal(calls.length, 0)
        assert.equal(files(dir, 'failed').length, 1)
    })

    test('status sesi yang menunggu diganti yang terbaru; status lama dibuang, bukan disisihkan', async (t) => {
        const dir = tempDir(t)
        const delivered = []
        let release
        const fetchImpl = (url, init) => new Promise((resolve) => {
            delivered.push(JSON.parse(init.body))
            release = () => resolve(new Response('{}', { status: 200 }))
        })
        const webhooks = createWebhookSender({ url: URL, secret: SECRET, store: createDiskQueueStore(dir), fetchImpl })
        webhooks.send({ type: 'session', state: 'closed', phone: null, timestamp: 1 })
        await new Promise((resolve) => setImmediate(resolve))
        webhooks.send({ type: 'session', state: 'connecting', phone: null, timestamp: 2 })
        webhooks.send(message('KEEP'))
        webhooks.send({ type: 'session', state: 'closed', phone: null, timestamp: 3 })
        webhooks.send({ type: 'session', state: 'open', phone: '628111', timestamp: 4 })
        assert.equal(webhooks.pending(), 3, 'sedang dikirim + pesan + status terbaru')
        assert.equal(files(dir, 'pending').length, 3)

        for (let i = 0; i < 3; i++) {
            release()
            await new Promise((resolve) => setImmediate(resolve))
        }
        await webhooks.flush()
        assert.deepEqual(delivered.map((event) => event.state ?? event.provider_message_id), ['closed', 'KEEP', 'open'])

        const clock = 1_800_000_000_000
        const store = createDiskQueueStore(dir)
        store.add({ queued_at: clock - WEBHOOK_MAX_AGE_MS - 1, event: { type: 'status', provider_message_id: 'OUT1', status: 'read' } })
        const later = createWebhookSender({ url: URL, secret: SECRET, store, fetchImpl, now: () => clock })
        await new Promise((resolve) => setImmediate(resolve))
        await later.flush()
        assert.equal(later.failedCount(), 0)
        assert.deepEqual(files(dir, 'pending'), [])
    })

    test('file rusak atau setengah tertulis tidak menghentikan gateway', (t) => {
        const dir = tempDir(t)
        mkdirSync(join(dir, 'pending'), { recursive: true })
        writeFileSync(join(dir, 'pending', '0000000000001-000000.json'), '{rusak')
        writeFileSync(join(dir, 'pending', '0000000000002-000000.json.tmp'), '{"setengah')
        const store = createDiskQueueStore(dir)

        assert.deepEqual(store.load(), [])
        assert.equal(store.failedCount(), 1)
        assert.deepEqual(readdirSync(join(dir, 'pending')), [])
    })

    test('replay mengembalikan event yang disisihkan ke antrean dengan waktu baru', async (t) => {
        const dir = tempDir(t)
        const store = createDiskQueueStore(dir)
        store.setAside(store.add({ queued_at: 1, event: message('LATER') }))
        assert.equal(replayFailed(dir, () => 1_800_000_000_000), 1)
        assert.deepEqual(files(dir, 'failed'), [])

        const { calls, fetchImpl } = recorder()
        const webhooks = createWebhookSender({ url: URL, secret: SECRET, store: createDiskQueueStore(dir), fetchImpl, now: () => 1_800_000_000_000 })
        await new Promise((resolve) => setImmediate(resolve))
        await webhooks.flush()
        assert.deepEqual(calls.map((call) => call.event.provider_message_id), ['LATER'])
    })
})
