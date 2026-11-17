import assert from 'node:assert/strict'
import { EventEmitter } from 'node:events'
import { describe, test } from 'node:test'
import { SendError } from '../src/send-queue.js'
import { createSession } from '../src/session.js'

const settle = async () => {
    for (let i = 0; i < 10; i++) {
        await new Promise((resolve) => setImmediate(resolve))
    }
}

const boom = (statusCode) => Object.assign(new Error(`status ${statusCode}`), { output: { statusCode } })

/** A Baileys stand-in: records sockets, timers, auth writes and log lines. */
function harness({ registered = false } = {}) {
    const sockets = []
    const timers = []
    const states = []
    const inbound = []
    const statuses = []
    const logs = []
    const removed = []
    let wiped = 0
    let saves = 0
    let releaseSave = null

    const makeSocket = (options) => {
        const ev = new EventEmitter()
        const socket = {
            options,
            ev,
            user: undefined,
            lookups: 0,
            sends: [],
            whatsAppNumbers: new Set(['6281234567890']),
            async onWhatsApp(jid) {
                socket.lookups++
                const phone = jid.split('@')[0]

                return socket.whatsAppNumbers.has(phone) ? [{ jid, exists: true }] : []
            },
            async sendMessage(jid, content) {
                if (socket.sendError) {
                    throw socket.sendError
                }
                socket.sends.push({ jid, content })

                return { key: { id: `WAID${socket.sends.length}`, remoteJid: jid, fromMe: true }, message: { extendedTextMessage: { text: content.text } } }
            },
            loggedOut: false,
            async logout() {
                socket.loggedOut = true
                setImmediate(() => ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: boom(401) } }))
            },
            ended: false,
            async end() {
                socket.ended = true
                ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: undefined } })
            },
        }
        sockets.push(socket)

        return socket
    }

    const useAuthState = async () => ({
        state: {
            creds: registered ? { me: { id: '628111111111:7@s.whatsapp.net' } } : {},
            keys: { get: async () => ({}), set: async () => {} },
        },
        saveCreds: () => new Promise((resolve) => {
            releaseSave = () => {
                saves++
                resolve()
            }
        }),
    })

    const log = (level) => (context, message) => logs.push({ level, context, message: message ?? context })
    const logger = { debug: log('debug'), info: log('info'), warn: log('warn'), error: log('error') }

    const session = createSession({
        authDir: '/tmp/satset-test-auth',
        makeSocket,
        useAuthState,
        logger,
        onInbound: (event) => inbound.push(event),
        onStatus: (event) => statuses.push(event),
        onStateChange: (change) => states.push(change.state),
        onSessionWiped: () => wiped++,
        removeDir: async (dir) => removed.push(dir),
        setTimer: (callback, delay) => {
            const timer = { callback, delay, cleared: false }
            timers.push(timer)

            return timer
        },
        clearTimer: (timer) => {
            timer.cleared = true
        },
    })

    return {
        session, sockets, timers, states, inbound, statuses, logs, removed,
        get wiped() { return wiped },
        get saves() { return saves },
        releaseSave: () => releaseSave?.(),
        current: () => sockets.at(-1),
        emit: (event, payload, socket = sockets.at(-1)) => socket.ev.emit(event, payload),
        open: async (socket = sockets.at(-1)) => {
            socket.user = { id: '628111111111:7@s.whatsapp.net' }
            socket.ev.emit('connection.update', { connection: 'open' })
            await settle()
        },
        runTimer: async () => {
            const timer = timers.filter((item) => !item.cleared && !item.ran).at(-1)
            timer.ran = true
            timer.callback()
            await settle()
        },
    }
}

