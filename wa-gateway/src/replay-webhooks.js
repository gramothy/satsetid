// Put events that Laravel rejected or did not accept in time (data/webhook-queue/failed) back
// in the queue. Fix the cause first, run `npm run webhooks:replay`, then restart the gateway.
import { join } from 'node:path'
import { DATA_DIR } from './config.js'
import { replayFailed } from './webhook.js'

const moved = replayFailed(join(DATA_DIR, 'webhook-queue'))
console.log(`${moved} event dipindahkan kembali ke antrean. Jalankan ulang wa-gateway (npm start) untuk mengirimnya.`)
