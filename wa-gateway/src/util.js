// Pure helpers for the gateway. Nothing here talks to WhatsApp or the network,
// so every function can be unit tested with `node --test`.
import { createHash, createHmac, timingSafeEqual } from 'node:crypto'

/** E.164 digits without "+", the same shape Laravel stores (62..., 1...). */
export const PHONE_PATTERN = /^[1-9]\d{7,14}$/

/** Longest text forwarded to Laravel or accepted for sending (the chatbot reads 1000). */
export const MAX_TEXT_LENGTH = 4096

/** WIB (Asia/Jakarta) is UTC+7 all year; the daily cap resets at WIB midnight. */
export const WIB_OFFSET_MS = 7 * 60 * 60 * 1000

/**
 * proto.WebMessageInfo.Status in Baileys 7.0.0-rc14. Copied so the mapping stays a pure
 * function; test/util.test.js checks these values against the installed library.
 */
export const WA_MESSAGE_STATUS = Object.freeze({
    ERROR: 0,
    PENDING: 1,
    SERVER_ACK: 2,
    DELIVERY_ACK: 3,
    READ: 4,
    PLAYED: 5,
})

export function isValidPhone(value) {
    return typeof value === 'string' && PHONE_PATTERN.test(value)
}

/** "6281234567890" -> "6281234567890@s.whatsapp.net", or null for anything else. */
export function phoneToJid(phone) {
    return isValidPhone(phone) ? `${phone}@s.whatsapp.net` : null
}

/**
 * Phone number of a phone-number JID ("62812...@s.whatsapp.net", also with a device
 * suffix such as "62812...:3@s.whatsapp.net"). LIDs, groups and other servers give null.
 */
export function jidToPhone(jid) {
    if (typeof jid !== 'string') {
        return null
    }
    const at = jid.indexOf('@')
    if (at < 1) {
        return null
    }
    const server = jid.slice(at + 1)
    if (server !== 's.whatsapp.net' && server !== 'c.us') {
        return null
    }
    const user = jid.slice(0, at).split(':')[0].split('_')[0]

    return isValidPhone(user) ? user : null
}

export function isLidJid(jid) {
    return typeof jid === 'string' && /@(hosted\.)?lid$/.test(jid)
}

/**
 * Sender phone number of a direct chat. In Baileys 7 a chat can be addressed by LID
 * ("...@lid"); the phone number then travels in key.remoteJidAlt. Without it the
 * sender cannot be matched to a queue ticket, so the caller drops the message.
 */
export function resolveSenderPhone(key) {
    const remoteJid = key?.remoteJid
    if (isLidJid(remoteJid)) {
        return jidToPhone(key?.remoteJidAlt)
    }

    return jidToPhone(remoteJid)
}

/**
 * Plain text of an incoming message: `conversation` or `extendedTextMessage.text`,
 * also inside the disappearing / view-once / document-with-caption wrappers.
 * Media, stickers, reactions, edits and protocol messages give null.
 */
export function extractText(message) {
    let content = message
    for (let depth = 0; depth < 5 && content; depth++) {
        const wrapper = content.ephemeralMessage
            ?? content.viewOnceMessage
            ?? content.viewOnceMessageV2
            ?? content.viewOnceMessageV2Extension
            ?? content.documentWithCaptionMessage
        if (!wrapper?.message) {
            break
        }
        content = wrapper.message
    }

    const text = typeof content?.conversation === 'string' && content.conversation !== ''
        ? content.conversation
        : content?.extendedTextMessage?.text
    if (typeof text !== 'string' || text.trim() === '') {
        return null
    }

    // Truncate by code point so an emoji is never cut in half.
    const characters = Array.from(text)

    return characters.length > MAX_TEXT_LENGTH ? characters.slice(0, MAX_TEXT_LENGTH).join('') : text
}

/** Unix seconds from a Baileys timestamp (number, protobuf Long or numeric string). */
export function toUnixSeconds(value, fallbackSeconds) {
    let seconds = null
    if (typeof value === 'number') {
        seconds = value
    } else if (typeof value === 'bigint') {
        seconds = Number(value)
    } else if (typeof value === 'string' && /^\d+$/.test(value)) {
        seconds = Number(value)
    } else if (value && typeof value.toNumber === 'function') {
        seconds = value.toNumber()
    } else if (value && typeof value.low === 'number' && typeof value.high === 'number') {
        seconds = (value.high >>> 0) * 2 ** 32 + (value.low >>> 0)
    }

    return Number.isSafeInteger(seconds) && seconds > 0 ? seconds : fallbackSeconds
}

function chatKind(remoteJid) {
    if (typeof remoteJid !== 'string') {
        return 'invalid'
    }
    if (remoteJid.endsWith('@g.us')) {
        return 'group'
    }
    if (remoteJid === 'status@broadcast' || remoteJid.endsWith('@broadcast')) {
        return 'broadcast'
    }
    if (remoteJid.endsWith('@newsletter')) {
        return 'newsletter'
    }

    return 'direct'
}

