<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import { getInfraConnectionDetails } from '../lib/infraConnectionDetails'
import { addToast } from '../lib/toast'

const props = defineProps({
  visible: { type: Boolean, default: false },
  service: { type: String, default: '' },
  label: { type: String, default: '' },
})

const emit = defineEmits(['update:visible'])

const { t } = useI18n()
const copiedKey = ref('')

const details = computed(() => getInfraConnectionDetails(props.service))

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
      <p class="service-conn-intro">{{ t('services.connection_intro') }}</p>

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
      <Button type="button" :label="t('action.ok')" @click="close" />
    </template>
  </Dialog>
</template>
