<script setup>
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import Tag from 'primevue/tag'
import PinButton from '../components/PinButton.vue'
import WebServerSwitch from '../components/WebServerSwitch.vue'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const COMPOSE_FILE = 'apache.yml'

const { t } = useI18n()
const router = useRouter()
const {
  data,
  stateLabel,
  composeFileState,
  composeYamlActionEnabled,
  composeYamlAction,
  isPending,
  siteUrl,
  showToast,
  translateApiError,
} = useManager()

const tab = ref('control')
const loading = ref(true)
const pending = ref('')
const apache = ref({ config: '', vhosts: [], reload_log: '' })
const configDraft = ref('')
const configOriginal = ref('')
const configDirty = computed(() => configDraft.value !== configOriginal.value)
const httpPort = ref(8081)
const httpsPort = ref(8443)
const nginxPorts = computed(() => ({
  http: Number(data.nginx_management?.http_port || 80),
  https: Number(data.nginx_management?.https_port || 443),
}))
// Same host port as Nginx: allowed, but then only one of the two can run.
const sharesNginxPorts = computed(
  () => httpPort.value === nginxPorts.value.http || httpsPort.value === nginxPorts.value.https,
)

const item = computed(() => (data.infra_services?.compose_files || []).find((file) => file.name === COMPOSE_FILE) || null)
const state = computed(() => (item.value ? composeFileState(item.value) : 'not_created'))
const container = computed(() => item.value?.compose_services?.[0]?.container || 'apache_container')
const sites = computed(() =>
  Object.values(data.servers || {}).filter((server) => server?.WEB_SERVER === 'apache'),
)

function stateSeverity(value) {
  if (value === 'running') return 'success'
  if (value === 'stopped') return 'secondary'
  if (value === 'error') return 'danger'
  if (value === 'busy') return 'warn'
  return 'contrast'
}

function enabled(action) {
  return !!item.value && composeYamlActionEnabled(item.value, action)
}

function busy(action) {
  return isPending('compose-file', { name: COMPOSE_FILE, action })
}

async function runAction(action) {
  if (item.value) await composeYamlAction(item.value, action)
}

function apply(result) {
  if (!result?.apache) return
  apache.value = result.apache
  httpPort.value = result.apache.http_port
  httpsPort.value = result.apache.https_port
  data.apache_ports = { http_port: result.apache.http_port, https_port: result.apache.https_port }
  if (!configDirty.value) {
    configDraft.value = result.apache.config
    configOriginal.value = result.apache.config
  }
}

async function load() {
  loading.value = true
  try {
    apply(await apiGet('/api/apache'))
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    loading.value = false
  }
}