/**
 * Turn one message from `messages.upsert` (type "notify") into the webhook payload,
 * or explain why it is skipped. Never returns message content in `skip`.
 *
 * @returns {{ event: object } | { skip: string, id: string | null }}
 */
export function toInboundEvent(message, nowSeconds = Math.floor(Date.now() / 1000)) {
    const key = message?.key
    const id = typeof key?.id === 'string' && key.id !== '' && key.id.length <= 255 ? key.id : null
    if (!id) {
        return { skip: 'invalid', id: null }
    }
    if (key.fromMe) {
        return { skip: 'from_me', id }
    }
    const kind = chatKind(key.remoteJid)
    if (kind !== 'direct') {
        return { skip: kind, id }
    }
    const text = extractText(message.message)
    if (text === null) {
        return { skip: 'non_text', id }
    }
    const from = resolveSenderPhone(key)
    if (!from) {
        return { skip: isLidJid(key.remoteJid) ? 'lid_without_phone' : 'unknown_sender', id }
    }

    return {
        event: {
            type: 'message',
            provider_message_id: id,
            from,
            text,
            timestamp: toUnixSeconds(message.messageTimestamp, nowSeconds),
        },
    }
}

/**
 * Events of one `messages.upsert`. Only "notify" carries new messages; "append" is
 * history and is ignored as a whole.
 */
export function inboundEventsFromUpsert(upsert, nowSeconds) {
    if (upsert?.type !== 'notify' || !Array.isArray(upsert.messages)) {
        return { events: [], skipped: [] }
    }
    const events = []
    const skipped = []
    for (const message of upsert.messages) {
        const result = toInboundEvent(message, nowSeconds)
        if (result.event) {
            events.push(result.event)
        } else {
            skipped.push(result)
        }
    }

    return { events, skipped }
}

/** Baileys message status -> the status Laravel's applyDeliveryStatus() understands. */
export function mapMessageStatus(status) {
    const value = typeof status === 'string' && status in WA_MESSAGE_STATUS ? WA_MESSAGE_STATUS[status] : status
    switch (value) {
        case WA_MESSAGE_STATUS.DELIVERY_ACK:
            return 'delivered'
        case WA_MESSAGE_STATUS.READ:
        case WA_MESSAGE_STATUS.PLAYED:
            return 'read'
        case WA_MESSAGE_STATUS.ERROR:
            return 'failed'
        default:
            // PENDING and SERVER_ACK: Laravel already marks the message "sent".
            return null
    }
}

/**
 * Webhook payload for one entry of `messages.update`, or null. Only receipts for
 * messages this gateway sent (fromMe) in direct chats are forwarded.
 */
export function toStatusEvent(update) {
    const key = update?.key
    if (!key?.fromMe || typeof key.id !== 'string' || key.id === '' || chatKind(key.remoteJid) !== 'direct') {
        return null
    }
    const status = mapMessageStatus(update.update?.status)

    return status ? { type: 'status', provider_message_id: key.id, status } : null
}

/** Exponential reconnect delay: 1s, 2s, 4s, ... capped at 30s. */
export function backoffDelay(attempt, { baseMs = 1000, maxMs = 30000 } = {}) {
    const step = Math.max(0, Math.floor(attempt))

    return Math.min(maxMs, baseMs * 2 ** Math.min(step, 30))
}

/** Random pause between two outgoing messages, inclusive of both bounds. */
export function pickDelay(minMs, maxMs, random = Math.random) {
    const low = Math.max(0, Math.min(minMs, maxMs))
    const high = Math.max(minMs, maxMs)

    return low + Math.floor(random() * (high - low + 1))
}

/** Calendar day in WIB, e.g. "2026-11-17". */
export function wibDateKey(nowMs) {
    return new Date(nowMs + WIB_OFFSET_MS).toISOString().slice(0, 10)
}

/** hex(HMAC-SHA256(secret, timestamp + "." + rawBody)), checked by Laravel with hash_equals. */
export function signWebhook(secret, timestamp, rawBody) {
    return createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex')
}

/**
 * Constant-time check of an "Authorization: Bearer <token>" header. Both sides are
 * hashed first so timingSafeEqual always compares equal-length buffers.
 */
export function bearerTokenMatches(header, token) {
    if (typeof header !== 'string' || typeof token !== 'string' || token === '') {
        return false
    }
    const match = /^Bearer (.+)$/.exec(header)
    if (!match) {
        return false
    }
    const given = createHash('sha256').update(match[1]).digest()
    const expected = createHash('sha256').update(token).digest()

    return timingSafeEqual(given, expected)
}

/**
 * True when WhatsApp rejected a stanza with 479 ("smax-invalid"), which means a device of the
 * recipient has a stale session. Nothing was delivered, so the send is safe to repeat once.
 */
export function isStaleSessionError(error) {
    return error?.output?.statusCode === 479
        || error?.data === 479
        || /smax-invalid|\(479\)/.test(String(error?.message ?? ''))
}
