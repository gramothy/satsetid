import { pickDelay } from './util.js'

/**
 * Laravel waits up to 30 seconds for POST /send. Every request is answered inside this
 * budget: a message that could only start after it is refused with 503 "busy" (never
 * sent), so Laravel keeps it pending instead of ending in an ambiguous timeout.
 */
export const RESPONSE_BUDGET_MS = 25000

/** Time reserved for the WhatsApp round trip once the pause is over. */
export const SEND_ALLOWANCE_MS = 5000

const DAY_MS = 24 * 60 * 60 * 1000

/** Error thrown by the session's sendText(). `code` decides the HTTP answer. */
export class SendError extends Error {
    /**
     * @param {'not_sent' | 'not_on_whatsapp'} code
     * @param {string} reason short, content-free reason
     */
    constructor(code, reason, options = undefined) {
        super(reason, options)
        this.name = 'SendError'
        this.code = code
        this.reason = reason
    }
}

const defaultSleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

const answer = (status, body) => ({ status, body })

/**
 * One FIFO queue with a single sender. Before each message it waits a random pause
 * between minDelayMs and maxDelayMs, and it stops at the daily cap.
 *
 * Results always resolve (never reject) to { status, body }:
 *   200 { message_id }            sent
 *   409 { error: 'send_uncertain' } WhatsApp may have the message; do not retry automatically
 *   422 { error: 'not_on_whatsapp' }
 *   429 { error: 'daily_cap' }
 *   503 { error: 'not_connected' | 'busy' | 'lookup_failed' } not sent, safe to retry later
 *
 * A client_ref that was already sent returns the same message_id again instead of sending twice.
 */
export function createSendQueue({
    minDelayMs,
    maxDelayMs,
    dailyCap,
    counter,
    isConnected,
    sendText,
    logger = null,
    random = Math.random,
    sleep = defaultSleep,
    now = Date.now,
    idempotencyTtlMs = DAY_MS,
    idempotencyLimit = 2000,
}) {
    const jobs = []
    const inFlight = new Map()
    const done = new Map()
    let running = false
    let sending = false
    let closed = false
    let idleWaiters = []

    const pending = () => jobs.length + (sending ? 1 : 0)

    const remember = (clientRef, result) => {
        done.set(clientRef, { result, expiresAt: now() + idempotencyTtlMs })
        while (done.size > idempotencyLimit) {
            done.delete(done.keys().next().value)
        }
    }

    const recall = (clientRef) => {
        const entry = done.get(clientRef)
        if (!entry) {
            return null
        }
        if (entry.expiresAt <= now()) {
            done.delete(clientRef)

            return null
        }

        return entry.result
    }

    const failure = (error, job) => {
        if (error?.code === 'not_on_whatsapp') {
            return answer(422, { error: 'not_on_whatsapp' })
        }
        if (error?.code === 'not_sent') {
            return answer(503, { error: error.reason === 'lookup_failed' ? 'lookup_failed' : 'not_connected' })
        }
        // Baileys failed after it may have written the message to WhatsApp.
        logger?.warn({ client_ref: job.clientRef, err: error?.message }, 'Hasil kirim tidak pasti; tidak dikirim ulang otomatis')

        return answer(409, { error: 'send_uncertain' })
    }

    const deliverJob = async (job) => {
        if (closed || !isConnected()) {
            return answer(503, { error: 'not_connected' })
        }
        if (counter.count() >= dailyCap) {
            return answer(429, { error: 'daily_cap' })
        }
        const delay = pickDelay(minDelayMs, maxDelayMs, random)
        if (now() + delay > job.enqueuedAt + RESPONSE_BUDGET_MS - SEND_ALLOWANCE_MS) {
            return answer(503, { error: 'busy' })
        }
        await sleep(delay)
        if (closed || !isConnected()) {
            return answer(503, { error: 'not_connected' })
        }
        try {
            const messageId = await sendText(job.to, job.text)
            counter.increment()
            const result = answer(200, { message_id: messageId })
            if (job.clientRef) {
                remember(job.clientRef, result)
            }

            return result
        } catch (error) {
            return failure(error, job)
        }
    }

    const drain = async () => {
        if (running) {
            return
        }
        running = true
        try {
            while (jobs.length > 0) {
                const job = jobs.shift()
                sending = true
                try {
                    job.resolve(await deliverJob(job))
                } catch (error) {
                    job.resolve(failure(error, job))
                } finally {
                    sending = false
                }
            }
        } finally {
            running = false
            const waiters = idleWaiters
            idleWaiters = []
            waiters.forEach((resolve) => resolve())
        }
    }

    return {
        /** Queue one text message. Resolves when it was sent or refused. */
        submit({ to, text, clientRef = null }) {
            if (clientRef) {
                const previous = recall(clientRef)
                if (previous) {
                    return Promise.resolve(previous)
                }
                if (inFlight.has(clientRef)) {
                    return inFlight.get(clientRef)
                }
            }
            if (closed || !isConnected()) {
                return Promise.resolve(answer(503, { error: 'not_connected' }))
            }
            if (counter.count() + pending() >= dailyCap) {
                return Promise.resolve(answer(429, { error: 'daily_cap' }))
            }

            const promise = new Promise((resolve) => {
                jobs.push({ to, text, clientRef, enqueuedAt: now(), resolve })
            })
            if (clientRef) {
                inFlight.set(clientRef, promise)
                promise.then(() => inFlight.delete(clientRef))
            }
            drain()

            return promise
        },

        /** Messages waiting or being sent right now. */
        pending,

        /** Refuse queued messages (not sent yet) and accept no new ones. */
        close() {
            closed = true
            while (jobs.length > 0) {
                jobs.shift().resolve(answer(503, { error: 'not_connected' }))
            }
        },

        /** Resolves once the message being sent (if any) has finished. */
        idle() {
            return running ? new Promise((resolve) => idleWaiters.push(resolve)) : Promise.resolve()
        },
    }
}
