<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import ProgressSpinner from 'primevue/progressspinner'
import Tag from 'primevue/tag'
import { useManager } from '../composables/useManager'

const { t } = useI18n()
const { pullProgress, dismissPullProgress, stateLabel } = useManager()

const logEl = ref(null)
const expanded = ref(false)

const dialogVisible = computed({
  get: () => !!(pullProgress.value && !pullProgress.value.dismissed),
  set: (value) => {
    if (!value) dismissPullProgress()
  },
})

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
  const classes = []
  if (state === 'busy') classes.push('is-busy')
  else if (state === 'error') classes.push('is-error')
  else if (state === 'running' || state === 'stopped' || state === 'not_created') classes.push('is-done')
  if (expanded.value) classes.push('is-expanded')
  return classes
})

const dialogStyle = computed(() => ({
  width: expanded.value
    ? 'min(40rem, calc(100vw - 1.75rem))'
    : 'min(22rem, calc(100vw - 1.75rem))',
}))

const bodyText = computed(() => {
  const job = pullProgress.value
  if (!job) return ''
  if (job.content) return job.content
  if (job.loading || job.state === 'busy') return t('progress.waiting')
  return t('progress.empty')
})

const isBusy = computed(() => pullProgress.value?.state === 'busy')

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'info'
  return 'contrast'
}

function toggleExpanded() {
  expanded.value = !expanded.value
  nextTick(() => {
    const el = logEl.value
    if (el) el.scrollTop = el.scrollHeight
  })
}

watch(dialogVisible, (visible) => {
  if (!visible) expanded.value = false
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
  <Dialog
    v-model:visible="dialogVisible"
    class="pull-progress-dialog"
    :class="panelClass"
    :modal="false"
    position="bottomright"
    :draggable="true"
    :dismissable-mask="false"
    :close-on-escape="true"
    :style="dialogStyle"
    :pt="{
      root: { 'data-tour': 'pull-progress-panel', role: 'status', 'aria-live': 'polite' },
    }"
  >
    <template #header>
      <div class="pull-progress-title-wrap">
        <ProgressSpinner
          v-if="isBusy"
          class="pull-progress-spinner"
          stroke-width="8"
          aria-hidden="true"
        />
        <span class="pull-progress-title">{{ title }}</span>
        <Tag
          v-if="pullProgress?.state"
          class="pull-progress-state"
          :value="stateLabel(pullProgress.state)"
          :severity="stateSeverity(pullProgress.state)"
          rounded
        />
      </div>
    </template>

    <template #closebutton="{ closeCallback }">
      <Button
        type="button"
        class="pull-progress-expand"
        :icon="expanded ? 'pi pi-window-minimize' : 'pi pi-window-maximize'"
        severity="secondary"
        text
        rounded
        :aria-label="expanded ? t('progress.collapse') : t('progress.expand')"
        :title="expanded ? t('progress.collapse') : t('progress.expand')"
        @click="toggleExpanded"
      />
      <Button
        type="button"
        icon="pi pi-times"
        severity="secondary"
        text
        rounded
        :aria-label="t('progress.dismiss')"
        @click="closeCallback"
      />
    </template>

    <pre ref="logEl" class="pull-progress-log">{{ bodyText }}</pre>
  </Dialog>
</template>
