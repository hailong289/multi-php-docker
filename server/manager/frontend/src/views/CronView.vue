<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { splitServerPath } from '../lib/frameworkPaths'
import { addToast, toastSeverityFromType } from '../lib/toast'

const { t } = useI18n()
const { data, serverEntries } = useManager()

const service = ref('')
const directoryPreset = ref('')
const directory = ref('')
const command = ref('')
const minutes = ref(1)
const jobs = ref([])
const busy = ref(false)

const serviceOptions = computed(() =>
  Object.keys(data.supervisor_services?.targets || {}).map((id) => ({ label: id, value: id })),
)

const directoryOptions = computed(() => {
  const seen = new Set()
  const options = []
  for (const { key, server } of serverEntries.value) {
    const { projectDir } = splitServerPath(server.SERVER_PATH || '')
    if (!projectDir.startsWith('/var/www/source/') || seen.has(projectDir)) continue
    seen.add(projectDir)
    const name = server.DOMAIN_NAME || server.APP_NAME || key
    options.push({ label: `${name} · ${projectDir}`, value: projectDir })
  }
  return options
})

function applyDirectoryPreset(value) {
  if (!value) return
  directoryPreset.value = value
  directory.value = value
}

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
watch(directoryOptions, (options) => {
  if (!directory.value && options[0]) applyDirectoryPreset(options[0].value)
  else if (directoryPreset.value && !options.some((item) => item.value === directoryPreset.value)) {
    directoryPreset.value = options.find((item) => item.value === directory.value)?.value || ''
  }
}, { immediate: true })
watch(directory, (value) => {
  directoryPreset.value = directoryOptions.value.find((item) => item.value === value)?.value || ''
})
onMounted(load)
</script>

<template>
  <section class="panel" data-tour="cron-panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <div>
          <h2>{{ t('cron.title') }}</h2>
          <p>{{ t('cron.subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <Button
            type="button"
            :label="t('sequence.save')"
            :loading="busy"
            :disabled="busy || !service || !directory.trim() || !command.trim()"
            @click="createJob"
          />
        </div>
      </div>
    </div>

    <div class="panel-body cron-body">
      <Message severity="info" :closable="false">{{ t('cron.hint') }}</Message>

      <div class="cron-form">
        <label class="cron-field">
          <span>{{ t('cron.service') }}</span>
          <Select
            v-model="service"
            :options="serviceOptions"
            option-label="label"
            option-value="value"
            fluid
          />
        </label>
        <label class="cron-field cron-field-minutes">
          <span>{{ t('cron.minutes') }}</span>
          <InputNumber v-model="minutes" :min="1" :max="60" fluid />
        </label>
        <label class="cron-field cron-field-wide">
          <span>{{ t('cron.directory') }}</span>
          <div class="cron-directory-row">
            <Select
              :model-value="directoryPreset"
              :options="directoryOptions"
              option-label="label"
              option-value="value"
              :placeholder="t('cron.directory_placeholder')"
              :disabled="!directoryOptions.length"
              class="cron-directory-pick"
              @update:model-value="applyDirectoryPreset"
            />
            <InputText
              v-model="directory"
              fluid
              :placeholder="t('form.project_dir_placeholder')"
            />
          </div>
        </label>
        <label class="cron-field cron-field-wide">
          <span>{{ t('cron.command') }}</span>
          <InputText
            v-model="command"
            fluid
            :placeholder="t('cron.command_placeholder')"
          />
        </label>
      </div>

      <DataTable :value="jobs" data-key="name" striped-rows>
        <template #empty>{{ t('cron.empty') }}</template>
        <Column field="directory" :header="t('cron.directory')" />
        <Column field="command" :header="t('cron.command')" />
        <Column field="minutes" :header="t('cron.minutes')" style="width: 8rem" />
        <Column :header="t('domains.table.actions')" style="width: 7rem">
          <template #body="{ data: row }">
            <Button
              type="button"
              :label="t('sequence.remove')"
              size="small"
              severity="danger"
              text
              :disabled="busy"
              @click="remove(row.name)"
            />
          </template>
        </Column>
      </DataTable>
    </div>
  </section>
</template>

<style scoped>
.cron-body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.cron-form {
  display: grid;
  grid-template-columns: minmax(12rem, 1fr) 8rem;
  gap: 0.85rem 1rem;
  align-items: end;
}

.cron-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 0;
}

.cron-field > span {
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--p-text-muted-color, var(--muted, #64748b));
}

.cron-field-wide {
  grid-column: 1 / -1;
}

.cron-directory-row {
  display: grid;
  grid-template-columns: minmax(12rem, 0.9fr) minmax(14rem, 1.2fr);
  gap: 0.5rem;
  align-items: center;
}

@media (max-width: 640px) {
  .cron-form {
    grid-template-columns: 1fr;
  }

  .cron-directory-row {
    grid-template-columns: 1fr;
  }
}
</style>
