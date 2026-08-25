import assert from 'node:assert/strict'
import {
  HOSTS_PROTOCOL_WINDOW,
  hostsProtocolUrl,
  isHostsProtocolPlatform,
  launchHostsWriteProtocol,
  normalizeHostsWriteToken,
} from './hostsProtocol.js'

assert.equal(normalizeHostsWriteToken('DEADBEEFcafe'), 'deadbeefcafe')
assert.equal(normalizeHostsWriteToken('nope!'), '')
assert.equal(hostsProtocolUrl('deadbeefcafebabe'), 'multi-php-hosts://write?id=deadbeefcafebabe')
assert.equal(hostsProtocolUrl(''), 'multi-php-hosts://write')
assert.equal(isHostsProtocolPlatform('Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'Win32'), true)
assert.equal(isHostsProtocolPlatform('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 'MacIntel'), true)
assert.equal(isHostsProtocolPlatform('Mozilla/5.0 (X11; Linux x86_64)', 'Linux x86_64'), false)

const clicks = []
let createdIframe = false
const loc = { href: 'http://127.0.0.1:8080/server-manage/domains' }
const launched = launchHostsWriteProtocol(
  {
    navigator: { userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', platform: 'Win32' },
    location: loc,
    document: {
      body: {
        appendChild() {},
      },
      createElement(tag) {
        if (tag === 'iframe') createdIframe = true
        const el = {
          href: '',
          target: '',
          rel: '',
          style: {},
          click() {
            clicks.push({ tag, href: el.href, target: el.target })
          },
          remove() {},
        }
        return el
      },
    },
  },
  'aabbccddeeff0011',
)

assert.equal(launched, true)
assert.equal(createdIframe, false, 'Chromium blocks custom protocols in hidden iframes')
assert.equal(
  loc.href,
  'http://127.0.0.1:8080/server-manage/domains',
  'must not navigate the current Manager page away',
)
assert.deepEqual(clicks, [
  {
    tag: 'a',
    href: 'multi-php-hosts://write?id=aabbccddeeff0011',
    target: HOSTS_PROTOCOL_WINDOW,
  },
])

console.log('OK: hostsProtocol checks')
