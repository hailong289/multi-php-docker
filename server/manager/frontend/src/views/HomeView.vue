<script setup>
import TableSkeleton from '../components/TableSkeleton.vue'
import { useManager } from '../composables/useManager'

const {
  loading,
  busy,
  modalOpen,
  editingKey,
  fieldErrors,
  data,
  form,
  serverEntries,
  versionLabel,
  openAddModal,
  closeModal,
  startEdit,
  saveServer,
  deleteServer,
  toggleServerEnabled,
  isServerEnabled,
  reloadNginx,
  nginxStatusText,
  nginxStatusOk,
  isPending,
} = useManager()
</script>

<template>
  <section class="panel" data-tour="home-panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <h2>{{ $t('servers.title') }}</h2>
        <div class="panel-heading-actions">
          <button
            type="button"
            class="primary"
            data-tour="home-add"
            :disabled="busy || loading"
            @click="openAddModal"
          >
            {{ $t('form.add') }}
          </button>
          <button
            type="button"
            data-tour="home-reload"
            :class="{ 'is-loading': isPending('reload') }"
            :disabled="busy || loading"
            @click="reloadNginx"
          >
            <span v-if="isPending('reload')" class="btn-spinner" aria-hidden="true"></span>
            {{ isPending('reload') ? $t('action.working') : $t('reload.button') }}
          </button>
        </div>
      </div>
      <p v-if="!loading && data.nginx_status" class="status-line">
        <strong>
          {{ nginxStatusOk() ? $t('reload.success') : $t('reload.error') }}:
        </strong>
        {{ nginxStatusText() }}
      </p>
      <div v-else-if="loading" class="status-line">
        <span class="skeleton-line skeleton-w2"></span>
      </div>
    </div>

    <TableSkeleton
      v-if="loading"
      data-tour="home-table"
      :columns="4"
      :rows="4"
      :headers="[
        $t('table.app_domain'),
        $t('table.php'),
        $t('table.document_root'),
        $t('table.actions'),
      ]"
    />
    <div v-else-if="serverEntries.length === 0" class="empty" data-tour="home-table">{{ $t('servers.empty') }}</div>
    <div v-else class="table-wrap" data-tour="home-table">
      <table>
        <thead>
          <tr>
            <th>{{ $t('table.app_domain') }}</th>
            <th>{{ $t('table.php') }}</th>
            <th>{{ $t('table.document_root') }}</th>
            <th>{{ $t('table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="item in serverEntries"
            :key="item.key"
            :class="{ 'is-disabled': !isServerEnabled(item.server) }"
          >
            <td>
              <strong>{{ item.server.APP_NAME }}</strong>
              <span
                v-if="!isServerEnabled(item.server)"
                class="status-pill status-off"
              >{{ $t('servers.disabled_badge') }}</span>
              <br />
              <a :href="'http://' + item.server.DOMAIN_NAME" target="_blank" rel="noreferrer">
                {{ item.server.DOMAIN_NAME }}
              </a>
            </td>
            <td><code>{{ item.server.CONTAINER_PHP_VERSION }}</code></td>
            <td><code>{{ item.server.SERVER_PATH }}</code></td>
            <td>
              <div class="actions">
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('toggle', { key: item.key }) }"
                  :disabled="busy"
                  :title="
                    isServerEnabled(item.server)
                      ? $t('action.disable_hint')
                      : $t('action.enable_hint')
                  "
                  @click="toggleServerEnabled(item.key)"
                >
                  <span
                    v-if="isPending('toggle', { key: item.key })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('toggle', { key: item.key })
                      ? $t('action.working')
                      : isServerEnabled(item.server)
                        ? $t('action.disable')
                        : $t('action.enable')
                  }}
                </button>
                <button type="button" :disabled="busy" @click="startEdit(item.key)">
                  {{ $t('action.edit') }}
                </button>
                <button
                  type="button"
                  class="danger"
                  :class="{ 'is-loading': isPending('delete', { key: item.key }) }"
                  :disabled="busy"
                  @click="deleteServer(item.key)"
                >
                  <span
                    v-if="isPending('delete', { key: item.key })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('delete', { key: item.key })
                      ? $t('action.working')
                      : $t('action.delete')
                  }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="panel-body command-block">
      <div class="command">
        <strong>{{ $t('apply.title') }}</strong>
        <pre v-if="!loading">{{ data.apply_command }}</pre>
        <div v-else class="skeleton-command">
          <span class="skeleton-line skeleton-w2"></span>
          <span class="skeleton-line skeleton-w1"></span>
        </div>
      </div>
    </div>
  </section>

  <div v-if="modalOpen" class="modal-backdrop" @click.self="!busy && closeModal()">
    <div
      class="modal-panel"
      role="dialog"
      aria-modal="true"
      :aria-label="editingKey ? $t('form.edit_title') : $t('form.add_title')"
    >
      <div class="modal-header">
        <h2>{{ editingKey ? $t('form.edit_title') : $t('form.add_title') }}</h2>
        <button type="button" class="modal-close" :disabled="busy" @click="closeModal">×</button>
      </div>
      <form class="modal-body" @submit.prevent="saveServer">
        <fieldset :disabled="busy" class="modal-fieldset">
          <label>{{ $t('form.app_name') }}</label>
          <input v-model="form.app_name" :placeholder="$t('form.app_placeholder')" required />
          <div v-if="fieldErrors.app_name" class="error">{{ fieldErrors.app_name }}</div>

          <label>{{ $t('form.domain') }}</label>
          <input v-model="form.domain_name" :placeholder="$t('form.domain_placeholder')" required />
          <div v-if="fieldErrors.domain_name" class="error">{{ fieldErrors.domain_name }}</div>

          <label>{{ $t('form.php_version') }}</label>
          <select v-model="form.php_version">
            <option v-for="(cfg, id) in data.php_versions" :key="id" :value="id">
              {{ versionLabel(cfg) }}
            </option>
          </select>
          <div v-if="fieldErrors.php_version" class="error">{{ fieldErrors.php_version }}</div>

          <label>{{ $t('form.server_path') }}</label>
          <input v-model="form.server_path" :placeholder="$t('form.path_placeholder')" required />
          <div v-if="fieldErrors.server_path" class="error">{{ fieldErrors.server_path }}</div>
        </fieldset>

        <div class="form-actions">
          <button
            type="submit"
            class="primary"
            :class="{ 'is-loading': isPending('save') }"
            :disabled="busy"
          >
            <span v-if="isPending('save')" class="btn-spinner" aria-hidden="true"></span>
            {{
              isPending('save')
                ? $t('action.working')
                : editingKey
                  ? $t('form.save')
                  : $t('form.add')
            }}
          </button>
          <button type="button" :disabled="busy" @click="closeModal">
            {{ $t('action.cancel') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
