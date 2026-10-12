import { spawn } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const php = spawn('php', ['-S', '127.0.0.1:8787', '-t', 'server', 'server/router.php'], {
  cwd: root,
  stdio: 'inherit',
})
const viteBin = process.platform === 'win32' ? 'npx.cmd' : 'npx'
const vite = spawn(viteBin, ['vite'], {
  cwd: root,
  stdio: 'inherit',
  shell: process.platform === 'win32',
})

function shutdown(code = 0) {
  if (!php.killed) php.kill()
  if (!vite.killed) vite.kill()
  process.exit(code)
}

php.on('exit', (code) => {
  if (code && code !== 0) shutdown(code)
})
vite.on('exit', (code) => shutdown(code ?? 0))
process.on('SIGINT', () => shutdown(0))
process.on('SIGTERM', () => shutdown(0))
