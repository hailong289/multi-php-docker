// Copies the Docker stack (tracked files only) into src-tauri/stack, which the app bundles as a resource.
// Runs before every `tauri build` / `tauri dev` (see tauri.conf.json).
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { chmodSync, copyFileSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const desktop = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const repo = resolve(desktop, '..')
const out = join(desktop, 'src-tauri', 'stack')

// Everything the containers and the Manager image need; user code (server/source) is mounted from SOURCE_DIR.
const PATHS = [
  'docker-compose.yml',
  'env.example.json',
  'compose',
  'docker_files',
  'configs',
  'scripts',
  'nginx',
  'mysql',
  'postgres',
  'server/manager',
  'server/source/.gitkeep',
  'server/backups',
  'logs',
]

const files = execFileSync('git', ['ls-files', '-z', '--', ...PATHS], { cwd: repo, encoding: 'utf8' })
  .split('\0')
  .filter((f) => f && !f.startsWith('server/manager/backend/tests/'))

rmSync(out, { recursive: true, force: true })
const hash = createHash('sha256')
for (const file of files) {
  const from = join(repo, file)
  const to = join(out, file)
  mkdirSync(dirname(to), { recursive: true })
  copyFileSync(from, to)
  chmodSync(to, statSync(from).mode)
  hash.update(file).update('\0').update(readFileSync(from))
}
// The app reinstalls the stack only when this changes.
writeFileSync(join(out, '.stack-version'), hash.digest('hex').slice(0, 16) + '\n')
console.log(`stack: ${files.length} files → ${out}`)
