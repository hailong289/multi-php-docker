<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { addToast, toastSeverityFromType } from '../lib/toast'

const { t } = useI18n()
const router = useRouter()
const { data } = useManager()

const service = ref('')
const directory = ref('/var/www/source/')
const command = ref('php artisan schedule:run')
const minutes = ref(1)
const jobs = ref([])
const busy = ref(false)

const serviceOptions = computed(() =>
  Object.keys(data.supervisor_services?.targets || {}).map((id) => ({ label: id, value: id })),
)

function toast(type, detail) {
  addToast({ severity: toastSeverityFromType(type), summary: detail })
}

async function load() {
  if (!service.value) {
    jobs.value = []
    return
  }
  try {
    const payload = await apiGet(`/api/crons/${encodeURIComponent(service.value)}`)
    jobs.value = payload.jobs || []
  } catch (error) {
    toast('error', error?.payload?.error?.key || t('cron.invalid_command'))
  }
}

async function createJob() {
  busy.value = true
  try {
    const result = await apiSend('POST', `/api/crons/${encodeURIComponent(service.value)}`, {
      directory: directory.value,
      command: command.value,
      minutes: Number(minutes.value),
    })
    toast('success', t(result.message_key || 'cron.saved'))
    await load()
  } catch (error) {
    const key = error?.payload?.error?.key
    toast('error', key ? t(key) : t('cron.invalid_command'))
  } finally {
    busy.value = false
  }
}

async function remove(name) {
  busy.value = true
  try {
    await apiSend('DELETE', `/api/crons/${encodeURIComponent(service.value)}/${encodeURIComponent(name)}`)
    toast('success', t('cron.deleted'))
    await load()
  } catch (error) {
    toast('error', error?.payload?.error?.key || t('cron.invalid_command'))
  } finally {
    busy.value = false
  }
}

watch(service, load)
watch(serviceOptions, (options) => {
  if (!service.value && options[0]) service.value = options[0].value
}, { immediate: true })
onMounted(load)
</script>

<template>
  <section class="page">
    <h1>{{ t('cron.title') }}</h1>
    <div class="form-row">
      <label>
        Supervisor
        <Select v-model="service" :options="serviceOptions" option-label="label" option-value="value" />
      </label>
      <label>
        {{ t('cron.directory') }}
        <InputText v-model="directory" />
      </label>
      <label>
        {{ t('cron.command') }}
        <InputText v-model="command" />
      </label>
      <Button :label="t('cron.laravel')" severity="secondary" @click="command = 'php artisan schedule:run'" />
      <label>
        {{ t('cron.minutes') }}
        <InputNumber v-model="minutes" :min="1" :max="60" />
      </label>
      <Button :label="t('sequence.save')" :disabled="busy || !service" @click="createJob" />
      <Button :label="t('nav.php_versions')" severity="secondary" @click="router.push({ name: 'php-versions' })" />
    </div>
    <p>{{ t('cron.saved') }}</p>
    <DataTable :value="jobs" data-key="name">
      <Column field="directory" :header="t('cron.directory')" />
      <Column field="command" :header="t('cron.command')" />
      <Column field="minutes" :header="t('cron.minutes')" />
      <Column>
        <template #body="{ data: row }">
          <Button :label="t('sequence.remove')" size="small" severity="danger" :disabled="busy" @click="remove(row.name)" />
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
