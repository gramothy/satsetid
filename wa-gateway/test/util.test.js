import assert from 'node:assert/strict'
import { createHmac } from 'node:crypto'
import { describe, test } from 'node:test'
import {
    backoffDelay,
    bearerTokenMatches,
    extractText,
    inboundEventsFromUpsert,
    jidToPhone,
    mapMessageStatus,
    phoneToJid,
    pickDelay,
    resolveSenderPhone,
    signWebhook,
    toInboundEvent,
    toStatusEvent,
    toUnixSeconds,
    WA_MESSAGE_STATUS,
    wibDateKey,
} from '../src/util.js'

const textMessage = (overrides = {}, message = { conversation: 'ANTRE ayam-bakar' }) => ({
    key: { id: 'ABC123', remoteJid: '6281234567890@s.whatsapp.net', fromMe: false, ...overrides },
    message,
    messageTimestamp: 1763370000,
})

describe('nomor dan JID', () => {
    test('nomor 62... menjadi JID s.whatsapp.net', () => {
        assert.equal(phoneToJid('6281234567890'), '6281234567890@s.whatsapp.net')
        assert.equal(phoneToJid('14155238886'), '14155238886@s.whatsapp.net')
    })

    test('nomor tidak valid tidak menjadi JID', () => {
        for (const value of ['081234567890', '+6281234567890', '62 812', '6281234567890123456', '', null, 6281234567890]) {
            assert.equal(phoneToJid(value), null, String(value))
        }
    })

    test('JID nomor (dengan atau tanpa device) menjadi nomor', () => {
        assert.equal(jidToPhone('6281234567890@s.whatsapp.net'), '6281234567890')
        assert.equal(jidToPhone('6281234567890:12@s.whatsapp.net'), '6281234567890')
        assert.equal(jidToPhone('6281234567890@c.us'), '6281234567890')
    })

    test('LID, grup, dan teks lain bukan nomor', () => {
        for (const jid of ['123456789012345@lid', '120363000000000000@g.us', 'status@broadcast', 'abc@s.whatsapp.net', undefined]) {
            assert.equal(jidToPhone(jid), null, String(jid))
        }
    })
})

describe('pemetaan LID', () => {
    test('chat biasa memakai remoteJid', () => {
        assert.equal(resolveSenderPhone({ remoteJid: '6281234567890@s.whatsapp.net' }), '6281234567890')
    })

    test('chat LID memakai remoteJidAlt', () => {
        assert.equal(resolveSenderPhone({ remoteJid: '201234567890123@lid', remoteJidAlt: '6281234567890@s.whatsapp.net' }), '6281234567890')
        assert.equal(resolveSenderPhone({ remoteJid: '201234567890123@hosted.lid', remoteJidAlt: '6281234567890:3@s.whatsapp.net' }), '6281234567890')
    })

    test('chat LID tanpa remoteJidAlt tidak punya nomor', () => {
        assert.equal(resolveSenderPhone({ remoteJid: '201234567890123@lid' }), null)
        assert.equal(resolveSenderPhone({ remoteJid: '201234567890123@lid', remoteJidAlt: '209999@lid' }), null)
    })

    test('pesan LID tanpa nomor dibuang dengan alasan dan id saja', () => {
        const result = toInboundEvent(textMessage({ remoteJid: '201234567890123@lid' }))
        assert.deepEqual(result, { skip: 'lid_without_phone', id: 'ABC123' })
    })
})

