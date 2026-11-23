<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const props = defineProps({
  domains: { type: Array, default: () => [] },
})

const { t } = useI18n()
const { showToast, translateApiError, data } = useManager()

const dns = ref({ state: 'unknown', tlds: [], custom_sites: [], records: [], query_log: false, upstream: '', logs: [] })
const loading = ref(true)
const restarting = ref(false)
const testDomain = ref('')
const testing = ref(false)
const result = ref(null)
const newRecord = ref({ name: '', target: '' })
const savingRecords = ref(false)

const running = computed(() => dns.value.state === 'running')
// The browser runs on the host, so its platform is the host OS. Windows has no per-TLD resolver with a custom port.
const hostIsWindows = /win/i.test(navigator.userAgentData?.platform || navigator.platform || '')
const hostWildcard = computed(() => data.hosts_wildcard_tlds || [])
const settings = ref({ query_log: false, upstream: '' })
const savingSettings = ref(false)

// The test answer, as one verdict: which hop failed, if any.
const verdict = computed(() => {
  const r = result.value
  if (!r) return null
  if (!r.ip) return { severity: 'error', key: 'dns.test.no_ip' }
  if (r.ip.startsWith('127.')) return { severity: 'error', key: 'dns.test.loopback' }
  if (!r.http || r.http.state === 'error') return { severity: 'error', key: 'dns.test.http_error' }
  if (r.http.state === 'up') return { severity: 'success', key: 'dns.test.ok' }
  return { severity: 'warn', key: 'dns.test.http_status' }
})

async function load() {
  try {
    dns.value = await apiGet('/api/dns')
    settings.value = { query_log: dns.value.query_log, upstream: dns.value.upstream }
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    loading.value = false
  }
}

async function restart() {
  restarting.value = true
  try {
    const res = await apiSend('POST', '/api/dns/restart', {})
    showToast('success', t(res.message_key))
    await load()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    restarting.value = false
  }
}

async function runTest() {
  if (!testDomain.value.trim()) return
  testing.value = true
  result.value = null
  try {
    result.value = await apiSend('POST', '/api/dns/test', { domain: testDomain.value.trim() })
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    testing.value = false
  }
}

// Records are saved as a whole list; the server validates and restarts dns_container.
async function saveRecords(records) {
  savingRecords.value = true
  try {
    const res = await apiSend('PUT', '/api/dns/records', { records })
    dns.value.records = res.records
    showToast('success', t(res.message_key))
    return true
  } catch (error) {
    showToast('failure', translateApiError(error))
    return false
  } finally {
    savingRecords.value = false
  }
}

async function addRecord() {
  const record = { name: newRecord.value.name.trim(), target: newRecord.value.target.trim() }
  if (!record.name || !record.target) return
  if (await saveRecords([...dns.value.records, record])) newRecord.value = { name: '', target: '' }
}

function removeRecord(name) {
  saveRecords(dns.value.records.filter((r) => r.name !== name))
}

// Query log applies on toggle; upstream has its own Save. Each sends the other's saved value.
async function saveSettings(body) {
  savingSettings.value = true
  try {
    // A changed upstream recreates the dns container, which can take a while.
    const res = await apiSend('PUT', '/api/dns/settings', body, { timeout: 180000 })
    showToast('success', t(res.message_key))
    await load()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    savingSettings.value = false
  }
}

const toggleQueryLog = () => saveSettings({ query_log: settings.value.query_log, upstream: dns.value.upstream })
const saveUpstream = () => saveSettings({ query_log: dns.value.query_log, upstream: settings.value.upstream.trim() })
const upstreamChanged = computed(() => settings.value.upstream.trim() !== (dns.value.upstream || ''))

// CoreDNS startup/reload chatter is noise here; query lines become "time  type  name  → result".
const NOISE = /\[INFO\] (Reloading|plugin\/reload)|CoreDNS-|linux\/|^\S+\s+\S+\.:\d+$/
const logLines = computed(() =>
  dns.value.logs
    .filter((line) => !NOISE.test(line))
    .map((line) => {
      const time = (line.match(/T(\d\d:\d\d:\d\d)/) || [])[1] || ''
      const q = line.match(/"(\w+) IN (\S+?)\.? \S+ .*?" (\w+)/)
      const rest = line.replace(/^\S+Z\s*/, '')
      return q ? `${time}  ${q[1].padEnd(5)} ${q[2]}  → ${q[3]}` : `${time}  ${rest}`
    }),
)

onMounted(load)
// Adding/removing a domain on this page changes which custom-TLD sites get a DNS rule.
watch(() => props.domains.join(','), load)
</script>

