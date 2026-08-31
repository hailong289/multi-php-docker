<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useManager } from '../composables/useManager'

const { t } = useI18n()
const { pullProgress, dismissPullProgress, stateClass, stateLabel } = useManager()

const logEl = ref(null)

const visible = computed(() => pullProgress.value && !pullProgress.value.dismissed)

const title = computed(() => {
  const job = pullProgress.value
  if (!job) return ''
  if (job.action === 'pull-recreate') {
    return t('progress.pull_recreate_title', { service: job.label })
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
        <span
          v-if="pullProgress.state"
          class="state-badge pull-progress-state"
          :class="stateClass(pullProgress.state)"
        >
          {{ stateLabel(pullProgress.state) }}
        </span>
      </div>
      <button
        type="button"
        class="pull-progress-close"
        :aria-label="t('progress.dismiss')"
        @click="dismissPullProgress()"
      >
        ×
      </button>
    </div>
    <pre ref="logEl" class="pull-progress-log">{{ bodyText }}</pre>
  </div>
</template>