describe('ekstraksi teks', () => {
    test('conversation dan extendedTextMessage', () => {
        assert.equal(extractText({ conversation: 'YA' }), 'YA')
        assert.equal(extractText({ extendedTextMessage: { text: '2', contextInfo: {} } }), '2')
    })

    test('pembungkus pesan sementara dan view once dibuka', () => {
        assert.equal(extractText({ ephemeralMessage: { message: { extendedTextMessage: { text: 'BATAL' } } } }), 'BATAL')
        assert.equal(extractText({ viewOnceMessageV2: { message: { conversation: 'YA' } } }), 'YA')
        assert.equal(extractText({ ephemeralMessage: { message: { documentWithCaptionMessage: { message: { conversation: 'x' } } } } }), 'x')
    })

    test('media, stiker, reaksi, edit, dan teks kosong bukan teks', () => {
        for (const message of [
            { imageMessage: { caption: 'foto' } },
            { stickerMessage: {} },
            { reactionMessage: { text: '👍' } },
            { protocolMessage: { type: 14, editedMessage: { conversation: 'baru' } } },
            { conversation: '   ' },
            null,
            undefined,
        ]) {
            assert.equal(extractText(message), null, JSON.stringify(message))
        }
    })

    test('teks sangat panjang dipotong tanpa memotong emoji', () => {
        const text = extractText({ conversation: '😀'.repeat(5000) })
        assert.equal(Array.from(text).length, 4096)
        assert.ok(text.endsWith('😀'))
    })
})

describe('filter event masuk', () => {
    test('pesan teks dari chat pribadi menjadi payload webhook', () => {
        assert.deepEqual(toInboundEvent(textMessage()), {
            event: { type: 'message', provider_message_id: 'ABC123', from: '6281234567890', text: 'ANTRE ayam-bakar', timestamp: 1763370000 },
        })
    })

    test('pesan sendiri, grup, status, newsletter, dan non-teks diabaikan', () => {
        assert.equal(toInboundEvent(textMessage({ fromMe: true })).skip, 'from_me')
        assert.equal(toInboundEvent(textMessage({ remoteJid: '120363000000000000@g.us', participant: '6281234567890@s.whatsapp.net' })).skip, 'group')
        assert.equal(toInboundEvent(textMessage({ remoteJid: 'status@broadcast' })).skip, 'broadcast')
        assert.equal(toInboundEvent(textMessage({ remoteJid: '120363000000000000@newsletter' })).skip, 'newsletter')
        assert.equal(toInboundEvent(textMessage({}, { imageMessage: {} })).skip, 'non_text')
        assert.equal(toInboundEvent({ key: { remoteJid: '6281234567890@s.whatsapp.net' } }).skip, 'invalid')
    })

    test('hanya type notify yang diproses; append (riwayat) diabaikan', () => {
        const messages = [textMessage(), textMessage({ id: 'DEF', fromMe: true })]
        const notify = inboundEventsFromUpsert({ type: 'notify', messages })
        assert.equal(notify.events.length, 1)
        assert.deepEqual(notify.skipped, [{ skip: 'from_me', id: 'DEF' }])
        assert.deepEqual(inboundEventsFromUpsert({ type: 'append', messages }), { events: [], skipped: [] })
    })

    test('timestamp Long protobuf dan cadangan waktu sekarang', () => {
        assert.equal(toUnixSeconds({ low: 1763370000, high: 0, unsigned: true }, 1), 1763370000)
        assert.equal(toUnixSeconds({ toNumber: () => 1763370001 }, 1), 1763370001)
        assert.equal(toUnixSeconds('1763370002', 1), 1763370002)
        assert.equal(toUnixSeconds(null, 99), 99)
        assert.equal(toInboundEvent({ ...textMessage(), messageTimestamp: undefined }, 1234).event.timestamp, 1234)
    })
})

