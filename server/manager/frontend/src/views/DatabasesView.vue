<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { confirmDialog } from '../lib/confirm'
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
  { label: t('databases.server_none'), value: '' },
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
  if (key.startsWith('databases.')) return t(key)
  return key || t('databases.failed')
}

function statusSeverity(status) {
  if (status === 'ready') return 'success'
  if (status === 'pending') return 'warn'
  if (status === 'failed') return 'danger'
  return 'secondary'
}

function statusLabel(status) {
  if (status === 'ready') return t('databases.ready')
  if (status === 'pending') return t('databases.pending')
  if (status === 'failed') return t('databases.failed')
  return status || '—'
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

async function remove(row) {
  if (
    !(await confirmDialog(t('databases.delete_confirm', { name: row.database }), {
      acceptLabel: t('action.delete'),
      rejectLabel: t('action.cancel'),
      acceptSeverity: 'danger',
    }))
  ) {
    return
  }
  busy.value = true
  try {
    const result = await apiSend('DELETE', `/api/databases/dumps/${row.id}`)
    toast('success', t(result.message_key || 'databases.deleted'))
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
  <section class="panel" data-tour="databases-panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <div>
          <h2>{{ t('databases.title') }}</h2>
          <p>{{ t('databases.subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <Button
            type="button"
            :label="t('databases.dump')"
            :loading="busy"
            :disabled="busy || !database"
            @click="dump"
          />
        </div>
      </div>
    </div>

    <div class="panel-body databases-body">
      <Message severity="info" :closable="false">{{ t('databases.hint') }}</Message>

      <div class="databases-form">
        <label class="databases-field">
          <span>{{ t('databases.engine') }}</span>
          <Select
            v-model="engine"
            :options="engineOptions"
            option-label="label"
            option-value="value"
            fluid
          />
        </label>
        <label class="databases-field">
          <span>{{ t('databases.name') }}</span>
          <Select
            v-model="database"
            :options="nameOptions"
            option-label="label"
            option-value="value"
            fluid
            :placeholder="t('databases.name_placeholder')"
            :disabled="!nameOptions.length"
          />
        </label>
        <label class="databases-field">
          <span>{{ t('databases.server') }}</span>
          <Select
            v-model="serverKey"
            :options="serverOptions"
            option-label="label"
            option-value="value"
            fluid
          />
        </label>
      </div>

      <DataTable :value="dumps" data-key="id" striped-rows>
        <template #empty>{{ t('databases.empty') }}</template>
        <Column field="engine" :header="t('databases.engine')" style="width: 7rem" />
        <Column field="database" :header="t('databases.name')" />
        <Column field="server_key" :header="t('databases.server')">
          <template #body="{ data: row }">
            {{ row.server_key || '—' }}
          </template>
        </Column>
        <Column field="created_at" :header="t('databases.created_at')" style="width: 11rem" />
        <Column :header="t('databases.status')" style="width: 8rem">
          <template #body="{ data: row }">
            <Tag :value="statusLabel(row.status)" :severity="statusSeverity(row.status)" rounded />
          </template>
        </Column>
        <Column :header="t('domains.table.actions')" style="width: 11rem">
          <template #body="{ data: row }">
            <div class="databases-actions">
              <Button
                type="button"
                :label="t('databases.restore')"
                size="small"
                text
                :disabled="busy || row.status !== 'ready'"
                @click="restore(row.id)"
              />
              <Button
                type="button"
                :label="t('action.delete')"
                size="small"
                severity="danger"
                text
                :disabled="busy"
                @click="remove(row)"
              />
            </div>
          </template>
        </Column>
      </DataTable>
    </div>
  </section>
</template>

<style scoped>
.databases-body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.databases-form {
  display: grid;
  grid-template-columns: repeat(3, minmax(10rem, 1fr));
  gap: 0.85rem 1rem;
  align-items: end;
}

.databases-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 0;
}

.databases-field > span {
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--p-text-muted-color, var(--muted, #64748b));
}

.databases-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem;
}

@media (max-width: 720px) {
  .databases-form {
    grid-template-columns: 1fr;
  }
}
</style>
