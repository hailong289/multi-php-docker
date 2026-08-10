<script setup>
import { ref, computed } from 'vue'
import TableSkeleton from '../components/TableSkeleton.vue'
import { useManager } from '../composables/useManager'
import { authState } from '../lib/authState'

const {
  loading,
  busy,
  data,
  domainEntries,
  domainForm,
  domainModalOpen,
  domainModalMode,
  domainFieldErrors,
  hostsManualOpen,
  hostsManual,
  hostsProgress,
  openHostsDomainAdd,
  openDomainEdit,
  closeDomainModal,
  saveDomain,
  deleteDomain,
  syncHosts,
  writeDomainHostsAdmin,
  closeHostsManual,
  hostsStateLabel,
  hostsStateClass,
  hostsStatusText,
  isPending,
} = useManager()

const copied = ref(false)
const hostsWriteEnabled = computed(
  () => data.hosts_write_enabled !== false && authState.hosts_write_enabled !== false,
)

async function copyManualLines() {
  const text = (hostsManual.value?.lines || []).join('\n')
  if (!text) return
  try {
    await navigator.clipboard.writeText(text)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (_) {
    /* ignore */
  }
}
</script>

<template>
  <section class="panel" data-tour="domains-panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <div>
          <h2>{{ $t('domains.title') }}</h2>
          <p>{{ $t('domains.subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <button
            type="button"
            class="primary"
            data-tour="domains-add"
            :disabled="busy || loading"
            @click="openHostsDomainAdd"
          >
            {{ $t('domains.add') }}
          </button>
          <button
            type="button"
            data-tour="domains-sync"
            :class="{ 'is-loading': isPending('hosts-sync') }"
            :disabled="busy || loading"
            @click="syncHosts"
          >
            <span v-if="isPending('hosts-sync')" class="btn-spinner" aria-hidden="true"></span>
            {{ isPending('hosts-sync') ? $t('action.working') : $t('domains.sync_button') }}
          </button>
        </div>
      </div>
      <p class="status-line">
        <strong>{{ $t('domains.hosts_status') }}:</strong>
        {{ loading ? $t('loading') : hostsStatusText() }}
      </p>
      <p v-if="!hostsWriteEnabled" class="status-line warn">
        {{ $t('hosts.remote_disabled') }}
      </p>
      <div v-if="hostsProgress" class="hosts-progress" aria-live="polite">
        <div class="hosts-progress-head">
          <span class="btn-spinner" aria-hidden="true"></span>
          <strong>{{ $t('hosts.progress_title') }}</strong>
          <span class="hosts-progress-count">
            {{ hostsProgress.attempt }}/{{ hostsProgress.maxAttempts }}
          </span>
        </div>
        <p class="status-line">{{ $t(hostsProgress.message_key) }}</p>
        <div class="hosts-progress-bar" aria-hidden="true">
          <span
            class="hosts-progress-fill"
            :style="{
              width:
                Math.max(8, (hostsProgress.attempt / hostsProgress.maxAttempts) * 100) + '%',
            }"
          ></span>
        </div>
      </div>
      <p v-else-if="!loading && !hostsWriteEnabled" class="status-line">
        {{ $t('hosts.remote_disabled_hint') }}
      </p>
      <p v-else-if="!loading && data.pending_sync" class="status-line warn">
        {{ $t('hosts.watch_required') }}
      </p>
      <p v-else-if="!loading" class="status-line">{{ $t('hosts.sync_hint') }}</p>
    </div>

    <TableSkeleton
      v-if="loading"
      :columns="4"
      :rows="3"
      :headers="[
        $t('domains.table.domain'),
        $t('domains.table.source'),
        $t('domains.table.hosts'),
        $t('domains.table.actions'),
      ]"
    />
    <div v-else-if="domainEntries.length === 0" class="empty">
      <p>{{ $t('domains.empty') }}</p>
      <button type="button" class="primary" :disabled="busy" @click="openHostsDomainAdd">
        {{ $t('domains.add') }}
      </button>
    </div>
    <div v-else class="table-wrap" data-tour="domains-table">
      <table>
        <thead>
          <tr>
            <th>{{ $t('domains.table.domain') }}</th>
            <th>{{ $t('domains.table.source') }}</th>
            <th>{{ $t('domains.table.hosts') }}</th>
            <th>{{ $t('domains.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in domainEntries" :key="item.key">
            <td>
              <a :href="'http://' + item.domain_name" target="_blank" rel="noreferrer">
                {{ item.domain_name }}
              </a>
            </td>
            <td>
              {{
                item.source === 'hosts'
                  ? $t('domains.source.hosts')
                  : item.app_name || $t('domains.source.server')
              }}
            </td>
            <td>
              <span class="state-badge" :class="hostsStateClass(item.hosts_state)">
                {{ hostsStateLabel(item.hosts_state) }}
              </span>
            </td>
            <td>
              <div class="actions">
                <button
                  v-if="hostsWriteEnabled && item.hosts_state !== 'synced'"
                  type="button"
                  class="primary"
                  :class="{
                    'is-loading': isPending('hosts-admin', { domain: item.domain_name }),
                  }"
                  :disabled="busy"
                  :title="$t('domains.write_admin_hint')"
                  @click="writeDomainHostsAdmin(item.domain_name)"
                >
                  <span
                    v-if="isPending('hosts-admin', { domain: item.domain_name })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('hosts-admin', { domain: item.domain_name })
                      ? $t('action.working')
                      : $t('domains.write_admin')
                  }}
                </button>
                <button type="button" :disabled="busy" @click="openDomainEdit(item.key)">
                  {{ $t('action.edit') }}
                </button>
                <button
                  v-if="item.source === 'hosts'"
                  type="button"
                  class="danger"
                  :class="{ 'is-loading': isPending('delete', { key: item.key }) }"
                  :disabled="busy"
                  @click="deleteDomain(item.key)"
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
  </section>

  <div v-if="domainModalOpen" class="modal-backdrop" @click.self="!busy && closeDomainModal()">
    <div
      class="modal-panel"
      role="dialog"
      aria-modal="true"
      :aria-label="domainModalMode === 'add' ? $t('domains.add_title') : $t('domains.edit_title')"
    >
      <div class="modal-header">
        <h2>{{ domainModalMode === 'add' ? $t('domains.add_title') : $t('domains.edit_title') }}</h2>
        <button type="button" class="modal-close" :disabled="busy" @click="closeDomainModal">×</button>
      </div>
      <form class="modal-body" @submit.prevent="saveDomain">
        <fieldset :disabled="busy" class="modal-fieldset">
          <label>{{ $t('form.domain') }}</label>
          <input
            v-model="domainForm.domain_name"
            :placeholder="$t('form.domain_placeholder')"
            required
          />
          <div v-if="domainFieldErrors.domain_name" class="error">
            {{ domainFieldErrors.domain_name }}
          </div>
          <p v-if="domainModalMode === 'add'" class="status-line">{{ $t('domains.add_hosts_only') }}</p>
        </fieldset>
        <div class="form-actions">
          <button
            type="submit"
            class="primary"
            :class="{ 'is-loading': isPending('domain-save') }"
            :disabled="busy"
          >
            <span v-if="isPending('domain-save')" class="btn-spinner" aria-hidden="true"></span>
            {{
              isPending('domain-save')
                ? $t('action.working')
                : domainModalMode === 'add'
                  ? $t('domains.add')
                  : $t('domains.save')
            }}
          </button>
          <button type="button" :disabled="busy" @click="closeDomainModal">
            {{ $t('action.cancel') }}
          </button>
        </div>
      </form>
    </div>
  </div>

  <div v-if="hostsManualOpen && hostsManual" class="modal-backdrop" @click.self="closeHostsManual">
    <div class="modal-panel" role="dialog" aria-modal="true" :aria-label="$t('hosts.manual_title')">
      <div class="modal-header">
        <h2>{{ $t('hosts.manual_title') }}</h2>
        <button type="button" class="modal-close" @click="closeHostsManual">×</button>
      </div>
      <div class="modal-body">
        <p class="status-line">{{ $t('hosts.manual_intro') }}</p>
        <label>{{ $t('hosts.manual_path_windows') }}</label>
        <code class="manual-path">{{
          hostsManual.hosts_path_windows || hostsManual.hosts_path || 'C:\\Windows\\System32\\drivers\\etc\\hosts'
        }}</code>
        <label>{{ $t('hosts.manual_path_unix') }}</label>
        <code class="manual-path">{{ hostsManual.hosts_path_unix || '/etc/hosts' }}</code>
        <label>{{ $t('hosts.manual_lines') }}</label>
        <pre class="manual-lines">{{ (hostsManual.lines || []).join('\n') }}</pre>
        <div class="form-actions">
          <button type="button" class="primary" @click="copyManualLines">
            {{ copied ? $t('hosts.manual_copied') : $t('hosts.manual_copy') }}
          </button>
          <button type="button" @click="closeHostsManual">{{ $t('hosts.manual_close') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