describe('mapping status', () => {
    test('enum Baileys ke status Laravel', () => {
        assert.equal(mapMessageStatus(WA_MESSAGE_STATUS.DELIVERY_ACK), 'delivered')
        assert.equal(mapMessageStatus(WA_MESSAGE_STATUS.READ), 'read')
        assert.equal(mapMessageStatus(WA_MESSAGE_STATUS.PLAYED), 'read')
        assert.equal(mapMessageStatus(WA_MESSAGE_STATUS.ERROR), 'failed')
        assert.equal(mapMessageStatus('DELIVERY_ACK'), 'delivered')
        assert.equal(mapMessageStatus(WA_MESSAGE_STATUS.PENDING), null)
        assert.equal(mapMessageStatus(WA_MESSAGE_STATUS.SERVER_ACK), null)
        assert.equal(mapMessageStatus(undefined), null)
    })

    test('hanya tanda terima untuk pesan keluar di chat pribadi', () => {
        const key = { id: 'OUT1', remoteJid: '6281234567890@s.whatsapp.net', fromMe: true }
        assert.deepEqual(toStatusEvent({ key, update: { status: 3 } }), { type: 'status', provider_message_id: 'OUT1', status: 'delivered' })
        assert.equal(toStatusEvent({ key: { ...key, fromMe: false }, update: { status: 4 } }), null)
        assert.equal(toStatusEvent({ key: { ...key, remoteJid: '120363000000000000@g.us' }, update: { status: 4 } }), null)
        assert.equal(toStatusEvent({ key, update: { status: 2 } }), null)
        assert.equal(toStatusEvent({ key, update: { pollUpdates: [] } }), null)
    })

    test('nilai enum sama dengan proto Baileys yang terpasang', async () => {
        const { proto } = await import('@whiskeysockets/baileys')
        for (const [name, value] of Object.entries(WA_MESSAGE_STATUS)) {
            assert.equal(proto.WebMessageInfo.Status[name], value, name)
        }
    })
})

describe('backoff dan jeda', () => {
    test('backoff eksponensial 1 detik sampai maksimal 30 detik', () => {
        assert.deepEqual([0, 1, 2, 3, 4, 5, 6, 50].map((attempt) => backoffDelay(attempt)), [1000, 2000, 4000, 8000, 16000, 30000, 30000, 30000])
    })

    test('jeda acak di antara batas bawah dan atas (inklusif)', () => {
        assert.equal(pickDelay(3000, 8000, () => 0), 3000)
        assert.equal(pickDelay(3000, 8000, () => 0.999999), 8000)
        assert.equal(pickDelay(3000, 8000, () => 0.5), 5500)
        assert.equal(pickDelay(5000, 5000, () => 0.7), 5000)
    })

    test('tanggal WIB berganti pada pukul 17.00 UTC', () => {
        assert.equal(wibDateKey(Date.UTC(2026, 10, 17, 16, 59, 59)), '2026-11-17')
        assert.equal(wibDateKey(Date.UTC(2026, 10, 17, 17, 0, 0)), '2026-11-18')
    })
})

describe('keamanan', () => {
    test('signature webhook = hex HMAC-SHA256(secret, timestamp.body)', () => {
        const body = '{"type":"message"}'
        const expected = createHmac('sha256', 'rahasia-webhook').update(`1763370000.${body}`).digest('hex')
        assert.equal(signWebhook('rahasia-webhook', '1763370000', body), expected)
    })

    test('Bearer token dibandingkan tepat', () => {
        assert.equal(bearerTokenMatches('Bearer token-yang-benar-123', 'token-yang-benar-123'), true)
        assert.equal(bearerTokenMatches('Bearer token-yang-salah-123', 'token-yang-benar-123'), false)
        assert.equal(bearerTokenMatches('Bearer token-yang-benar-1234', 'token-yang-benar-123'), false)
        assert.equal(bearerTokenMatches('token-yang-benar-123', 'token-yang-benar-123'), false)
        assert.equal(bearerTokenMatches(undefined, 'token-yang-benar-123'), false)
        assert.equal(bearerTokenMatches('Bearer ', ''), false)
    })
})

test('isStaleSessionError recognises WhatsApp 479 rejections only', async () => {
    const { isStaleSessionError } = await import('../src/util.js')
    assert.equal(isStaleSessionError({ output: { statusCode: 479 } }), true)
    assert.equal(isStaleSessionError({ data: 479 }), true)
    assert.equal(isStaleSessionError(new Error('smax-invalid (479)')), true)
    assert.equal(isStaleSessionError(new Error('Connection Closed')), false)
    assert.equal(isStaleSessionError({ output: { statusCode: 428 } }), false)
    assert.equal(isStaleSessionError(null), false)
})
