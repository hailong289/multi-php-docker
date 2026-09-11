<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputText from 'primevue/inputtext'
import Paginator from 'primevue/paginator'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { showToast, translateApiError, loadBootstrap, data } = useManager()

const loading = ref(true)
const refreshing = ref(false)
const installing = ref('')
const versions = ref([])
const pagination = ref({ page: 1, per_page: 20, total: 0, total_pages: 1 })
const q = ref(String(route.query.q || ''))
const variant = ref(String(route.query.variant || 'all'))
const page = ref(Math.max(1, Number(route.query.page || 1)))

const variantOptions = computed(() => [
  { label: t('php_controller.variant_all'), value: 'all' },
  { label: t('php_controller.variant_default'), value: 'default' },
  { label: t('php_controller.variant_alpine'), value: 'alpine' },
  { label: t('php_controller.variant_trixie'), value: 'trixie' },
])

function variantLabel(value) {
  if (value === 'alpine') return t('php_controller.variant_alpine')
  if (value === 'trixie') return t('php_controller.variant_trixie')
  return t('php_controller.variant_default')
}

function variantSeverity(value) {
  return value === 'alpine' ? 'info' : 'secondary'
}

function syncQuery() {
  router.replace({
    name: 'php-version-catalog',
    query: {
      page: String(page.value),
      q: q.value || undefined,
      variant: variant.value !== 'all' ? variant.value : undefined,
    },
  })
}

async function loadCatalog({ forceRefresh = false, keepRows = false } = {}) {
  if (forceRefresh) refreshing.value = true
  else loading.value = true
  try {
    const params = new URLSearchParams()
    params.set('page', String(page.value))
    params.set('per_page', '20')
    if (q.value.trim()) params.set('q', q.value.trim())
    if (variant.value && variant.value !== 'all') params.set('variant', variant.value)
    const result = await apiGet(`/api/php-controllers/available-versions?${params}`)
    versions.value = result.versions || []
    pagination.value = result.pagination || { page: 1, per_page: 20, total: 0, total_pages: 1 }
    page.value = pagination.value.page || 1
  } catch (error) {
    if (!keepRows) versions.value = []
    showToast('failure', translateApiError(error))
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

async function installVersion(row) {
  if (data.php_controller_daemon?.state !== 'running') return
  if (row.installed || !row.installable || installing.value) return
  installing.value = row.tag
  try {
    const result = await apiSend('POST', '/api/php-controllers/install-version', {
      version: row.version,
      variant: row.variant || 'default',
    })
    showToast(
      'success',
      t(result.message_key || 'php_controller.version_install_requested', result.message_parameters || {}),
    )
    if (result.php_controllers) data.php_controllers = result.php_controllers
    row.installed = true
    await loadBootstrap()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    installing.value = ''
  }
}

function onPage(event) {
  const next = (event.page || 0) + 1
  if (next === page.value) return
  page.value = next
  syncQuery()
  loadCatalog({ keepRows: true })
}

function applyFilters() {
  page.value = 1
  syncQuery()
  loadCatalog({ keepRows: true })
}

watch(
  () => [route.query.page, route.query.q, route.query.variant],
  () => {
    page.value = Math.max(1, Number(route.query.page || 1))
    q.value = String(route.query.q || '')
    variant.value = String(route.query.variant || 'all')
  },
)

onMounted(async () => {
  await loadBootstrap()
  await loadCatalog()
})
</script>

<template>
  <section class="panel" data-tour="php-catalog-panel">
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
          <h2>{{ t('php_controller.catalog_title') }}</h2>
          <p>{{ t('php_controller.catalog_subtitle') }}</p>
        </div>
      </div>
      <Button
        type="button"
        :label="refreshing ? t('action.working') : t('php_controller.refresh_hub')"
        :loading="refreshing"
        :disabled="loading || refreshing || !!installing"
        @click="loadCatalog({ forceRefresh: true, keepRows: true })"
      />
    </div>

    <div class="panel-body php-catalog-filters" data-tour="php-catalog-filters">
      <InputText
        v-model="q"
        type="search"
        :placeholder="t('php_controller.filter_versions')"
        fluid
        @keyup.enter="applyFilters"
      />
      <Select
        v-model="variant"
        :options="variantOptions"
        option-label="label"
        option-value="value"
        class="php-catalog-variant"
        @update:model-value="applyFilters"
      />
      <Button
        type="button"
        :label="t('php_controller.apply_filters')"
        :disabled="loading || refreshing"
        @click="applyFilters"
      />
    </div>

    <DataTable
      v-if="loading"
      :value="Array.from({ length: 8 }, () => ({}))"
      :loading="true"
    >
      <Column :header="t('php_controller.version')" />
      <Column :header="t('php_controller.variant')" />
      <Column :header="t('php_controller.hub_tag')" />
      <Column :header="t('php_controller.actions')" />
    </DataTable>
    <div v-else-if="!versions.length" class="panel-body">
      {{ t('php_controller.no_versions_available') }}
    </div>
    <div v-else :class="{ 'is-dimmed': refreshing }">
      <DataTable :value="versions" data-key="tag" striped-rows>
        <Column :header="t('php_controller.version')">
          <template #body="{ data: row }">
            {{ row.label }}
          </template>
        </Column>
        <Column :header="t('php_controller.variant')">
          <template #body="{ data: row }">
            <Tag
              :value="variantLabel(row.variant)"
              :severity="variantSeverity(row.variant)"
              rounded
            />
          </template>
        </Column>
        <Column :header="t('php_controller.hub_tag')">
          <template #body="{ data: row }">
            <code>{{ row.tag }}</code>
          </template>
        </Column>
        <Column :header="t('php_controller.actions')">
          <template #body="{ data: row }">
            <Button
              v-if="row.installable && !row.installed"
              type="button"
              size="small"
              :label="
                installing === row.tag
                  ? t('action.working')
                  : t('php_controller.install_version')
              "
              :loading="installing === row.tag"
              :disabled="!!installing || refreshing || data.php_controller_daemon?.state !== 'running'"
              @click="installVersion(row)"
            />
            <Tag
              v-else-if="row.installed"
              :value="t('php_controller.already_installed')"
              severity="success"
              rounded
            />
            <Tag
              v-else
              :value="t('php_controller.tag_not_installable')"
              severity="secondary"
              rounded
            />
          </template>
        </Column>
      </DataTable>
    </div>

    <div
      v-if="!loading && pagination.total > 0"
      class="php-catalog-pager-wrap"
    >
      <Paginator
        :rows="pagination.per_page || 20"
        :total-records="pagination.total"
        :first="((pagination.page || 1) - 1) * (pagination.per_page || 20)"
        template="PrevPageLink PageLinks NextPageLink"
        @page="onPage"
      />
      <span class="php-catalog-pager-meta">
        {{
          t('php_controller.page_meta', {
            page: pagination.page,
            total_pages: pagination.total_pages,
            total: pagination.total,
          })
        }}
      </span>
    </div>
  </section>
</template>
