<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ActionMenu from '../components/ActionMenu.vue'
import PinButton from '../components/PinButton.vue'
import { useManager } from '../composables/useManager'
import { usePinnedContainers } from '../composables/usePinnedContainers'
import { buildPhpMenuItems, buildPinMenuItem } from '../lib/containerMenus'

const router = useRouter()
const { t } = useI18n()
const mgr = useManager()
const {
  loading,
  data,
  stateLabel,
  phpServiceState,
  showCreateHint,
  loadBootstrap,
  busy,
} = mgr
const { isPinned, togglePin } = usePinnedContainers()

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}

const menuCtx = computed(() => ({ t, router, mgr }))

function phpMenuItems(service) {
  const pinItem = buildPinMenuItem('php', service, {
    t,
    pinned: isPinned('php', service),
    toggle: togglePin,
  })
  return [...buildPhpMenuItems(service, menuCtx.value), pinItem]
}

const phpRows = computed(() =>
  Object.entries(data.php_controllers?.targets || {}).map(([service, target]) => ({
    service,
    target,
  })),
)

onMounted(() => {
  loadBootstrap()
})
</script>

<template>
  <section class="panel" data-tour="php-panel">
    <div class="panel-heading">
      <div class="controller-heading panel-heading-row">
        <div>
          <h2>{{ t('php_controller.title') }}</h2>
          <p>{{ t('php_controller.subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <Button
            type="button"
            data-tour="php-compose-yaml"
            :label="t('php_controller.manage_yaml')"
            :disabled="busy || loading"
            @click="router.push({ name: 'php-compose-yaml' })"
          />
          <Button
            type="button"
            data-tour="php-add"
            :label="t('php_controller.add_version')"
            :disabled="busy || loading"
            @click="router.push({ name: 'php-version-catalog' })"
          />
        </div>
      </div>
    </div>

    <div class="panel-body">
      <div
        v-if="loading"
        class="resource-card-grid"
        aria-busy="true"
        aria-live="polite"
      >
        <div
          v-for="n in 6"
          :key="'php-skel-' + n"
          class="resource-card resource-card-skeleton"
        >
          <div class="resource-card-head">
            <span class="skeleton-line skeleton-w2"></span>
            <span class="skeleton-line skeleton-tag"></span>
          </div>
          <div class="resource-card-meta">
            <span class="skeleton-line skeleton-w1"></span>
            <span class="skeleton-line skeleton-w0"></span>
          </div>
          <div class="resource-card-footer">
            <span class="skeleton-line skeleton-w0"></span>
          </div>
        </div>
      </div>

      <div
        v-else-if="phpRows.length === 0"
        class="empty"
        data-tour="php-table"
      >
        {{ t('php_controller.subtitle') }}
      </div>

      <div v-else class="resource-card-grid" data-tour="php-table">
        <article
          v-for="row in phpRows"
          :key="row.service"
          class="resource-card"
          :data-state="phpServiceState(row.service)"
        >
          <div class="resource-card-head">
            <div class="resource-card-title">
              <h3 :title="row.target.label">{{ row.target.label }}</h3>
            </div>
            <Tag
              :value="stateLabel(phpServiceState(row.service))"
              :severity="stateSeverity(phpServiceState(row.service))"
              rounded
            />
          </div>

          <dl class="resource-card-meta">
            <div>
              <dt>{{ t('php_controller.container') }}</dt>
              <dd><code>{{ row.target.container }}</code></dd>
            </div>
            <div>
              <dt>{{ t('php_controller.profile') }}</dt>
              <dd>
                <code>{{ row.target.profile || t('php_controller.default_profile') }}</code>
              </dd>
            </div>
          </dl>

          <p
            v-if="showCreateHint(row.service, row.target)"
            class="create-hint"
          >
            {{ t('php_controller.create_hint') }}
          </p>

          <div class="resource-card-footer">
            <div class="row-actions">
              <PinButton kind="php" :id="row.service" />
              <ActionMenu :items="phpMenuItems(row.service)" />
            </div>
          </div>
        </article>
      </div>
    </div>
  </section>
</template>
