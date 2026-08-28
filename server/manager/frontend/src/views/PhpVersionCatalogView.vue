<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import TableSkeleton from '../components/TableSkeleton.vue'
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

const pageNumbers = computed(() => {
  const total = pagination.value.total_pages || 1
  const current = pagination.value.page || 1
  const windowSize = 5
  let start = Math.max(1, current - Math.floor(windowSize / 2))
  let end = Math.min(total, start + windowSize - 1)
  start = Math.max(1, end - windowSize + 1)
  const nums = []
  for (let i = start; i <= end; i += 1) nums.push(i)
  return nums
})

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

function goPage(next) {
  const total = pagination.value.total_pages || 1
  const target = Math.min(total, Math.max(1, next))
  if (target === page.value) return
  page.value = target
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
        <button
          type="button"
          class="icon-back"
          :aria-label="$t('php_controller.back_to_versions')"
          :title="$t('php_controller.back_to_versions')"
          @click="router.push({ name: 'php-versions' })"
        >
          <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false">
            <path
              d="M12.5 4.5 7 10l5.5 5.5"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </button>
        <div>
          <h2>{{ $t('php_controller.catalog_title') }}</h2>
          <p>{{ $t('php_controller.catalog_subtitle') }}</p>
        </div>
      </div>
      <button
        type="button"
        :class="{ 'is-loading': refreshing }"
        :disabled="loading || refreshing || !!installing"
        @click="loadCatalog({ forceRefresh: true, keepRows: true })"
      >
        <span v-if="refreshing" class="btn-spinner" aria-hidden="true"></span>
        {{ refreshing ? $t('action.working') : $t('php_controller.refresh_hub') }}
      </button>
    </div>

    <div class="panel-body php-catalog-filters" data-tour="php-catalog-filters">
      <input
        v-model="q"
        type="search"
        :placeholder="$t('php_controller.filter_versions')"
        @keyup.enter="applyFilters"
      />
      <select v-model="variant" @change="applyFilters">
        <option value="all">{{ $t('php_controller.variant_all') }}</option>
        <option value="default">{{ $t('php_controller.variant_default') }}</option>
        <option value="alpine">{{ $t('php_controller.variant_alpine') }}</option>
        <option value="trixie">{{ $t('php_controller.variant_trixie') }}</option>
      </select>
      <button type="button" class="primary" :disabled="loading || refreshing" @click="applyFilters">
        {{ $t('php_controller.apply_filters') }}
      </button>
    </div>

    <TableSkeleton
      v-if="loading"
      :columns="4"
      :rows="8"
      :headers="[
        $t('php_controller.version'),
        $t('php_controller.variant'),
        $t('php_controller.hub_tag'),
        $t('php_controller.actions'),
      ]"
    />
    <div v-else-if="!versions.length" class="panel-body">
      {{ $t('php_controller.no_versions_available') }}
    </div>
    <div v-else class="table-wrap" :class="{ 'is-dimmed': refreshing }">
      <table class="php-catalog-table">
        <thead>
          <tr>
            <th>{{ $t('php_controller.version') }}</th>
            <th>{{ $t('php_controller.variant') }}</th>
            <th>{{ $t('php_controller.hub_tag') }}</th>
            <th>{{ $t('php_controller.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in versions" :key="row.tag">
            <td>{{ row.label }}</td>
            <td>
              <span
                class="state-badge"
                :class="
                  row.variant === 'alpine'
                    ? 'ext-status--enabled_in_ini'
                    : 'ext-status--available_to_install'
                "
              >
                {{
                  row.variant === 'alpine'
                    ? $t('php_controller.variant_alpine')
                    : row.variant === 'trixie'
                      ? $t('php_controller.variant_trixie')
                      : $t('php_controller.variant_default')
                }}
              </span>
            </td>
            <td><code>{{ row.tag }}</code></td>
            <td class="php-ext-actions-col">
              <button
                v-if="row.installable && !row.installed"
                type="button"
                class="primary"
                :disabled="!!installing || refreshing || data.php_controller_daemon?.state !== 'running'"
                @click="installVersion(row)"
              >
                <span
                  v-if="installing === row.tag"
                  class="btn-spinner"
                  aria-hidden="true"
                ></span>
                {{
                  installing === row.tag
                    ? $t('action.working')
                    : $t('php_controller.install_version')
                }}
              </button>
              <span v-else-if="row.installed" class="php-version-installed">{{
                $t('php_controller.already_installed')
              }}</span>
              <span v-else class="php-version-installed">{{ $t('php_controller.tag_not_installable') }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="!loading && pagination.total_pages > 1" class="panel-body php-catalog-pager">
      <button type="button" :disabled="pagination.page <= 1 || loading || refreshing" @click="goPage(pagination.page - 1)">
        {{ $t('php_controller.prev_page') }}
      </button>
      <button
        v-for="n in pageNumbers"
        :key="n"
        type="button"
        :class="{ primary: n === pagination.page }"
        :disabled="loading || refreshing"
        @click="goPage(n)"
      >
        {{ n }}
      </button>
      <button
        type="button"
        :disabled="pagination.page >= pagination.total_pages || loading || refreshing"
        @click="goPage(pagination.page + 1)"
      >
        {{ $t('php_controller.next_page') }}
      </button>
      <span class="php-catalog-pager-meta">
        {{
          $t('php_controller.page_meta', {
            page: pagination.page,
            total_pages: pagination.total_pages,
            total: pagination.total,
          })
        }}
      </span>
    </div>
  </section>
</template>
