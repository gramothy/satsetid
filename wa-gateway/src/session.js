import { rm } from 'node:fs/promises'
import { SendError } from './send-queue.js'
import { backoffDelay, inboundEventsFromUpsert, jidToPhone, phoneToJid, toStatusEvent } from './util.js'

/** Values reported by GET /health. */
export const SESSION_STATES = Object.freeze(['connecting', 'qr', 'open', 'closed', 'logged_out'])

/** DisconnectReason values from Baileys 7 that change the gateway's behaviour. */
const LOGGED_OUT = 401
const CONNECTION_CLOSED = 428
const RESTART_REQUIRED = 515

const KNOWN_NUMBER_TTL_MS = 24 * 60 * 60 * 1000
const UNKNOWN_NUMBER_TTL_MS = 60 * 60 * 1000
const NUMBER_CACHE_LIMIT = 5000
const RECENT_MESSAGE_LIMIT = 500

/**
 * One linked WhatsApp account (Baileys socket) with its auth folder.
 *
 * Dependencies are injected so the gateway can be tested without WhatsApp:
 * makeSocket/useAuthState default to Baileys' makeWASocket/useMultiFileAuthState.
 */
export function createSession({
    authDir,
    makeSocket,
    useAuthState,
    logger,
    baileysLogger = logger,
    onInbound = () => {},
    onStatus = () => {},
    onStateChange = () => {},
    onSessionWiped = () => {},
    printQr = () => {},
    removeDir = (dir) => rm(dir, { recursive: true, force: true }),
    setTimer = setTimeout,
    clearTimer = clearTimeout,
    now = Date.now,
}) {
    let state = 'connecting'
    let qr = null
    let phone = null
    let sock = null
    let saveCurrentCreds = null
    let attempt = 0
    let reconnectTimer = null
    let stopping = false
    let epoch = 0
    const pendingWrites = new Set()
    const knownNumbers = new Map()
    const recentMessages = new Map()

    const setState = (next) => {
        if (state === next) {
            return
        }
        state = next
        onStateChange({ state, phone })
    }

    /** Remember auth writes so shutdown and logout can wait for them. */
    const track = (promise) => {
        pendingWrites.add(promise)
        promise.then(() => pendingWrites.delete(promise), () => pendingWrites.delete(promise))

        return promise
    }

    const waitForWrites = () => Promise.allSettled([...pendingWrites])

    /** Writes from a session that was wiped (logged out) must not recreate the auth folder. */
    const guardAuth = ({ state: authState, saveCreds }, ownEpoch) => ({
        state: {
            creds: authState.creds,
            keys: {
                get: (type, ids) => authState.keys.get(type, ids),
                set: (data) => (ownEpoch === epoch ? track(Promise.resolve(authState.keys.set(data))) : Promise.resolve()),
            },
        },
        saveCreds: () => {
            if (ownEpoch !== epoch) {
                return Promise.resolve()
            }

            return track(Promise.resolve().then(saveCreds)).catch((error) => {
                logger.warn({ err: error?.message }, 'Gagal menyimpan kredensial WhatsApp')
            })
        },
    })

    const rememberSent = (id, message) => {
        if (!message) {
            return
        }
        recentMessages.set(id, message)
        while (recentMessages.size > RECENT_MESSAGE_LIMIT) {
            recentMessages.delete(recentMessages.keys().next().value)
        }
    }

    const cacheNumber = (number, jid) => {
        knownNumbers.set(number, { jid, expiresAt: now() + (jid ? KNOWN_NUMBER_TTL_MS : UNKNOWN_NUMBER_TTL_MS) })
        while (knownNumbers.size > NUMBER_CACHE_LIMIT) {
            knownNumbers.delete(knownNumbers.keys().next().value)
        }
    }

    /** JID to send to, checked with onWhatsApp() once and cached. */
    const resolveJid = async (socket, number) => {
        const cached = knownNumbers.get(number)
        if (cached && cached.expiresAt > now()) {
            if (!cached.jid) {
                throw new SendError('not_on_whatsapp', 'not_on_whatsapp')
            }

            return cached.jid
        }

        let results
        try {
            results = await socket.onWhatsApp(phoneToJid(number))
        } catch (error) {
            throw new SendError('not_sent', 'lookup_failed', { cause: error })
        }
        if (!Array.isArray(results)) {
            throw new SendError('not_sent', 'lookup_failed')
        }
        const match = results.find((result) => result?.exists)
        if (!match) {
            cacheNumber(number, null)
            throw new SendError('not_on_whatsapp', 'not_on_whatsapp')
        }
        const jid = jidToPhone(match.jid) ? match.jid : phoneToJid(number)
        cacheNumber(number, jid)

        return jid
    }

    const wipe = async () => {
        epoch += 1
        sock = null
        saveCurrentCreds = null
        if (reconnectTimer) {
            clearTimer(reconnectTimer)
            reconnectTimer = null
        }
        await waitForWrites()
        await removeDir(authDir)
        knownNumbers.clear()
        recentMessages.clear()
        qr = null
        phone = null
        onSessionWiped()
    }

    const scheduleReconnect = () => {
        if (stopping || reconnectTimer) {
            return
        }
        const delay = backoffDelay(attempt)
        attempt += 1
        logger.info({ delay_ms: delay }, 'Menyambung ulang ke WhatsApp')
        reconnectTimer = setTimer(() => {
            reconnectTimer = null
            start().catch((error) => {
                logger.error({ err: error?.message }, 'Gagal membuka koneksi WhatsApp')
                setState('closed')
                scheduleReconnect()
            })
        }, delay)
    }

    const handleConnection = async (socket, update) => {
        if (socket !== sock) {
            return
        }
        const { connection, lastDisconnect } = update

        if (update.qr) {
            qr = update.qr
            attempt = 0
            setState('qr')
            printQr(update.qr)
        }

        if (connection === 'open') {
            qr = null
            attempt = 0
            phone = jidToPhone(socket.user?.id) ?? phone
            logger.info('WhatsApp tersambung')
            setState('open')

            return
        }
        if (connection !== 'close') {
            return
        }

        sock = null
        qr = null
        const code = lastDisconnect?.error?.output?.statusCode
        if (stopping) {
            setState('closed')

            return
        }
        if (code === LOGGED_OUT) {
            logger.warn('Sesi WhatsApp keluar (logged out). auth_info dihapus; tidak menyambung ulang otomatis.')
            await wipe()
            setState('logged_out')

            return
        }
        if (code === RESTART_REQUIRED) {
            logger.info('WhatsApp meminta restart setelah pairing')
        } else {
            logger.warn({ code: code ?? null, err: lastDisconnect?.error?.message ?? null }, 'Koneksi WhatsApp terputus')
        }
        setState('closed')
        scheduleReconnect()
    }

    const handleUpsert = (upsert) => {
        const { events, skipped } = inboundEventsFromUpsert(upsert, Math.floor(now() / 1000))
        for (const item of skipped) {
            if (item.skip === 'lid_without_phone') {
                logger.warn({ id: item.id }, 'Pesan dari LID tanpa nomor telepon diabaikan')
            } else {
                logger.debug({ id: item.id, reason: item.skip }, 'Pesan masuk diabaikan')
            }
        }
        events.forEach((event) => onInbound(event))
    }

    const handleUpdates = (updates) => {
        for (const update of Array.isArray(updates) ? updates : []) {
            const event = toStatusEvent(update)
            if (event) {
                onStatus(event)
            }
        }
    }

    async function start() {
        if (stopping) {
            return
        }
        const ownEpoch = epoch
        // The previous socket may still be writing creds (e.g. right after pairing).
        await waitForWrites()
        const auth = guardAuth(await useAuthState(authDir), ownEpoch)
        if (stopping || ownEpoch !== epoch) {
            return
        }
        phone = jidToPhone(auth.state.creds?.me?.id) ?? phone

        const socket = makeSocket({
            auth: auth.state,
            logger: baileysLogger,
            // Stay "offline" so the phone keeps receiving notifications.
            markOnlineOnConnect: false,
            // Never request the full chat history. Recent history sync stays on: Baileys
            // needs it for the initial LID <-> phone number mappings.
            syncFullHistory: false,
            getMessage: async (key) => recentMessages.get(key?.id),
        })
        sock = socket
        saveCurrentCreds = auth.saveCreds
        setState('connecting')

        socket.ev.on('creds.update', () => {
            if (socket === sock) {
                auth.saveCreds()
            }
        })
        socket.ev.on('connection.update', (update) => {
            handleConnection(socket, update).catch((error) => {
                logger.error({ err: error?.message }, 'Gagal memproses status koneksi WhatsApp')
            })
        })
        socket.ev.on('messages.upsert', (upsert) => {
            if (socket === sock) {
                handleUpsert(upsert)
            }
        })
        socket.ev.on('messages.update', (updates) => {
            if (socket === sock) {
                handleUpdates(updates)
            }
        })
    }

    return {
        get state() {
            return state
        },
        get qr() {
            return state === 'qr' ? qr : null
        },
        get phone() {
            return phone
        },

        start,

        /** Send one text message; resolves to the WhatsApp message id. */
        async sendText(number, text) {
            const socket = sock
            if (!socket || state !== 'open') {
                throw new SendError('not_sent', 'not_connected')
            }
            const jid = await resolveJid(socket, number)
            if (socket !== sock || state !== 'open') {
                throw new SendError('not_sent', 'not_connected')
            }

            let sent
            try {
                sent = await socket.sendMessage(jid, { text })
            } catch (error) {
                // "Connection Closed" is thrown before anything is written to the socket.
                if (error?.output?.statusCode === CONNECTION_CLOSED) {
                    throw new SendError('not_sent', 'not_connected', { cause: error })
                }
                throw error
            }
            const id = sent?.key?.id
            if (typeof id !== 'string' || id === '') {
                throw new Error('sendMessage tidak mengembalikan id pesan')
            }
            rememberSent(id, sent.message)

            return id
        },

        /** Unlink this device (when connected) and delete auth_info. No automatic reconnect. */
        async logout() {
            if (reconnectTimer) {
                clearTimer(reconnectTimer)
                reconnectTimer = null
            }
            // Detach first: the socket's own "logged out" close event is then ignored and
            // this method alone wipes the session.
            const socket = sock
            const wasOpen = state === 'open'
            sock = null
            if (socket && wasOpen) {
                try {
                    await socket.logout()
                } catch (error) {
                    logger.warn({ err: error?.message }, 'Logout ke WhatsApp gagal; sesi lokal tetap dihapus')
                }
            } else if (socket) {
                await Promise.resolve(socket.end(undefined)).catch(() => {})
            }
            await wipe()
            setState('logged_out')
        },

        /** Close the socket for shutdown and wait until credentials are on disk. */
        async stop() {
            stopping = true
            if (reconnectTimer) {
                clearTimer(reconnectTimer)
                reconnectTimer = null
            }
            const socket = sock
            const save = saveCurrentCreds
            sock = null
            if (socket) {
                await Promise.resolve(socket.end(undefined)).catch(() => {})
            }
            await waitForWrites()
            if (save && state !== 'logged_out') {
                await save()
            }
            await waitForWrites()
            setState('closed')
        },
    }
}
