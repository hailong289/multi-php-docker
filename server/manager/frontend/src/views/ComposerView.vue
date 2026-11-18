<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { confirmDialog } from '../lib/confirm'

/** Shared Composer library: download versions, assign one per PHP version (or a default). */
const { t } = useI18n()
const router = useRouter()
const { showToast, translateApiError, phpAction, phpActionEnabled, isPending } = useManager()

const NONE = 'none'

const library = ref({ releases: [], versions: [], default: null, targets: [] })
const loading = ref(true)
const pending = ref('')
const downloadChoice = ref('latest')
const downloadCustom = ref('')

const releaseByVersion = computed(() =>
  Object.fromEntries(library.value.releases.map((release) => [release.version, release.key])),
)

const downloadOptions = computed(() => {
  const byKey = Object.fromEntries(library.value.releases.map((release) => [release.key, release.version]))
  return [
    { value: 'latest', label: t('composer.latest', { version: byKey.latest || '—' }) },
    { value: 'lts', label: t('composer.lts', { version: byKey.lts || '—' }) },
    { value: 'custom', label: t('composer.custom') },
  ]
})

// "default" is used by unassigned PHP versions, so it must run on the oldest one installed.
const lowestPhpId = computed(() => Math.min(...library.value.targets.map((row) => phpId(row.service))))

function assignOptions(target, current = null) {
  const fallback = target === 'default' ? t('composer.image_default') : t('composer.use_default')
  const php = target === 'default' ? lowestPhpId.value : phpId(target)
  const options = [
    // A sentinel, not null or '': PrimeVue Select treats both as "nothing selected" and shows a blank box.
    { value: NONE, label: fallback, disabled: false },
    ...library.value.versions.map((row) => ({
      value: row.version,
      label: row.version,
      disabled: row.min_php !== null && php < row.min_php,
    })),
  ]
  // Assigned but its files are gone: keep it visible so the box is not blank, and let the user pick another.
  if (current && !library.value.versions.some((row) => row.version === current)) {
    // Not disabled: PrimeVue Select renders a disabled selected option as a blank box.
    options.push({ value: current, label: t('composer.missing_version', { version: current }), disabled: false })
  }
  return options
}

/** Same as ComposerLibrary::phpVersionId: a minor-only service means its newest patch. */
function phpId(service) {
  const [major = 0, minor = 0, patch = 99] = String(service)
    .replace(/^php-/, '')
    .replace(/-(alpine|trixie)$/, '')
    .split('.')
    .map(Number)
  return major * 10000 + minor * 100 + patch
}

const labelByService = computed(() =>
  Object.fromEntries(library.value.targets.map((row) => [row.service, row.label])),
)

function targetLabels(list) {
  return list.map((target) => (target === 'default' ? t('composer.default_short') : labelByService.value[target] || target)).join(', ')
}

const unmountedCount = computed(() => library.value.targets.filter((row) => row.mounted === false).length)

async function run(key, request) {
  pending.value = key
  try {
    library.value = await request()
    return true
  } catch (error) {
    showToast('failure', translateApiError(error))
    return false
  } finally {
    pending.value = ''
  }
}

async function load() {
  loading.value = true
  await run('', () => apiGet('/api/php-controllers/composer'))
  loading.value = false
}

async function download() {
  const version = downloadChoice.value === 'custom' ? downloadCustom.value.trim() : downloadChoice.value
  if (!version) return
  let downloaded = ''
  const ok = await run('download', async () => {
    const result = await apiSend('POST', '/api/php-controllers/composer/versions', { version })
    downloaded = result.downloaded
    return result
  })
  if (ok) showToast('success', t('composer.downloaded', { version: downloaded }))
}

async function remove(version) {
  if (!(await confirmDialog(t('composer.remove_confirm', { version }), { acceptLabel: t('action.delete'), acceptSeverity: 'danger' }))) return
  if (await run(`remove:${version}`, () => apiSend('DELETE', `/api/php-controllers/composer/versions/${version}`))) {
    showToast('success', t('composer.removed', { version }))
  }
}

async function assign(target, version) {
  if (await run(`assign:${target}`, () => apiSend('PUT', '/api/php-controllers/composer/assign', { target, version }))) {
    showToast('success', t('composer.assigned'))
  }
}

// Recreate picks up the /opt/composer mount added to compose/php-*.yml.
async function recreate(service) {
  await phpAction(service, 'recreate')
  await load()
}

onMounted(load)
</script>

