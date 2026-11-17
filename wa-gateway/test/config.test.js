import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadConfig } from '../src/config.js'

const valid = {
    WA_GATEWAY_TOKEN: 'token-gateway-0123456789',
    WA_WEBHOOK_SECRET: 'secret-webhook-0123456789',
}

test('nilai bawaan sesuai spesifikasi dan host selalu 127.0.0.1', () => {
    const config = loadConfig(valid)
    assert.equal(config.host, '127.0.0.1')
    assert.equal(config.port, 3100)
    assert.equal(config.webhookUrl, 'http://127.0.0.1:8000/webhooks/baileys')
    assert.equal(config.minDelayMs, 3000)
    assert.equal(config.maxDelayMs, 8000)
    assert.equal(config.dailyCap, 200)
    assert.match(config.authDir, /wa-gateway[\\/]auth_info$/)
    assert.match(config.dataDir, /wa-gateway[\\/]data$/)
})

test('token dan secret wajib, minimal 16 karakter, dan tidak sama', () => {
    assert.throws(() => loadConfig({}), /WA_GATEWAY_TOKEN[\s\S]*WA_WEBHOOK_SECRET/)
    assert.throws(() => loadConfig({ ...valid, WA_GATEWAY_TOKEN: 'pendek' }), /WA_GATEWAY_TOKEN/)
    assert.throws(() => loadConfig({ ...valid, WA_WEBHOOK_SECRET: valid.WA_GATEWAY_TOKEN }), /harus berbeda/)
})

test('pesan error tidak memuat nilai rahasia', () => {
    try {
        loadConfig({ WA_GATEWAY_TOKEN: 'rahasia-tapi-pendek', WA_WEBHOOK_SECRET: 'x', WA_GATEWAY_PORT: 'abc' })
        assert.fail('harus error')
    } catch (error) {
        assert.ok(!error.message.includes('rahasia-tapi-pendek'))
        assert.match(error.message, /WA_GATEWAY_PORT/)
    }
})

test('jeda dan batas harian divalidasi', () => {
    assert.throws(() => loadConfig({ ...valid, WA_MIN_DELAY_MS: '9000', WA_MAX_DELAY_MS: '3000' }), /WA_MIN_DELAY_MS/)
    assert.throws(() => loadConfig({ ...valid, WA_MAX_DELAY_MS: '60000' }), /WA_MAX_DELAY_MS/)
    assert.throws(() => loadConfig({ ...valid, WA_DAILY_CAP: '-1' }), /WA_DAILY_CAP/)
    assert.throws(() => loadConfig({ ...valid, WA_WEBHOOK_URL: 'ftp://contoh' }), /WA_WEBHOOK_URL/)
    assert.equal(loadConfig({ ...valid, WA_DAILY_CAP: '0' }).dailyCap, 0)
})
