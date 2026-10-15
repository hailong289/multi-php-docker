<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const { t } = useI18n()
const {
  loadBootstrap,
  showToast,
  translateApiError,
} = useManager()

const loading = ref(true)
const refreshing = ref(false)

const connectionLoading = ref(false)
const connectionSaving = ref(false)
const connectionTesting = ref(false)
const connection = ref(null)
const connectionForm = ref(emptyConnectionForm())

const modeOptions = computed(() => [
  { label: t('docker_connection.mode_local'), value: 'local' },
  { label: t('docker_connection.mode_tcp'), value: 'tcp_tls' },
  { label: t('docker_connection.mode_ssh'), value: 'ssh' },
])

function emptyConnectionForm() {
  return {
    mode: 'local',
    tcp: { host: '', port: 2376, tls: true, ca: '', cert: '', key: '' },
    ssh: { user: '', host: '', port: 22, identity_file: '' },
    remote_project_path: '',
  }
}

function applyConnectionPayload(payload) {
  connection.value = payload || null
  const src = payload || emptyConnectionForm()
  connectionForm.value = {
    mode: src.mode || 'local',
    tcp: {
      host: src.tcp?.host || '',
      port: src.tcp?.port || 2376,
      tls: src.tcp?.tls !== false,
      ca: src.tcp?.ca || '',
      cert: src.tcp?.cert || '',
      key: src.tcp?.key || '',
    },
    ssh: {
      user: src.ssh?.user || '',
      host: src.ssh?.host || '',
      port: src.ssh?.port || 22,
      identity_file: src.ssh?.identity_file || '',
    },
    remote_project_path: src.remote_project_path || '',
  }
}

async function loadConnection({ quiet = false } = {}) {
  if (!quiet) connectionLoading.value = true
  try {
    const result = await apiGet('/api/docker-connection')
    applyConnectionPayload(result.docker_connection)
  } catch (error) {
    if (!quiet) showToast('failure', translateApiError(error))
  } finally {
    connectionLoading.value = false
  }
}

async function testConnection() {
  connectionTesting.value = true
  try {
    const result = await apiSend('POST', '/api/docker-connection/test', connectionForm.value)
    showToast(result.ok ? 'success' : 'failure', t(result.message_key || 'docker_connection.test_failed'))
    if (connection.value) {
      connection.value = { ...connection.value, reachable: !!result.ok }
    }
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    connectionTesting.value = false
  }
}

async function saveConnection() {
  connectionSaving.value = true
  try {
    const result = await apiSend('PUT', '/api/docker-connection', connectionForm.value)
    showToast('success', t(result.message_key || 'docker_connection.saved'))
    if (result.docker_connection) applyConnectionPayload(result.docker_connection)
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    connectionSaving.value = false
  }
}

const showInitialLoading = computed(() => loading.value && connection.value == null)

function connectionReachableSeverity() {
  if (connection.value?.reachable === true) return 'success'
  if (connection.value?.reachable === false) return 'danger'
  return 'secondary'
}

async function refreshAll() {
  refreshing.value = true
  try {
    await loadConnection({ quiet: true })
  } finally {
    refreshing.value = false
  }
}

