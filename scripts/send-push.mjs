import { createInterface } from 'node:readline'

const chunks = []
for await (const chunk of createInterface({ input: process.stdin })) {
  chunks.push(chunk)
}
const data = JSON.parse(chunks.join('\n') || '{}')
if (!data.vapid?.publicKey || !data.subs?.length) {
  process.exit(0)
}
const webpush = (await import('web-push')).default
webpush.setVapidDetails(data.vapid.subject, data.vapid.publicKey, data.vapid.privateKey)
const payload = JSON.stringify(data.payload)
await Promise.all(data.subs.map((sub) => webpush.sendNotification(sub, payload).catch(() => null)))
