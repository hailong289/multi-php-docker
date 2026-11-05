<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import { apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { confirmDialog } from '../lib/confirm'
import { getInfraConnectionDetails, getInfraWebUrl, openInfraWeb } from '../lib/infraConnectionDetails'
import { infraServiceDescriptionKey } from '../lib/infraServiceDescriptions'
import { addToast } from '../lib/toast'

const props = defineProps({
  visible: { type: Boolean, default: false },
  service: { type: String, default: '' },
  label: { type: String, default: '' },
})

const emit = defineEmits(['update:visible'])

const { t, te } = useI18n()
const copiedKey = ref('')
const { data, translateApiError } = useManager()
const PORT_LABELS = {
  main: 'services.conn.port',
  amqp: 'services.conn.port_amqp',
  mgmt: 'services.conn.port_mgmt',
  smtp: 'services.conn.port_smtp',
  web: 'services.conn.port_web',
  api: 'services.conn.port_api',
  console: 'services.conn.port_console',
}
const target = computed(() => data.infra_services?.targets?.[props.service] || null)
const portDraft = ref({})
const savingPorts = ref(false)
const portsDirty = computed(() =>
  Object.entries(target.value?.host_ports || {}).some(([key, value]) => portDraft.value[key] !== value),
)

watch(
  () => [props.visible, target.value?.host_ports],
  () => {
    if (props.visible && !savingPorts.value) portDraft.value = { ...(target.value?.host_ports || {}) }
  },
  { immediate: true },
)

async function savePorts() {
  if (!portsDirty.value || savingPorts.value) return
  const state = data.infra_services?.statuses?.[props.service]?.state
  const recreate = state === 'running' || state === 'stopped'
  if (recreate && !(await confirmDialog(t('ports.confirm', { service: props.label || props.service }), { acceptLabel: t('ports.save'), rejectLabel: t('action.cancel') }))) return
  savingPorts.value = true
  try {
    const result = await apiSend('PUT', `/api/infra-services/${props.service}/ports`, { ports: portDraft.value })
    if (result.infra_services) data.infra_services = result.infra_services
    addToast({ severity: 'success', summary: t(result.message_key || 'ports.saved'), life: 4200 })
  } catch (error) {
    addToast({ severity: 'error', summary: translateApiError(error), life: 5000 })
  } finally {
    savingPorts.value = false
  }
}

const details = computed(() => getInfraConnectionDetails(props.service))
const webUrl = computed(() => getInfraWebUrl(props.service))
const serviceDescription = computed(() => {
  const key = infraServiceDescriptionKey(props.service)
  if (!key || !te(key)) return ''
  return t(key)
})

const title = computed(() =>
  t('services.connection_title', { service: props.label || props.service }),
)

function close() {
  emit('update:visible', false)
  copiedKey.value = ''
}

async function copyText(text, key) {
  if (!text) return
  try {
    await navigator.clipboard.writeText(text)
    copiedKey.value = key
    addToast({ severity: 'success', summary: t('services.connection_copied'), life: 2000 })
    setTimeout(() => {
      if (copiedKey.value === key) copiedKey.value = ''
    }, 1500)
  } catch (_) {
    addToast({ severity: 'error', summary: t('services.connection_copy_failed'), life: 3000 })
  }
}
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :header="title"
    :style="{ width: 'min(520px, 100%)' }"
    dismissable-mask
    @update:visible="(v) => { if (!v) close() }"
  >
    <div v-if="details" class="service-conn" data-tour="service-connection-dialog">
      <p v-if="serviceDescription" class="service-conn-desc">{{ serviceDescription }}</p>
      <p class="service-conn-intro">{{ t('services.connection_intro') }}</p>
      <p class="service-conn-intro">{{ t('services.connection_docker_host_hint') }}</p>

      <div class="service-conn-fields">
        <div
          v-for="(field, index) in details.fields"
          :key="field.labelKey + index"
          class="service-conn-row"
        >
          <div class="service-conn-meta">
            <span class="service-conn-label">{{ t(field.labelKey) }}</span>
            <code class="service-conn-value">{{ field.value }}</code>
          </div>
          <Button
            type="button"
            text
            size="small"
            :label="copiedKey === `field:${index}` ? t('services.connection_copied_short') : t('services.connection_copy')"
            @click="copyText(field.value, `field:${index}`)"
          />
        </div>
      </div>

      <p
        v-for="noteKey in details.notes || []"
        :key="noteKey"
        class="service-conn-note create-hint"
      >
        {{ t(noteKey) }}
      </p>

      <form v-if="target?.host_ports" class="service-conn-ports" @submit.prevent="savePorts">
        <strong>{{ t('ports.title') }}</strong>
        <p class="service-conn-intro">
          {{ target.ports_configurable ? t('ports.hint') : t('ports.not_configurable') }}
        </p>
        <div class="service-conn-ports-row">
          <label v-for="(value, key) in target.host_ports" :key="key">
            <span>{{ t(PORT_LABELS[key] || 'services.conn.port') }}</span>
            <InputNumber
              v-model="portDraft[key]"
              :min="1"
              :max="65535"
              :use-grouping="false"
              :disabled="savingPorts || !target.ports_configurable"
              size="small"
            />
          </label>
          <Button
            type="submit"
            size="small"
            :label="savingPorts ? t('action.working') : t('ports.save')"
            :loading="savingPorts"
            :disabled="!portsDirty || savingPorts || !target.ports_configurable"
          />
        </div>
      </form>

      <div class="service-conn-env">
        <div class="service-conn-env-head">
          <strong>{{ t('services.connection_env') }}</strong>
          <Button
            type="button"
            text
            size="small"
            :label="copiedKey === 'env' ? t('services.connection_copied_short') : t('services.connection_copy_env')"
            @click="copyText(details.env, 'env')"
          />
        </div>
        <pre class="service-conn-env-block">{{ details.env }}</pre>
      </div>
    </div>

    <template #footer>
      <Button
        v-if="webUrl"
        type="button"
        outlined
        icon="pi pi-external-link"
        :label="t('services.open_web')"
        @click="openInfraWeb(service)"
      />
      <Button type="button" :label="t('action.ok')" @click="close" />
    </template>
  </Dialog>
</template>