<template>
  <section class="panel composer-page">
    <div class="panel-heading nginx-heading">
      <div class="php-detail-heading">
        <Button
          type="button"
          class="icon-back"
          icon="pi pi-arrow-left"
          severity="secondary"
          text
          rounded
          :aria-label="t('php_controller.back_to_versions')"
          :title="t('php_controller.back_to_versions')"
          @click="router.push({ name: 'php-versions' })"
        />
        <div>
          <h2>{{ t('composer.title') }}</h2>
          <p>{{ t('composer.page_hint') }}</p>
        </div>
      </div>
      <div class="panel-heading-actions">
        <Button type="button" :label="t('nginx.refresh')" :disabled="loading || !!pending" @click="load" />
      </div>
    </div>

    <div class="panel-body composer-sections">
      <section class="composer-block" aria-labelledby="composer-versions-title">
        <div class="composer-block-head">
          <h3 id="composer-versions-title">{{ t('composer.versions_title') }}</h3>
          <form class="composer-row" @submit.prevent="download">
            <Select
              v-model="downloadChoice"
              :options="downloadOptions"
              option-label="label"
              option-value="value"
              :aria-label="t('composer.download')"
              :disabled="!!pending"
            />
            <InputText
              v-if="downloadChoice === 'custom'"
              v-model="downloadCustom"
              :placeholder="t('composer.custom_placeholder')"
              :aria-label="t('composer.custom')"
              :disabled="!!pending"
            />
            <Button
              type="submit"
              icon="pi pi-download"
              :label="t('composer.download')"
              :loading="pending === 'download'"
              :disabled="!!pending || (downloadChoice === 'custom' && !downloadCustom.trim())"
            />
          </form>
        </div>
        <p v-if="!loading && !library.versions.length" class="composer-empty">{{ t('composer.versions_empty') }}</p>
        <ul v-else class="composer-list">
          <li v-for="row in library.versions" :key="row.version" class="composer-version-row">
            <div class="composer-list-main">
              <strong>{{ row.version }}</strong>
              <Tag
                v-if="releaseByVersion[row.version]"
                :value="releaseByVersion[row.version] === 'lts' ? 'LTS' : t('composer.tag_latest')"
                severity="secondary"
                rounded
              />
              <span class="composer-muted">
                {{ row.assigned_to.length ? t('composer.used_by', { list: targetLabels(row.assigned_to) }) : t('composer.unused') }}
              </span>
              <span v-if="row.min_php === null" class="composer-muted">{{ t('composer.min_php_unknown') }}</span>
            </div>
            <Button
              type="button"
              icon="pi pi-trash"
              severity="danger"
              text
              rounded
              :aria-label="t('composer.remove', { version: row.version })"
              :title="row.assigned_to.length ? t('composer.version_in_use') : t('composer.remove', { version: row.version })"
              :loading="pending === `remove:${row.version}`"
              :disabled="!!pending || row.assigned_to.length > 0"
              @click="remove(row.version)"
            />
          </li>
        </ul>
      </section>

      <section class="composer-block" aria-labelledby="composer-assign-title">
        <div class="composer-block-head">
          <h3 id="composer-assign-title">{{ t('composer.assign_title') }}</h3>
        </div>
        <p v-if="unmountedCount" class="composer-warn">
          <i class="pi pi-exclamation-triangle" aria-hidden="true" />
          {{ t('composer.recreate_hint', { count: unmountedCount }) }}
        </p>
        <ul class="composer-list">
          <li class="composer-assign">
            <label for="composer-assign-default" class="composer-list-main">
              <strong>{{ t('composer.default_target') }}</strong>
            </label>
            <Select
              input-id="composer-assign-default"
              :model-value="library.default ?? NONE"
              :options="assignOptions('default', library.default)"
              option-label="label"
              option-value="value"
              option-disabled="disabled"
              :disabled="!!pending"
              :loading="pending === 'assign:default'"
              @update:model-value="(value) => assign('default', value === NONE ? null : value)"
            />
          </li>
          <li v-for="row in library.targets" :key="row.service" class="composer-assign">
            <label :for="`composer-assign-${row.service}`" class="composer-list-main">
              <strong>{{ row.label }}</strong>
              <span class="composer-muted">
                {{ row.effective ? t('composer.runs', { version: row.effective }) : t('composer.runs_image') }}
              </span>
              <Tag v-if="row.mounted === false" :value="t('composer.needs_recreate')" severity="warn" rounded />
            </label>
            <div class="composer-assign-controls">
              <Button
                v-if="row.mounted === false && phpActionEnabled(row.service, 'recreate')"
                type="button"
                size="small"
                severity="secondary"
                outlined
                :label="t('composer.recreate')"
                :loading="isPending('php', { service: row.service, action: 'recreate' })"
                @click="recreate(row.service)"
              />
              <Select
                :input-id="`composer-assign-${row.service}`"
                :model-value="row.version ?? NONE"
                :options="assignOptions(row.service, row.version)"
                option-label="label"
                option-value="value"
                option-disabled="disabled"
                :disabled="!!pending"
                :loading="pending === `assign:${row.service}`"
                @update:model-value="(value) => assign(row.service, value === NONE ? null : value)"
              />
            </div>
          </li>
        </ul>
      </section>
    </div>
  </section>
</template>
