import { createServer } from 'node:http'
import { bearerTokenMatches, isValidPhone, MAX_TEXT_LENGTH } from './util.js'

const MAX_BODY_BYTES = 32 * 1024
const CLIENT_REF_PATTERN = /^[A-Za-z0-9:._-]{1,64}$/
const ROUTES = new Set(['/health', '/qr', '/send', '/logout'])

function reply(res, status, body) {
    const payload = JSON.stringify(body)
    res.writeHead(status, {
        'Content-Type': 'application/json; charset=utf-8',
        'Content-Length': Buffer.byteLength(payload),
        'Cache-Control': 'no-store',
        'X-Content-Type-Options': 'nosniff',
    })
    res.end(payload)
}

/** Parsed JSON body, or null when it is missing, too large or not JSON. */
function readJson(req) {
    return new Promise((resolve) => {
        const chunks = []
        let size = 0
        let tooLarge = false
        req.on('data', (chunk) => {
            size += chunk.length
            if (size > MAX_BODY_BYTES) {
                tooLarge = true
            } else if (!tooLarge) {
                chunks.push(chunk)
            }
        })
        req.on('end', () => {
            if (tooLarge) {
                resolve(null)

                return
            }
            try {
                resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')))
            } catch {
                resolve(null)
            }
        })
        req.on('error', () => resolve(null))
    })
}

/** { to, text, clientRef } for a valid POST /send body, otherwise null. */
export function parseSendRequest(body) {
    if (!body || typeof body !== 'object' || Array.isArray(body)) {
        return null
    }
    const { to, text } = body
    const clientRef = body.client_ref ?? null
    if (!isValidPhone(to) || typeof text !== 'string' || text.trim() === '' || text.length > MAX_TEXT_LENGTH) {
        return null
    }
    if (clientRef !== null && (typeof clientRef !== 'string' || !CLIENT_REF_PATTERN.test(clientRef))) {
        return null
    }

    return { to, text, clientRef }
}

/**
 * HTTP API for Laravel. Every route needs "Authorization: Bearer <WA_GATEWAY_TOKEN>".
 *   GET  /health -> { state, phone, sent_today, webhook_backlog, webhook_failed }
 *   GET  /qr     -> { qr }  raw QR string while state is "qr", otherwise null
 *   POST /send   -> see createSendQueue() for the answers
 *   POST /logout -> unlink the device and delete auth_info
 * Bodies and message text are never logged.
 */
export function createGatewayServer({ token, session, queue, counter, webhooks, logger }) {
    const route = async (req, res, path) => {
        if (!bearerTokenMatches(req.headers.authorization, token)) {
            req.resume()
            reply(res, 401, { error: 'unauthorized' })

            return
        }

        switch (`${req.method} ${path}`) {
            case 'GET /health':
                reply(res, 200, {
                    state: session.state,
                    phone: session.phone,
                    sent_today: counter.count(),
                    // Events Laravel has not accepted yet, and events set aside for manual review.
                    webhook_backlog: webhooks.pending(),
                    webhook_failed: webhooks.failedCount(),
                })

                return
            case 'GET /qr':
                reply(res, 200, { qr: session.state === 'qr' ? session.qr : null })

                return
            case 'POST /send': {
                const input = parseSendRequest(await readJson(req))
                if (!input) {
                    reply(res, 400, { error: 'invalid' })

                    return
                }
                const result = await queue.submit(input)
                reply(res, result.status, result.body)

                return
            }
            case 'POST /logout':
                req.resume()
                await session.logout()
                reply(res, 200, { ok: true })

                return
            default:
                req.resume()
                reply(res, ROUTES.has(path) ? 405 : 404, { error: ROUTES.has(path) ? 'method_not_allowed' : 'not_found' })
        }
    }

    return createServer((req, res) => {
        const started = Date.now()
        let path = '/'
        try {
            path = new URL(req.url ?? '/', 'http://127.0.0.1').pathname
        } catch {
            // Keep "/" for a malformed URL; it answers 404 after authentication.
        }
        route(req, res, path)
            .catch((error) => {
                logger.error({ path, err: error?.message }, 'Kesalahan di HTTP gateway')
                if (!res.headersSent) {
                    reply(res, 500, { error: 'internal' })
                }
            })
            .finally(() => {
                logger.debug({ method: req.method, path, status: res.statusCode, ms: Date.now() - started }, 'HTTP')
            })
    })
}
