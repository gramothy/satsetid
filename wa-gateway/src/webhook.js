import { existsSync, mkdirSync, readdirSync, readFileSync, renameSync, unlinkSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { backoffDelay, signWebhook } from './util.js'

/** Events Laravel has not accepted within this time are set aside (failed/) instead of retried. */
export const WEBHOOK_MAX_AGE_MS = 24 * 60 * 60 * 1000

/** A Laravel 5xx other than 503 may be caused by the event itself; give up on it after this many tries. */
export const SERVER_ERROR_ATTEMPTS = 10

/** Content-free description of an event for log lines (never text or phone numbers). */
export function describeEvent(event) {
    return event?.type === 'session'
        ? { type: 'session', state: event.state }
        : { type: event?.type, id: event?.provider_message_id }
}

/**
 * What a Laravel answer means for the event:
 *   delivered    2xx
 *   unavailable  Laravel is down, restarting, in maintenance or disabled (0 = no answer, 503),
 *                rate limited (408, 429) or the secrets differ (403). The event itself is fine:
 *                keep it and keep trying.
 *   error        other 5xx. Retried SERVER_ERROR_ATTEMPTS times, then set aside.
 *   rejected     other 4xx (invalid event). Set aside at once.
 */
export function classifyStatus(status) {
    if (status >= 200 && status < 300) {
        return 'delivered'
    }
    if (status === 0 || status === 403 || status === 408 || status === 429 || status === 503) {
        return 'unavailable'
    }

    return status >= 500 ? 'error' : 'rejected'
}

const isEnvelope = (value) => value && Number.isFinite(value.queued_at) && value.event && typeof value.event === 'object'

/**
 * Events on disk until Laravel accepts them, so a stopped Laravel or a gateway restart does
 * not lose customer messages (Baileys has already acknowledged them to WhatsApp).
 *   <dir>/pending/  one JSON file per event, in order (file name = queue time + sequence)
 *   <dir>/failed/   events Laravel rejected or did not accept within 24 hours (manual review,
 *                   `npm run webhooks:replay` puts them back)
 * The files contain message text and phone numbers: wa-gateway/data/ is not committed.
 */
export function createDiskQueueStore(dir) {
    const pendingDir = join(dir, 'pending')
    const failedDir = join(dir, 'failed')
    mkdirSync(pendingDir, { recursive: true })
    mkdirSync(failedDir, { recursive: true })
    for (const name of readdirSync(pendingDir)) {
        if (name.endsWith('.tmp')) {
            unlinkSync(join(pendingDir, name)) // half-written before a crash; never handed to the queue
        }
    }
    let sequence = 0

    return {
        load() {
            const items = []
            for (const name of readdirSync(pendingDir).filter((file) => file.endsWith('.json')).sort()) {
                let envelope = null
                try {
                    envelope = JSON.parse(readFileSync(join(pendingDir, name), 'utf8'))
                } catch {
                    envelope = null
                }
                if (isEnvelope(envelope)) {
                    items.push({ name, envelope })
                } else {
                    renameSync(join(pendingDir, name), join(failedDir, name))
                }
            }

            return items
        },
        add(envelope) {
            const name = `${String(envelope.queued_at).padStart(13, '0')}-${String(sequence++).padStart(6, '0')}.json`
            const path = join(pendingDir, name)
            writeFileSync(`${path}.tmp`, JSON.stringify(envelope), { flush: true })
            renameSync(`${path}.tmp`, path)

            return name
        },
        remove(name) {
            unlinkSync(join(pendingDir, name))
        },
        setAside(name) {
            renameSync(join(pendingDir, name), join(failedDir, name))
        },
        failedCount() {
            return readdirSync(failedDir).filter((file) => file.endsWith('.json')).length
        },
    }
}

/** Same interface without a disk, for tests. */
export function createMemoryQueueStore() {
    let failed = 0
    let sequence = 0

    return {
        load: () => [],
        add: () => `memory-${sequence++}`,
        remove: () => {},
        setAside: () => {
            failed += 1
        },
        failedCount: () => failed,
    }
}

/**
 * Move every set-aside event back to pending with a fresh queue time. Run it after fixing
 * the cause, then restart the gateway. Returns the number of events moved.
 */
export function replayFailed(dir, now = Date.now) {
    const pendingDir = join(dir, 'pending')
    const failedDir = join(dir, 'failed')
    if (!existsSync(failedDir)) {
        return 0
    }
    mkdirSync(pendingDir, { recursive: true })
    let moved = 0
    for (const name of readdirSync(failedDir).filter((file) => file.endsWith('.json')).sort()) {
        const source = join(failedDir, name)
        let envelope = null
        try {
            envelope = JSON.parse(readFileSync(source, 'utf8'))
        } catch {
            continue
        }
        if (!isEnvelope(envelope)) {
            continue
        }
        const target = join(pendingDir, `${String(now()).padStart(13, '0')}-r${String(moved).padStart(6, '0')}.json`)
        writeFileSync(`${target}.tmp`, JSON.stringify({ ...envelope, queued_at: now() }), { flush: true })
        renameSync(`${target}.tmp`, target)
        unlinkSync(source)
        moved += 1
    }

    return moved
}

/**
 * Posts events to Laravel one at a time, in the order they happened (so "2" is never
 * processed after "YA"). Each event is stored before the first attempt and removed only
 * after Laravel accepts it. Every attempt is signed with a fresh timestamp:
 *   X-Satset-Timestamp: unix seconds
 *   X-Satset-Signature: hex HMAC-SHA256(secret, timestamp + "." + raw body)
 * Retries back off 1s, 2s, 4s, ... up to 30s. After 3 failed retries the problem is logged and
 * the event keeps being retried while Laravel is unavailable (up to 24 hours).
 * Delivery is at-least-once: Laravel deduplicates message ids and ignores older states.
 */
export function createWebhookSender({
    url,
    secret,
    store = createMemoryQueueStore(),
    logger = null,
    fetchImpl = globalThis.fetch,
    sleep = null,
    now = Date.now,
    retries = 3,
    baseDelayMs = 1000,
    maxDelayMs = 30000,
    timeoutMs = 15000,
    maxAgeMs = WEBHOOK_MAX_AGE_MS,
    serverErrorAttempts = SERVER_ERROR_ATTEMPTS,
}) {
    const queue = store.load()
    let running = false
    let stopped = false
    let wake = null
    let idleWaiters = []

    // Interruptible, so stop() does not wait for a 30 second back-off.
    const pause = sleep ?? ((ms) => new Promise((resolve) => {
        const timer = setTimeout(resolve, ms)
        wake = () => {
            clearTimeout(timer)
            resolve()
        }
    }))

    const post = async (event) => {
        const body = JSON.stringify(event)
        const timestamp = String(Math.floor(now() / 1000))
        try {
            const response = await fetchImpl(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'User-Agent': 'satset-wa-gateway',
                    'X-Satset-Timestamp': timestamp,
                    'X-Satset-Signature': signWebhook(secret, timestamp, body),
                },
                body,
                signal: AbortSignal.timeout(timeoutMs),
            })
            await response.arrayBuffer().catch(() => {})

            return response.status
        } catch {
            return 0
        }
    }

    /** @returns {Promise<'delivered' | 'set_aside' | 'dropped' | 'stopped'>} */
    const deliver = async ({ envelope }) => {
        const { event } = envelope
        const about = describeEvent(event)
        let serverErrors = 0
        for (let attempt = 0; ; attempt++) {
            if (stopped) {
                return 'stopped'
            }
            if (now() - envelope.queued_at > maxAgeMs) {
                // An old session state or receipt is worthless; a customer message needs a person to look at it.
                if (event.type !== 'message') {
                    logger?.info(about, 'Event webhook lebih dari 24 jam dibuang')

                    return 'dropped'
                }
                logger?.error(about, 'Pesan masuk tidak diterima Laravel selama 24 jam; disisihkan ke data/webhook-queue/failed')

                return 'set_aside'
            }
            if (attempt > 0) {
                await pause(backoffDelay(attempt - 1, { baseMs: baseDelayMs, maxMs: maxDelayMs }))
                if (stopped) {
                    return 'stopped'
                }
            }

            const status = await post(event)
            const kind = classifyStatus(status)
            if (kind === 'delivered') {
                if (attempt > retries) {
                    logger?.info({ ...about, attempts: attempt + 1 }, 'Webhook akhirnya diterima Laravel')
                }

                return 'delivered'
            }
            if (kind === 'rejected') {
                logger?.error({ ...about, status }, 'Laravel menolak event webhook; disisihkan ke data/webhook-queue/failed')

                return 'set_aside'
            }
            if (kind === 'error' && ++serverErrors >= serverErrorAttempts) {
                logger?.error({ ...about, status }, 'Laravel terus error untuk event ini; disisihkan ke data/webhook-queue/failed')

                return 'set_aside'
            }

            if (status === 403 && attempt === 0) {
                logger?.error({ ...about, status }, 'Laravel menolak signature webhook. Samakan WA_WEBHOOK_SECRET dengan WHATSAPP_WEBHOOK_SECRET.')
            } else if (attempt < retries) {
                logger?.warn({ ...about, status, attempt: attempt + 1 }, 'Webhook ke Laravel gagal; dicoba lagi')
            } else if (attempt === retries) {
                logger?.error({ ...about, status }, 'Webhook ke Laravel tetap gagal setelah 3 kali percobaan ulang; event tetap disimpan dan terus dicoba')
            }
        }
    }

    const finish = (item, outcome) => {
        if (!item.name) {
            return
        }
        try {
            if (outcome === 'delivered' || outcome === 'dropped') {
                store.remove(item.name)
            } else {
                store.setAside(item.name)
            }
        } catch (error) {
            logger?.warn({ err: error?.message }, 'Gagal memperbarui antrean webhook di disk')
        }
    }

    const drain = async () => {
        if (running || stopped) {
            return
        }
        running = true
        try {
            while (!stopped && queue.length > 0) {
                const item = queue[0]
                const outcome = await deliver(item)
                if (outcome === 'stopped') {
                    break
                }
                finish(item, outcome)
                queue.shift()
            }
        } finally {
            running = false
            const waiters = idleWaiters
            idleWaiters = []
            waiters.forEach((resolve) => resolve())
        }
    }

    // Events left from the previous run go out first.
    if (queue.length > 0) {
        logger?.info({ count: queue.length }, 'Mengirim ulang event webhook yang tersimpan')
        queueMicrotask(drain)
    }

    return {
        /** Store and queue an event; never throws and never blocks the caller. */
        send(event) {
            // Only the newest session state matters: replace older ones that are still waiting
            // (not the one being sent), so a long reconnect loop does not pile up events.
            if (event?.type === 'session') {
                for (let index = queue.length - 1; index >= (running ? 1 : 0); index--) {
                    if (queue[index].envelope.event?.type === 'session') {
                        const [stale] = queue.splice(index, 1)
                        finish(stale, 'dropped')
                    }
                }
            }
            const envelope = { queued_at: now(), event }
            let name = null
            try {
                name = store.add(envelope)
            } catch (error) {
                logger?.error({ ...describeEvent(event), err: error?.message }, 'Gagal menyimpan event webhook ke disk; hanya disimpan di memori')
            }
            queue.push({ name, envelope })
            drain()
        },

        /** Events not yet accepted by Laravel. */
        pending() {
            return queue.length
        },

        /** Events set aside for manual review (data/webhook-queue/failed). */
        failedCount() {
            try {
                return store.failedCount()
            } catch {
                return null
            }
        },

        /** Resolves when the queue is empty, or after timeoutMs (used on shutdown). */
        flush(timeoutMs = 5000) {
            if (!running && queue.length === 0) {
                return Promise.resolve(true)
            }

            return Promise.race([
                new Promise((resolve) => idleWaiters.push(() => resolve(true))),
                new Promise((resolve) => setTimeout(() => resolve(false), timeoutMs).unref?.()),
            ])
        },

        /** Stop sending. Events not delivered yet stay on disk for the next start. */
        stop() {
            stopped = true
            wake?.()
        },
    }
}
