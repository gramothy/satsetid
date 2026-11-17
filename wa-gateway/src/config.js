import { existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

export const GATEWAY_ROOT = fileURLToPath(new URL('..', import.meta.url))
export const AUTH_DIR = fileURLToPath(new URL('../auth_info', import.meta.url))
/** Webhook events waiting for Laravel (contains message text; not committed). */
export const DATA_DIR = fileURLToPath(new URL('../data', import.meta.url))

const ENV_FILE = fileURLToPath(new URL('../.env', import.meta.url))

/**
 * Load wa-gateway/.env when present. The gateway never reads Laravel's .env: the token
 * and webhook secret are copied into both files on purpose.
 */
export function loadEnvFile(file = ENV_FILE) {
    if (!existsSync(file)) {
        return
    }
    if (typeof process.loadEnvFile !== 'function') {
        console.warn(`Node ${process.versions.node} belum bisa membaca wa-gateway/.env (butuh Node 20.12+). Perbarui Node, atau set variabel WA_* di terminal.`)

        return
    }
    process.loadEnvFile(file)
}

function integer(value, fallback, min, max) {
    if (value === undefined || value === '') {
        return fallback
    }
    const number = Number(value)
    if (!Number.isInteger(number) || number < min || number > max) {
        throw new Error(`nilai harus bilangan bulat ${min} sampai ${max}`)
    }

    return number
}

/**
 * Validated settings from the environment. Throws an Error whose message lists every
 * problem (never the secret values themselves).
 */
export function loadConfig(env = process.env) {
    const problems = []
    const read = (name, fallback, min, max) => {
        try {
            return integer(env[name], fallback, min, max)
        } catch (error) {
            problems.push(`${name}: ${error.message}`)

            return fallback
        }
    }

    const port = read('WA_GATEWAY_PORT', 3100, 1, 65535)
    // Capped at 15 s so POST /send is always answered before Laravel's 30 s timeout.
    const minDelayMs = read('WA_MIN_DELAY_MS', 3000, 0, 15000)
    const maxDelayMs = read('WA_MAX_DELAY_MS', 8000, 0, 15000)
    const dailyCap = read('WA_DAILY_CAP', 200, 0, 100000)
    const token = (env.WA_GATEWAY_TOKEN ?? '').trim()
    const webhookSecret = (env.WA_WEBHOOK_SECRET ?? '').trim()
    const webhookUrl = (env.WA_WEBHOOK_URL ?? '').trim() || 'http://127.0.0.1:8000/webhooks/baileys'

    if (token.length < 16) {
        problems.push('WA_GATEWAY_TOKEN wajib diisi, minimal 16 karakter (samakan dengan WHATSAPP_GATEWAY_TOKEN di Laravel)')
    }
    if (webhookSecret.length < 16) {
        problems.push('WA_WEBHOOK_SECRET wajib diisi, minimal 16 karakter (samakan dengan WHATSAPP_WEBHOOK_SECRET di Laravel)')
    }
    if (token !== '' && token === webhookSecret) {
        problems.push('WA_GATEWAY_TOKEN dan WA_WEBHOOK_SECRET harus berbeda')
    }
    if (!/^https?:\/\/[^\s]+$/i.test(webhookUrl)) {
        problems.push('WA_WEBHOOK_URL harus berupa URL http:// atau https://')
    }
    if (minDelayMs > maxDelayMs) {
        problems.push('WA_MIN_DELAY_MS tidak boleh lebih besar dari WA_MAX_DELAY_MS')
    }

    if (problems.length > 0) {
        throw new Error(`Konfigurasi wa-gateway tidak valid:\n- ${problems.join('\n- ')}`)
    }

    return Object.freeze({
        host: '127.0.0.1',
        port,
        token,
        webhookUrl,
        webhookSecret,
        minDelayMs,
        maxDelayMs,
        dailyCap,
        authDir: AUTH_DIR,
        dataDir: DATA_DIR,
    })
}