describe('koneksi', () => {
    test('opsi socket: tidak online saat tersambung dan tanpa riwayat penuh', async () => {
        const h = harness()
        await h.session.start()
        const options = h.current().options
        assert.equal(options.markOnlineOnConnect, false)
        assert.equal(options.syncFullHistory, false)
        assert.equal(typeof options.getMessage, 'function')
        assert.equal(h.session.state, 'connecting')
    })

    test('QR lalu open: state, QR mentah, dan nomor tersambung', async () => {
        const h = harness()
        await h.session.start()
        h.emit('connection.update', { qr: '2@QR-PERTAMA' })
        assert.equal(h.session.state, 'qr')
        assert.equal(h.session.qr, '2@QR-PERTAMA')

        await h.open()
        assert.equal(h.session.state, 'open')
        assert.equal(h.session.qr, null)
        assert.equal(h.session.phone, '628111111111')
        assert.deepEqual(h.states, ['qr', 'open'])
    })

    test('restart setelah pairing diperlakukan sebagai reconnect biasa', async () => {
        const h = harness()
        await h.session.start()
        h.emit('connection.update', { qr: '2@QR' })
        h.emit('connection.update', { connection: 'close', lastDisconnect: { error: boom(515) } })
        await settle()
        assert.equal(h.session.state, 'closed')
        assert.equal(h.timers.at(-1).delay, 1000)

        await h.runTimer()
        assert.equal(h.sockets.length, 2)
        await h.open()
        assert.equal(h.session.state, 'open')
    })

    test('backoff eksponensial sampai 30 detik, lalu reset setelah open', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        const delays = []
        for (let i = 0; i < 7; i++) {
            h.emit('connection.update', { connection: 'close', lastDisconnect: { error: boom(408) } })
            await settle()
            delays.push(h.timers.at(-1).delay)
            await h.runTimer()
        }
        assert.deepEqual(delays, [1000, 2000, 4000, 8000, 16000, 30000, 30000])

        await h.open()
        h.emit('connection.update', { connection: 'close', lastDisconnect: { error: boom(428) } })
        await settle()
        assert.equal(h.timers.at(-1).delay, 1000)
    })

    test('logged out: auth_info dihapus, state logged_out, tanpa reconnect', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        await h.open()
        h.emit('connection.update', { connection: 'close', lastDisconnect: { error: boom(401) } })
        await settle()
        assert.equal(h.session.state, 'logged_out')
        assert.deepEqual(h.removed, ['/tmp/satset-test-auth'])
        assert.equal(h.wiped, 1)
        assert.equal(h.session.phone, null)
        assert.equal(h.timers.length, 0)
    })

    test('event dari socket lama diabaikan setelah reconnect', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        const old = h.current()
        h.emit('connection.update', { connection: 'close', lastDisconnect: { error: boom(428) } })
        await settle()
        await h.runTimer()
        h.emit('connection.update', { connection: 'open' }, old)
        h.emit('messages.upsert', { type: 'notify', messages: [{ key: { id: 'X', remoteJid: '6281234567890@s.whatsapp.net' }, message: { conversation: 'YA' } }] }, old)
        await settle()
        assert.equal(h.session.state, 'connecting')
        assert.equal(h.inbound.length, 0)
    })
})

describe('pesan masuk dan status', () => {
    test('notify diteruskan, append diabaikan, LID tanpa nomor hanya dicatat id-nya', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        await h.open()
        const message = (id, key, text = 'ANTRE ayam-bakar') => ({ key: { id, fromMe: false, ...key }, message: { conversation: text }, messageTimestamp: 1763370000 })

        h.emit('messages.upsert', { type: 'append', messages: [message('OLD', { remoteJid: '6281234567890@s.whatsapp.net' })] })
        h.emit('messages.upsert', {
            type: 'notify',
            messages: [
                message('M1', { remoteJid: '6281234567890@s.whatsapp.net' }),
                message('M2', { remoteJid: '201234567890123@lid', remoteJidAlt: '6289876543210@s.whatsapp.net' }, '2'),
                message('M3', { remoteJid: '201234567890123@lid' }, 'rahasia'),
            ],
        })

        assert.deepEqual(h.inbound.map((event) => [event.provider_message_id, event.from, event.text]), [
            ['M1', '6281234567890', 'ANTRE ayam-bakar'],
            ['M2', '6289876543210', '2'],
        ])
        const warning = h.logs.find((log) => log.level === 'warn')
        assert.deepEqual(warning.context, { id: 'M3' })
        assert.ok(!JSON.stringify(h.logs).includes('rahasia'))
    })

    test('status pesan keluar diteruskan', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        h.emit('messages.update', [
            { key: { id: 'WAID1', remoteJid: '6281234567890@s.whatsapp.net', fromMe: true }, update: { status: 3 } },
            { key: { id: 'IN1', remoteJid: '6281234567890@s.whatsapp.net', fromMe: false }, update: { status: 4 } },
            { key: { id: 'WAID1', remoteJid: '6281234567890@s.whatsapp.net', fromMe: true }, update: { status: 4 } },
        ])
        assert.deepEqual(h.statuses, [
            { type: 'status', provider_message_id: 'WAID1', status: 'delivered' },
            { type: 'status', provider_message_id: 'WAID1', status: 'read' },
        ])
    })
})