async function saveConfig() {
  pending.value = 'save'
  try {
    const result = await apiSend('PUT', '/api/apache/config', { content: configDraft.value })
    configOriginal.value = configDraft.value
    apply(result)
    showToast('success', t(result.message_key || 'apache.config_saved'))
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function reload() {
  pending.value = 'reload'
  try {
    const result = await apiSend('POST', '/api/apache/reload', {})
    showToast('success', t(result.message_key || 'apache.reload_requested'))
    // entrypoint.sh polls the trigger every second; refresh to show its httpd -t result.
    setTimeout(load, 2500)
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function savePorts() {
  pending.value = 'ports'
  try {
    const result = await apiSend('PUT', '/api/apache/ports', {
      http_port: httpPort.value,
      https_port: httpsPort.value,
    })
    apply(result)
    showToast('success', t(result.message_key || 'apache.ports_saved'))
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

function openLogs() {
  router.push({ name: 'compose-file-logs', params: { name: COMPOSE_FILE } })
}

onMounted(load)
</script>

<template>
  <section class="panel nginx-page apache-page">
    <div class="panel-heading nginx-heading">
      <div>
        <WebServerSwitch />
        <h2>{{ t('apache.title') }}</h2>
        <p>{{ t('apache.subtitle') }}</p>
      </div>
      <div class="panel-heading-actions nginx-heading-actions">
        <Button type="button" :label="t('nginx.refresh')" :disabled="loading || !!pending" @click="load" />
      </div>
    </div>

    <div class="panel-body nginx-tabs-wrap">
      <Tabs v-model:value="tab">
        <TabList>
          <Tab value="control">{{ t('nginx.tab_control') }}</Tab>
          <Tab value="config">{{ t('apache.tab_config') }}</Tab>
          <Tab value="vhosts">{{ t('apache.tab_vhosts') }}</Tab>
        </TabList>
        <TabPanels>
          <TabPanel value="control">
            <div v-if="loading" class="nginx-tab-pad">{{ t('loading') }}</div>
            <div v-else class="nginx-control-stack">
              <div class="nginx-status-bar">
                <div class="nginx-status-meta">
                  <Tag
                    :value="item ? stateLabel(state) : t('apache.missing_compose')"
                    :severity="item ? stateSeverity(state) : 'danger'"
                    rounded
                  />
                  <code class="nginx-container">{{ container }}</code>
                </div>
                <div class="controller-actions">
                  <PinButton kind="compose" :id="COMPOSE_FILE" />
                  <Button
                    v-if="state === 'not_created' || state === 'error'"
                    type="button"
                    size="small"
                    :label="busy('create') ? t('action.working') : t('nginx.create')"
                    :loading="busy('create')"
                    :disabled="!enabled('create')"
                    @click="runAction('create')"
                  />
                  <Button
                    type="button"
                    size="small"
                    :label="busy('start') ? t('action.working') : t('nginx.start')"
                    :loading="busy('start')"
                    :disabled="!enabled('start')"
                    @click="runAction('start')"
                  />
                  <Button
                    type="button"
                    size="small"
                    severity="secondary"
                    outlined
                    :label="busy('stop') ? t('action.working') : t('nginx.stop')"
                    :loading="busy('stop')"
                    :disabled="!enabled('stop')"
                    @click="runAction('stop')"
                  />
                  <Button
                    type="button"
                    size="small"
                    severity="secondary"
                    outlined
                    :label="busy('restart') ? t('action.working') : t('nginx.restart')"
                    :loading="busy('restart')"
                    :disabled="!enabled('restart')"
                    @click="runAction('restart')"
                  />
                  <Button
                    type="button"
                    size="small"
                    :label="pending === 'reload' ? t('action.working') : t('apache.reload')"
                    :loading="pending === 'reload'"
                    :disabled="state !== 'running' || !!pending"
                    @click="reload"
                  />
                  <Button
                    type="button"
                    size="small"
                    severity="secondary"
                    text
                    :label="t('apache.logs')"
                    :disabled="state === 'not_created'"
                    @click="openLogs"
                  />
                </div>
              </div>

              <p class="create-hint">{{ t('apache.how_it_works') }}</p>

              <h3>{{ t('apache.ports') }}</h3>
              <div class="apache-ports">
                <label>
                  HTTP
                  <InputNumber v-model="httpPort" :min="1" :max="65535" :use-grouping="false" input-class="apache-port-input" />
                </label>
                <label>
                  HTTPS
                  <InputNumber v-model="httpsPort" :min="1" :max="65535" :use-grouping="false" input-class="apache-port-input" />
                </label>
                <Button
                  type="button"
                  size="small"
                  :label="pending === 'ports' ? t('action.working') : t('supervisor.conf_save')"
                  :loading="pending === 'ports'"
                  :disabled="!!pending || loading || (httpPort === apache.http_port && httpsPort === apache.https_port)"
                  @click="savePorts"
                />
              </div>
              <p class="create-hint">{{ t('apache.ports_hint') }}</p>
              <p v-if="sharesNginxPorts" class="p-error">{{ t('apache.ports_shared') }}</p>

              <h3>{{ t('apache.sites') }}</h3>
              <p v-if="!sites.length" class="empty">{{ t('apache.no_sites') }}</p>
              <ul v-else class="apache-site-list">
                <li v-for="site in sites" :key="site.APP_NAME">
                  <a v-if="site.DOMAIN_NAME" :href="siteUrl(site.DOMAIN_NAME, false, site)" target="_blank" rel="noreferrer">
                    {{ siteUrl(site.DOMAIN_NAME, false, site) }}
                  </a>
                  <strong v-else>{{ site.APP_NAME }}</strong>
                  <code>{{ site.SERVER_PATH }}</code>
                  <Tag :value="site.CONTAINER_PHP_VERSION" severity="secondary" rounded />
                </li>
              </ul>

              <template v-if="apache.reload_log">
                <h3>{{ t('apache.reload_log') }}</h3>
                <pre class="service-logs-pre">{{ apache.reload_log }}</pre>
              </template>
            </div>
          </TabPanel>

          <TabPanel value="config">
            <div class="panel-body nginx-template-editor">
              <div class="nginx-template-editor-head">
                <div>
                  <strong><code>apache/httpd.conf</code></strong>
                  <Tag v-if="configDirty" class="home-inline-tag" :value="t('nginx.template_dirty')" severity="secondary" rounded />
                </div>
                <div class="actions">
                  <Button
                    type="button"
                    :label="pending === 'save' ? t('action.working') : t('supervisor.conf_save')"
                    :loading="pending === 'save'"
                    :disabled="!!pending || loading || !configDirty"
                    @click="saveConfig"
                  />
                  <Button
                    type="button"
                    severity="secondary"
                    outlined
                    :label="pending === 'reload' ? t('action.working') : t('apache.reload')"
                    :loading="pending === 'reload'"
                    :disabled="state !== 'running' || !!pending"
                    @click="reload"
                  />
                </div>
              </div>
              <p class="nginx-template-meta">{{ t('apache.config_hint') }}</p>
              <MonacoEditor v-model="configDraft" language="ini" min-height="420px" :read-only="loading || pending === 'save'" />
            </div>
          </TabPanel>

          <TabPanel value="vhosts">
            <div class="nginx-tab-pad">
              <p class="create-hint">{{ t('apache.vhosts_hint') }}</p>
              <p v-if="!apache.vhosts.length" class="empty">{{ t('apache.no_sites') }}</p>
              <div v-for="vhost in apache.vhosts" :key="vhost.name" class="apache-vhost">
                <strong><code>{{ vhost.name }}</code></strong>
                <pre class="service-logs-pre">{{ vhost.content }}</pre>
              </div>
            </div>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </div>
  </section>
</template>
