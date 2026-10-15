<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Select from 'primevue/select'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { addToast, toastSeverityFromType } from '../lib/toast'

const { t } = useI18n()
const { serverEntries } = useManager()

const engine = ref('mysql')
const database = ref('')
const serverKey = ref('')
const names = ref([])
const dumps = ref([])
const busy = ref(false)
let timer = 0

const engineOptions = [
  { label: 'MySQL', value: 'mysql' },
  { label: 'PostgreSQL', value: 'postgres' },
]

const serverOptions = computed(() => [
  { label: '—', value: '' },
  ...serverEntries.value.map((item) => ({
    label: item.server?.DOMAIN_NAME || item.key,
    value: item.key,
  })),
])

const nameOptions = computed(() => names.value.map((name) => ({ label: name, value: name })))
const pending = computed(() => dumps.value.some((row) => row.status === 'pending'))

function toast(type, detail) {
  addToast({ severity: toastSeverityFromType(type), summary: detail })
}

function errorText(error) {
  const key = error?.payload?.error?.key || error?.message || ''
  if (key === 'database.container_stopped') return t('databases.container_stopped')
  return key || t('databases.failed')
}

async function loadNames() {
  names.value = []
  database.value = ''
  try {
    const payload = await apiGet(`/api/databases/${engine.value}/names`)
    names.value = payload.databases || []
    database.value = names.value[0] || ''
  } catch (error) {
    toast('error', errorText(error))
  }
}

async function loadDumps() {
  try {
    const payload = await apiGet('/api/databases/dumps')
    dumps.value = payload.dumps || []
  } catch (error) {
    toast('error', errorText(error))
  }
}

async function dump() {
  if (!database.value) return
  busy.value = true
  try {
    const body = { engine: engine.value, database: database.value }
    if (serverKey.value) body.server_key = serverKey.value
    await apiSend('POST', '/api/databases/dumps', body)
    await loadDumps()
  } catch (error) {
    toast('error', errorText(error))
  } finally {
    busy.value = false
  }
}

async function restore(id) {
  busy.value = true
  try {
    await apiSend('POST', `/api/databases/dumps/${id}/restore`, {})
    await loadDumps()
  } catch (error) {
    toast('error', errorText(error))
  } finally {
    busy.value = false
  }
}

watch(engine, loadNames)
watch(pending, (value) => {
  window.clearInterval(timer)
  if (value) timer = window.setInterval(loadDumps, 2000)
})

onMounted(async () => {
  await Promise.all([loadNames(), loadDumps()])
})
onUnmounted(() => window.clearInterval(timer))
</script>

<template>
  <section class="page">
    <h1>{{ t('databases.title') }}</h1>
    <div class="form-row">
      <label>
        {{ t('databases.engine') }}
        <Select v-model="engine" :options="engineOptions" option-label="label" option-value="value" />
      </label>
      <label>
        {{ t('databases.name') }}
        <Select v-model="database" :options="nameOptions" option-label="label" option-value="value" />
      </label>
      <label>
        {{ t('databases.server') }}
        <Select v-model="serverKey" :options="serverOptions" option-label="label" option-value="value" />
      </label>
      <Button :label="t('databases.dump')" :disabled="busy || !database" @click="dump" />
    </div>
    <DataTable :value="dumps" data-key="id">
      <template #empty>{{ t('databases.empty') }}</template>
      <Column field="engine" :header="t('databases.engine')" />
      <Column field="database" :header="t('databases.name')" />
      <Column field="server_key" :header="t('databases.server')" />
      <Column field="created_at" header="UTC" />
      <Column :header="t('databases.pending')">
        <template #body="{ data: row }">
          {{ t(`databases.${row.status}`) }}
        </template>
      </Column>
      <Column>
        <template #body="{ data: row }">
          <Button
            :label="t('databases.restore')"
            size="small"
            :disabled="busy || row.status !== 'ready'"
            @click="restore(row.id)"
          />
        </template>
      </Column>
    </DataTable>
  </section>
</template>

<style scoped>
.form-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: end;
  margin-bottom: 1rem;
}
label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
</style>