describe('kirim teks', () => {
    test('nomor dicek dengan onWhatsApp sekali lalu di-cache', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        await h.open()
        assert.equal(await h.session.sendText('6281234567890', 'satu'), 'WAID1')
        assert.equal(await h.session.sendText('6281234567890', 'dua'), 'WAID2')
        assert.equal(h.current().lookups, 1)
        assert.deepEqual(h.current().sends[0], { jid: '6281234567890@s.whatsapp.net', content: { text: 'satu' } })
        assert.deepEqual(await h.current().options.getMessage({ id: 'WAID1' }), { extendedTextMessage: { text: 'satu' } })
    })

    test('nomor tanpa WhatsApp ditolak dan hasilnya juga di-cache', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        await h.open()
        for (let i = 0; i < 2; i++) {
            await assert.rejects(h.session.sendText('6280000000000', 'x'), (error) => error instanceof SendError && error.code === 'not_on_whatsapp')
        }
        assert.equal(h.current().lookups, 1)
        assert.equal(h.current().sends.length, 0)
    })

    test('belum tersambung atau koneksi tertutup sebelum kirim: not_sent', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        await assert.rejects(h.session.sendText('6281234567890', 'x'), (error) => error.code === 'not_sent')
        await h.open()
        h.current().sendError = boom(428)
        await assert.rejects(h.session.sendText('6281234567890', 'x'), (error) => error.code === 'not_sent')
    })

    test('error lain dari sendMessage diteruskan (hasil tidak pasti)', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        await h.open()
        h.current().sendError = boom(408)
        await assert.rejects(h.session.sendText('6281234567890', 'x'), (error) => !(error instanceof SendError))
    })
})

describe('logout dan shutdown', () => {
    test('logout memutus perangkat, menghapus auth_info, dan tidak menyambung ulang', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        await h.open()
        await h.session.logout()
        await settle()
        assert.equal(h.sockets[0].loggedOut, true)
        assert.equal(h.session.state, 'logged_out')
        assert.deepEqual(h.removed, ['/tmp/satset-test-auth'])
        assert.equal(h.wiped, 1)
        assert.equal(h.timers.length, 0)
    })

    test('stop() menutup socket dan menunggu saveCreds selesai', async () => {
        const h = harness({ registered: true })
        await h.session.start()
        await h.open()
        h.emit('creds.update', { me: { id: '628111111111:7@s.whatsapp.net' } })

        let stopped = false
        const stopping = h.session.stop().then(() => { stopped = true })
        await settle()
        assert.equal(h.sockets[0].ended, true)
        assert.equal(stopped, false, 'stop() harus menunggu penulisan kredensial')

        h.releaseSave()
        await settle()
        h.releaseSave()
        await stopping
        assert.equal(stopped, true)
        assert.ok(h.saves >= 1)
        assert.equal(h.timers.length, 0, 'tidak ada reconnect saat shutdown')
        assert.equal(h.session.state, 'closed')
    })
})