<template>
  <section class="panel dns-panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <h2>{{ t('dns.title') }}</h2>
        <div class="panel-heading-actions">
          <Tag
            v-if="!loading"
            :severity="running ? 'success' : 'danger'"
            :value="t(running ? 'dns.state.running' : 'dns.state.stopped')"
          />
          <Button
            v-if="!loading && running"
            type="button"
            severity="secondary"
            text
            size="small"
            :label="t('dns.restart')"
            :loading="restarting"
            :disabled="restarting"
            @click="restart"
          />
        </div>
      </div>
      <Message v-if="!loading && !running" severity="error" :closable="false" class="domains-status-msg">
        {{ t('dns.down_warning') }}
        <Button type="button" size="small" :label="t('dns.restart')" :loading="restarting" :disabled="restarting" @click="restart" />
      </Message>
    </div>

    <div class="panel-body">
      <p class="status-line">{{ t('dns.summary', { tlds: dns.tlds.map((x) => `.${x}`).join(', ') }) }}</p>

      <form class="form-actions" @submit.prevent="runTest">
        <label class="dns-field">
          <span>{{ t('dns.test.label') }}</span>
          <InputText v-model="testDomain" :placeholder="t('dns.test.placeholder')" :disabled="testing" />
          <small>{{ t('dns.test.help') }}</small>
        </label>
        <!-- Empty label row so the button lines up with the inputs beside it. -->
        <div class="dns-field">
          <span aria-hidden="true">&nbsp;</span>
          <Button type="submit" :label="t('dns.test.run')" :loading="testing" :disabled="testing || !testDomain.trim()" />
        </div>
      </form>
      <Message v-if="verdict" :severity="verdict.severity" :closable="false" class="domains-status-msg">
        {{ t(verdict.key, { ip: result.ip || '—', status: result.http?.status ?? '—', ms: result.http?.ms ?? '—' }) }}
        <template v-if="!result.supported_tld"> {{ t('dns.test.unsupported_tld') }}</template>
      </Message>

      <!-- Everything below is optional; most users only need the status and the test above. -->
      <details class="dns-logs">
        <summary>{{ t('dns.advanced') }}</summary>

        <p v-if="dns.custom_sites.length" class="status-line">
          <strong>{{ t('dns.custom_sites') }}:</strong>
          <Tag v-for="d in dns.custom_sites" :key="d" severity="secondary" :value="d" class="dns-tld" />
        </p>

        <h3 class="dns-section-title dns-section">{{ t('dns.records.title') }}</h3>
        <p class="status-line">{{ t('dns.records.hint') }}</p>
        <p v-if="!dns.records.length" class="status-line dns-empty">{{ t('dns.records.empty') }}</p>
        <DataTable v-else :value="dns.records" data-key="name" size="small" class="dns-records">
          <Column field="name" :header="t('dns.records.name')" />
          <Column field="target" :header="t('dns.records.target')" />
          <Column :header="t('domains.table.actions')">
            <template #body="{ data: row }">
              <Button
                type="button"
                severity="danger"
                text
                size="small"
                :label="t('dns.records.remove')"
                :disabled="savingRecords"
                @click="removeRecord(row.name)"
              />
            </template>
          </Column>
        </DataTable>
        <form class="form-actions" @submit.prevent="addRecord">
          <label class="dns-field">
            <span>{{ t('dns.records.name') }}</span>
            <InputText v-model="newRecord.name" :placeholder="t('dns.records.name_placeholder')" :disabled="savingRecords" />
            <small>{{ t('dns.records.name_help') }}</small>
          </label>
          <label class="dns-field">
            <span>{{ t('dns.records.target') }}</span>
            <InputText v-model="newRecord.target" :placeholder="t('dns.records.target_placeholder')" :disabled="savingRecords" />
            <small>{{ t('dns.records.target_help') }}</small>
          </label>
          <!-- Empty label row so the button lines up with the inputs beside it. -->
          <div class="dns-field">
            <span aria-hidden="true">&nbsp;</span>
            <Button
              type="submit"
              :label="t('dns.records.add')"
              :loading="savingRecords"
              :disabled="savingRecords || !newRecord.name.trim() || !newRecord.target.trim()"
            />
          </div>
        </form>

        <h3 class="dns-section-title dns-section">{{ t('dns.host.title') }}</h3>
        <p v-if="hostWildcard.length" class="status-line">
          <Tag severity="success" :value="t('dns.host.active')" />
          {{ t('dns.host.active_hint', { tlds: hostWildcard.map((x) => `.${x}`).join(', ') }) }}
          {{ t('dns.host.remove_hint') }} <code>./scripts/hosts/wildcard_dns.sh remove</code>
        </p>
        <p v-else-if="hostIsWindows" class="status-line">{{ t('dns.host.windows') }}</p>
        <template v-else>
          <p class="status-line">{{ t('dns.host.hint') }}</p>
          <pre class="manual-lines">./scripts/hosts/wildcard_dns.sh install test</pre>
        </template>

        <h3 class="dns-section-title dns-section">{{ t('dns.settings.title') }}</h3>
        <div class="dns-field">
          <label class="dns-check">
            <Checkbox v-model="settings.query_log" binary :disabled="savingSettings" @change="toggleQueryLog" />
            <span>{{ t('dns.settings.query_log') }}</span>
          </label>
          <small>{{ t('dns.settings.query_log_help') }}</small>
        </div>
        <form class="form-actions" @submit.prevent="saveUpstream">
          <label class="dns-field">
            <span>{{ t('dns.settings.upstream') }}</span>
            <InputText v-model="settings.upstream" :placeholder="t('dns.settings.upstream_placeholder')" :disabled="savingSettings" />
            <small>{{ t('dns.settings.upstream_help') }}</small>
          </label>
          <!-- Empty label row so the button lines up with the inputs beside it. -->
          <div class="dns-field">
            <span aria-hidden="true">&nbsp;</span>
            <Button type="submit" :label="t('dns.settings.save')" :loading="savingSettings" :disabled="savingSettings || !upstreamChanged" />
          </div>
        </form>

        <details class="dns-logs dns-section" :open="dns.query_log">
          <summary>{{ t('dns.logs') }}</summary>
          <Button type="button" severity="secondary" text size="small" :label="t('dns.logs_refresh')" @click="load" />
          <pre class="manual-lines">{{ logLines.length ? logLines.join('\n') : t('dns.logs_empty') }}</pre>
        </details>
      </details>
    </div>
  </section>
</template>
