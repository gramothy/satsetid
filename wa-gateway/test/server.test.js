import assert from 'node:assert/strict'
import { after, before, describe, test } from 'node:test'
import { createGatewayServer, parseSendRequest } from '../src/server.js'

const TOKEN = 'token-gateway-0123456789'
const silent = { debug() {}, info() {}, warn() {}, error() {} }

describe('HTTP gateway', () => {
    const session = { state: 'qr', qr: 'QR-RAW-STRING', phone: null, logouts: 0, async logout() { this.logouts++ } }
    const submitted = []
    const queue = { async submit(input) { submitted.push(input); return { status: 200, body: { message_id: 'WAID1' } } } }
    const counter = { count: () => 7 }
    const webhooks = { pending: () => 2, failedCount: () => 1 }
    let server
    let base

    before(async () => {
        server = createGatewayServer({ token: TOKEN, session, queue, counter, webhooks, logger: silent })
        await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
        base = `http://127.0.0.1:${server.address().port}`
    })
    after(() => new Promise((resolve) => server.close(resolve)))

    const call = (path, { method = 'GET', token = TOKEN, body } = {}) => fetch(base + path, {
        method,
        headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}), 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : typeof body === 'string' ? body : JSON.stringify(body),
    })

    test('tanpa token atau token salah: 401 di semua endpoint', async () => {
        for (const [path, method] of [['/health', 'GET'], ['/qr', 'GET'], ['/send', 'POST'], ['/logout', 'POST'], ['/tidak-ada', 'GET']]) {
            assert.equal((await call(path, { method, token: null })).status, 401, path)
            assert.equal((await call(path, { method, token: 'token-gateway-salah-000' })).status, 401, path)
        }
        assert.equal(session.logouts, 0)
    })

    test('GET /health', async () => {
        const response = await call('/health')
        assert.equal(response.status, 200)
        assert.equal(response.headers.get('cache-control'), 'no-store')
        assert.deepEqual(await response.json(), { state: 'qr', phone: null, sent_today: 7, webhook_backlog: 2, webhook_failed: 1 })
    })

    test('GET /qr hanya berisi QR saat state qr', async () => {
        assert.deepEqual(await (await call('/qr')).json(), { qr: 'QR-RAW-STRING' })
        session.state = 'open'
        assert.deepEqual(await (await call('/qr')).json(), { qr: null })
        session.state = 'qr'
    })

    test('POST /send meneruskan ke antrean dan memvalidasi isi', async () => {
        const response = await call('/send', { method: 'POST', body: { to: '6281234567890', text: 'Halo', client_ref: 'log:123' } })
        assert.equal(response.status, 200)
        assert.deepEqual(await response.json(), { message_id: 'WAID1' })
        assert.deepEqual(submitted.at(-1), { to: '6281234567890', text: 'Halo', clientRef: 'log:123' })

        for (const body of ['{bukan json', { to: '081234567890', text: 'x' }, { to: '6281234567890', text: '' }, { to: '6281234567890', text: 'x', client_ref: 'a b' }]) {
            const invalid = await call('/send', { method: 'POST', body })
            assert.equal(invalid.status, 400)
            assert.deepEqual(await invalid.json(), { error: 'invalid' })
        }
        assert.equal(submitted.length, 1)
    })

    test('POST /logout', async () => {
        const response = await call('/logout', { method: 'POST' })
        assert.equal(response.status, 200)
        assert.equal(session.logouts, 1)
    })

    test('route tidak dikenal 404, metode salah 405', async () => {
        assert.equal((await call('/tidak-ada')).status, 404)
        assert.equal((await call('/send')).status, 405)
        assert.equal((await call('/health', { method: 'POST' })).status, 405)
    })
})

test('parseSendRequest', () => {
    assert.deepEqual(parseSendRequest({ to: '6281234567890', text: 'x' }), { to: '6281234567890', text: 'x', clientRef: null })
    assert.equal(parseSendRequest({ to: '6281234567890', text: 'x'.repeat(4097) }), null)
    assert.equal(parseSendRequest([]), null)
    assert.equal(parseSendRequest(null), null)
})
