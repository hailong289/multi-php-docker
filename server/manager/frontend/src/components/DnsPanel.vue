<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
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

const dns = ref({ state: 'unknown', tlds: [], records: [], logs: [] })
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
const unsupported = computed(() =>
  dns.value.tlds.length === 0
    ? []
    : props.domains.filter((d) => !dns.value.tlds.includes(String(d).split('.').pop())),
)

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

onMounted(load)
</script>

<template>
  <section class="panel dns-panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <div>
          <h2>{{ t('dns.title') }}</h2>
        </div>
        <div class="panel-heading-actions">
          <Tag
            v-if="!loading"
            :severity="running ? 'success' : 'danger'"
            :value="t(running ? 'dns.state.running' : 'dns.state.stopped')"
          />
          <Button
            type="button"
            severity="secondary"
            outlined
            :label="t('dns.restart')"
            :loading="restarting"
            :disabled="loading || restarting"
            @click="restart"
          />
        </div>
      </div>
      <Message v-if="!loading && !running" severity="error" :closable="false" class="domains-status-msg">
        {{ t('dns.down_warning') }}
      </Message>
    </div>

    <div class="panel-body">
      <p class="status-line">
        <strong>{{ t('dns.tlds') }}:</strong>
        <Tag v-for="tld in dns.tlds" :key="tld" severity="secondary" :value="`.${tld}`" class="dns-tld" />
      </p>
      <Message v-if="unsupported.length" severity="warn" :closable="false" class="domains-status-msg">
        {{ t('dns.unsupported', { domains: unsupported.join(', ') }) }}
      </Message>

      <form class="form-actions" @submit.prevent="runTest">
        <InputText v-model="testDomain" :placeholder="t('dns.test.placeholder')" :disabled="testing" />
        <Button type="submit" :label="t('dns.test.run')" :loading="testing" :disabled="testing || !testDomain.trim()" />
      </form>
      <Message v-if="verdict" :severity="verdict.severity" :closable="false" class="domains-status-msg">
        {{ t(verdict.key, { ip: result.ip || '—', status: result.http?.status ?? '—', ms: result.http?.ms ?? '—' }) }}
        <template v-if="!result.supported_tld"> {{ t('dns.test.unsupported_tld') }}</template>
      </Message>

      <h3 class="dns-section-title">{{ t('dns.host.title') }}</h3>
      <p v-if="hostWildcard.length" class="status-line">
        <Tag severity="success" :value="t('dns.host.active')" />
        {{ t('dns.host.active_hint', { tlds: hostWildcard.map((x) => `.${x}`).join(', ') }) }}
      </p>
      <p v-else-if="hostIsWindows" class="status-line">{{ t('dns.host.windows') }}</p>
      <template v-else>
        <p class="status-line">{{ t('dns.host.hint') }}</p>
        <pre class="manual-lines">./scripts/hosts/wildcard_dns.sh install test</pre>
      </template>
      <p v-if="hostWildcard.length" class="status-line">
        {{ t('dns.host.remove_hint') }} <code>./scripts/hosts/wildcard_dns.sh remove</code>
      </p>

      <h3 class="dns-section-title">{{ t('dns.records.title') }}</h3>
      <p class="status-line">{{ t('dns.records.hint') }}</p>
      <DataTable v-if="dns.records.length" :value="dns.records" data-key="name" size="small" class="dns-records">
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
        <InputText v-model="newRecord.name" :placeholder="t('dns.records.name_placeholder')" :disabled="savingRecords" />
        <InputText v-model="newRecord.target" :placeholder="t('dns.records.target_placeholder')" :disabled="savingRecords" />
        <Button
          type="submit"
          :label="t('dns.records.add')"
          :loading="savingRecords"
          :disabled="savingRecords || !newRecord.name.trim() || !newRecord.target.trim()"
        />
      </form>

      <details class="dns-logs">
        <summary>{{ t('dns.logs') }}</summary>
        <pre class="manual-lines">{{ dns.logs.length ? dns.logs.join('\n') : t('dns.logs_empty') }}</pre>
      </details>
    </div>
  </section>
</template>