onMounted(async () => {
  loading.value = true
  try {
    await loadBootstrap({ silent: true })
    await loadConnection()
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section class="panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <div>
          <h2>{{ t('docker_connection.title') }}</h2>
          <p>{{ t('docker_connection.subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <Button
            type="button"
            :label="refreshing ? t('action.working') : t('nginx.refresh')"
            :loading="refreshing"
            :disabled="showInitialLoading || refreshing"
            @click="refreshAll"
          />
        </div>
      </div>
    </div>

    <div class="panel-body">
      <div v-if="showInitialLoading" class="nginx-tab-pad">{{ t('loading') }}</div>
      <div v-else class="nginx-control-stack">
        <article class="php-daemon-connection">
          <div class="php-daemon-connection-head">
            <div>
              <h3>{{ t('docker_connection.title') }}</h3>
              <p>{{ t('docker_connection.subtitle') }}</p>
            </div>
            <div class="php-daemon-connection-status">
              <Tag
                :value="connection?.effective?.label || t('docker_connection.mode_local')"
                :severity="connectionReachableSeverity()"
                rounded
              />
              <Tag
                v-if="connection?.reachable === true"
                :value="t('docker_connection.reachable')"
                severity="success"
                rounded
              />
              <Tag
                v-else-if="connection?.reachable === false"
                :value="t('docker_connection.unreachable')"
                severity="danger"
                rounded
              />
            </div>
          </div>

          <Message severity="warn" :closable="false" class="php-daemon-connection-warn">
            {{ t('docker_connection.remote_bind_warning') }}
          </Message>

          <div v-if="connectionLoading" class="nginx-tab-pad">{{ t('loading') }}</div>
          <div v-else class="php-daemon-connection-form">
            <label class="php-daemon-field">
              <span>{{ t('docker_connection.mode') }}</span>
              <Select v-model="connectionForm.mode" :options="modeOptions" option-label="label" option-value="value" />
            </label>

            <template v-if="connectionForm.mode === 'tcp_tls'">
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.tcp_host') }}</span>
                <InputText v-model="connectionForm.tcp.host" fluid />
              </label>
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.tcp_port') }}</span>
                <InputNumber v-model="connectionForm.tcp.port" :min="1" :max="65535" fluid />
              </label>
              <label class="php-daemon-field php-daemon-field-check">
                <Checkbox v-model="connectionForm.tcp.tls" binary />
                <span>{{ t('docker_connection.tcp_tls') }}</span>
              </label>
              <template v-if="connectionForm.tcp.tls">
                <label class="php-daemon-field">
                  <span>{{ t('docker_connection.tcp_ca') }}</span>
                  <InputText v-model="connectionForm.tcp.ca" fluid :placeholder="t('docker_connection.path_placeholder')" />
                </label>
                <label class="php-daemon-field">
                  <span>{{ t('docker_connection.tcp_cert') }}</span>
                  <InputText v-model="connectionForm.tcp.cert" fluid :placeholder="t('docker_connection.path_placeholder')" />
                </label>
                <label class="php-daemon-field">
                  <span>{{ t('docker_connection.tcp_key') }}</span>
                  <InputText v-model="connectionForm.tcp.key" fluid :placeholder="t('docker_connection.path_placeholder')" />
                </label>
              </template>
            </template>

            <template v-if="connectionForm.mode === 'ssh'">
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.ssh_user') }}</span>
                <InputText v-model="connectionForm.ssh.user" fluid />
              </label>
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.ssh_host') }}</span>
                <InputText v-model="connectionForm.ssh.host" fluid />
              </label>
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.ssh_port') }}</span>
                <InputNumber v-model="connectionForm.ssh.port" :min="1" :max="65535" fluid />
              </label>
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.ssh_identity') }}</span>
                <InputText
                  v-model="connectionForm.ssh.identity_file"
                  fluid
                  :placeholder="t('docker_connection.path_placeholder')"
                />
              </label>
            </template>

            <label v-if="connectionForm.mode !== 'local'" class="php-daemon-field php-daemon-field-wide">
              <span>{{ t('docker_connection.remote_project_path') }}</span>
              <InputText v-model="connectionForm.remote_project_path" fluid />
              <small>{{ t('docker_connection.remote_project_hint') }}</small>
            </label>

            <div class="php-daemon-connection-actions">
              <Button
                type="button"
                size="small"
                severity="secondary"
                outlined
                :label="connectionTesting ? t('action.working') : t('docker_connection.test')"
                :loading="connectionTesting"
                :disabled="connectionSaving || connectionTesting"
                @click="testConnection"
              />
              <Button
                type="button"
                size="small"
                :label="connectionSaving ? t('action.working') : t('docker_connection.save')"
                :loading="connectionSaving"
                :disabled="connectionSaving || connectionTesting"
                @click="saveConnection"
              />
            </div>
          </div>
        </article>

      </div>
    </div>
  </section>
</template>

<style scoped>
.php-daemon-connection {
  display: grid;
  gap: 0.85rem;
  padding: 1rem;
  border: 1px solid var(--p-content-border-color, var(--line));
  border-radius: 10px;
  background: color-mix(in srgb, var(--p-card-background, var(--panel)) 94%, var(--p-primary-color, var(--primary)) 6%);
}

.php-daemon-connection-head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: flex-start;
}

.php-daemon-connection-head h3 {
  margin: 0;
  font-size: 1rem;
}

.php-daemon-connection-head p {
  margin: 0.35rem 0 0;
  opacity: 0.8;
  font-size: 0.9rem;
}

.php-daemon-connection-status {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.php-daemon-connection-form {
  display: grid;
  gap: 0.75rem 1rem;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
}

.php-daemon-field {
  display: grid;
  gap: 0.35rem;
}

.php-daemon-field span {
  font-size: 0.85rem;
  opacity: 0.8;
}

.php-daemon-field small {
  opacity: 0.7;
}

.php-daemon-field-check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.php-daemon-field-wide {
  grid-column: 1 / -1;
}

.php-daemon-connection-actions {
  grid-column: 1 / -1;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

</style>
