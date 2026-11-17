// SatSet WhatsApp gateway: keeps one Baileys session and exposes a small HTTP API on
// 127.0.0.1 for Laravel. Laravel stays the source of truth (outbox, opt-in, tickets).
import { join } from 'node:path'
import makeWASocket, { useMultiFileAuthState } from '@whiskeysockets/baileys'
import pino from 'pino'
import { loadConfig, loadEnvFile } from './config.js'
import { createDailyCounter } from './daily-counter.js'
import { createSendQueue } from './send-queue.js'
import { createGatewayServer } from './server.js'
import { createSession } from './session.js'
import { createDiskQueueStore, createWebhookSender } from './webhook.js'

loadEnvFile()

let config
try {
    config = loadConfig()
} catch (error) {
    console.error(error.message)
    console.error('Salin wa-gateway/.env.example menjadi wa-gateway/.env lalu isi nilainya.')
    process.exit(1)
}

// Message text, QR strings and secrets never belong in the log. Our own log lines only
// carry ids and states; the redaction is a safety net for Baileys' own warnings.
const logger = pino({
    level: 'info',
    redact: {
        paths: [
            'text', '*.text', 'qr', '*.qr', 'conversation', '*.conversation',
            'message.conversation', 'message.extendedTextMessage', '*.message.conversation', '*.message.extendedTextMessage',
            'authorization', '*.authorization', 'headers.authorization', 'token', '*.token', 'secret', '*.secret',
        ],
        censor: '[disembunyikan]',
    },
})
const baileysLogger = logger.child({ module: 'baileys' }, { level: 'warn' })

let qrTerminal = null
try {
    qrTerminal = (await import('qrcode-terminal')).default
} catch {
    // Optional dependency: the QR is shown on the SatSet admin page anyway.
}
const printQr = (qr) => {
    // Only on an interactive terminal, so the QR never ends up in a redirected log file.
    if (qrTerminal && process.stdout.isTTY) {
        qrTerminal.generate(qr, { small: true })
    }
}

const counter = createDailyCounter({ file: join(config.authDir, 'satset-sent-today.json'), logger })
let webhookStore
try {
    webhookStore = createDiskQueueStore(join(config.dataDir, 'webhook-queue'))
} catch (error) {
    console.error(`Folder antrean webhook tidak bisa dipakai (${error.message}).`)
    process.exit(1)
}
const webhooks = createWebhookSender({ url: config.webhookUrl, secret: config.webhookSecret, store: webhookStore, logger })
const session = createSession({
    authDir: config.authDir,
    makeSocket: makeWASocket,
    useAuthState: useMultiFileAuthState,
    logger,
    baileysLogger,
    printQr,
    onInbound: (event) => webhooks.send(event),
    onStatus: (event) => webhooks.send(event),
    onStateChange: ({ state, phone }) => {
        logger.info({ state }, 'Status sesi WhatsApp')
        webhooks.send({ type: 'session', state, phone, timestamp: Math.floor(Date.now() / 1000) })
    },
    onSessionWiped: () => counter.reset(),
})
const queue = createSendQueue({
    minDelayMs: config.minDelayMs,
    maxDelayMs: config.maxDelayMs,
    dailyCap: config.dailyCap,
    counter,
    logger,
    isConnected: () => session.state === 'open',
    sendText: (to, text) => session.sendText(to, text),
})
const server = createGatewayServer({ token: config.token, session, queue, counter, webhooks, logger })

server.on('error', (error) => {
    logger.fatal({ err: error?.message }, 'HTTP gateway gagal berjalan (port sudah dipakai?)')
    process.exit(1)
})
server.listen(config.port, config.host, () => {
    logger.info({ host: config.host, port: config.port }, 'wa-gateway siap. Scan QR di SatSet: Admin > Hubungkan WhatsApp')
})

process.on('unhandledRejection', (reason) => {
    logger.error({ err: reason?.message ?? 'unknown' }, 'Promise ditolak tanpa penanganan')
})

let shuttingDown = false
const shutdown = async (signal) => {
    if (shuttingDown) {
        return
    }
    shuttingDown = true
    logger.info({ signal }, 'Menutup wa-gateway; menunggu kredensial tersimpan')
    setTimeout(() => {
        logger.error('Penutupan terlalu lama; keluar paksa')
        process.exit(1)
    }, 20000).unref()

    server.close()
    queue.close()
    await queue.idle()
    await session.stop()
    // Events not delivered within 3 seconds stay in data/webhook-queue for the next start.
    await webhooks.flush(3000)
    webhooks.stop()
    server.closeAllConnections()
    logger.info('wa-gateway berhenti')
    process.exit(0)
}
// once(): a second Ctrl+C falls back to Node's default and exits immediately.
process.once('SIGINT', () => shutdown('SIGINT'))
process.once('SIGTERM', () => shutdown('SIGTERM'))

try {
    await session.start()
} catch (error) {
    logger.fatal({ err: error?.message }, 'Sesi WhatsApp gagal dimulai')
    process.exit(1)
}
