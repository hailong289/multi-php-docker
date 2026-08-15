import assert from 'node:assert/strict'
import {
  CUSTOM_TLD,
  LOCAL_TLDS,
  composeLocalDomain,
  parseLocalDomain,
} from './localDomain.js'

assert.deepEqual(LOCAL_TLDS, ['.test', '.local'])
assert.equal(CUSTOM_TLD, 'custom')
assert.deepEqual(parseLocalDomain('ung-dung.test'), { name: 'ung-dung', tld: '.test', custom: '' })
assert.deepEqual(parseLocalDomain('API.Shop.local'), { name: 'api.shop', tld: '.local', custom: '' })
assert.deepEqual(parseLocalDomain('shop.lan'), { name: 'shop', tld: 'custom', custom: '.lan' })
assert.deepEqual(parseLocalDomain('ung-dung'), { name: 'ung-dung', tld: '.test', custom: '' })
assert.equal(composeLocalDomain('ung-dung', '.test'), 'ung-dung.test')
assert.equal(composeLocalDomain('Ung-Dung', '.LOCAL'), 'ung-dung.local')
assert.equal(composeLocalDomain('ung-dung.test', '.local'), 'ung-dung.local')
assert.equal(composeLocalDomain('  ung-dung.  ', '.test'), 'ung-dung.test')
assert.equal(composeLocalDomain('', '.test'), '')
assert.equal(composeLocalDomain('shop', 'custom', '.lan'), 'shop.lan')
assert.equal(composeLocalDomain('shop', 'custom', 'lan'), 'shop.lan')
assert.equal(composeLocalDomain('ung-dung', '.com'), 'ung-dung.com')
assert.equal(composeLocalDomain('shop', 'custom', ''), '')
assert.equal(composeLocalDomain('shop.lan', 'custom', '.lan'), 'shop.lan')

console.log('OK: localDomain checks')
