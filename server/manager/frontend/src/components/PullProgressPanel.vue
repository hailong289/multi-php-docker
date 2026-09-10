<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useManager } from '../composables/useManager'

const { t } = useI18n()
const { pullProgress, dismissPullProgress, stateLabel } = useManager()

const logEl = ref(null)

const visible = computed(() => pullProgress.value && !pullProgress.value.dismissed)

const title = computed(() => {
  const job = pullProgress.value
  if (!job) return ''
  if (job.action === 'pull-recreate') {
    return t('progress.pull_recreate_title', { service: job.label })
  }
  if (job.action === 'recreate') {
    return t('progress.recreate_title', { service: job.label })
  }
  return t('progress.pull_create_title', { service: job.label })
})

const panelClass = computed(() => {
  const state = pullProgress.value?.state
  if (state === 'busy') return 'is-busy'
  if (state === 'error') return 'is-error'
  if (state === 'running' || state === 'stopped' || state === 'not_created') return 'is-done'
  return ''
})

const bodyText = computed(() => {
  const job = pullProgress.value
  if (!job) return ''
  if (job.content) return job.content
  if (job.loading || job.state === 'busy') return t('progress.waiting')
  return t('progress.empty')
})

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}

watch(
  () => pullProgress.value?.content,
  async () => {
    await nextTick()
    const el = logEl.value
    if (el) el.scrollTop = el.scrollHeight
  },
)
</script>

<template>
  <div
    v-if="visible"
    class="pull-progress-panel"
    :class="panelClass"
    role="status"
    aria-live="polite"
    data-tour="pull-progress-panel"
  >
    <div class="pull-progress-header">
      <div class="pull-progress-title-wrap">
        <span
          v-if="pullProgress.state === 'busy'"
          class="btn-spinner pull-progress-spinner"
          aria-hidden="true"
        ></span>
        <strong class="pull-progress-title">{{ title }}</strong>
        <Tag
          v-if="pullProgress.state"
          class="pull-progress-state"
          :value="stateLabel(pullProgress.state)"
          :severity="stateSeverity(pullProgress.state)"
          rounded
        />
      </div>
      <Button
        type="button"
        class="pull-progress-close"
        icon="pi pi-times"
        severity="secondary"
        text
        rounded
        :aria-label="t('progress.dismiss')"
        @click="dismissPullProgress()"
      />
    </div>
    <pre ref="logEl" class="pull-progress-log">{{ bodyText }}</pre>
  </div>
</template>
