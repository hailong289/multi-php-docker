<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import LocalDomainInput from '../components/LocalDomainInput.vue'
import { useManager } from '../composables/useManager'
import { authState } from '../lib/authState'
import { HOSTS_PROTOCOL_WINDOW, hostsProtocolUrl } from '../lib/hostsProtocol'

const { t } = useI18n()

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
  removeDomainEntry,
  writeDomainHostsAdmin,
  closeHostsManual,
  hostsStateLabel,
  hostsStatusText,
  isPending,
} = useManager()

const copied = ref(false)
const hostsWriteEnabled = computed(
  () => data.hosts_write_enabled !== false && authState.hosts_write_enabled !== false,
)
const hostsWriterHref = hostsProtocolUrl()

function hostsSeverity(state) {
  if (state === 'synced') return 'success'
  if (state === 'missing' || state === 'stale') return 'warn'
  return 'secondary'
}

function sourceLabel(item) {
  if (item.source === 'hosts') return t('domains.source.hosts')
  return item.app_name || t('domains.source.server')
}

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
          <h2>{{ t('domains.title') }}</h2>
          <p>{{ t('domains.subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <Button
            type="button"
            data-tour="domains-add"
            :label="t('domains.add')"
            :disabled="busy || loading"
            @click="openHostsDomainAdd"
          />
          <span
            v-if="hostsProgress"
            class="hosts-action-chip"
            role="status"
            aria-live="polite"
          >
            <span class="hosts-action-chip-dot" aria-hidden="true"></span>
            <span class="hosts-action-chip-text">{{ t(hostsProgress.message_key) }}</span>
          </span>
        </div>
      </div>
      <p class="status-line">
        <strong>{{ t('domains.hosts_status') }}:</strong>
        {{ loading ? t('loading') : hostsStatusText() }}
      </p>
      <Message
        v-if="!hostsWriteEnabled"
        severity="warn"
        :closable="false"
        class="domains-status-msg"
      >
        {{ t('hosts.remote_disabled') }}
      </Message>
      <p v-if="!loading && !hostsProgress && !hostsWriteEnabled" class="status-line">
        {{ t('hosts.remote_disabled_hint') }}
      </p>
      <Message
        v-else-if="!loading && !hostsProgress && data.pending_sync"
        severity="warn"
        :closable="false"
        class="domains-status-msg"
      >
        {{ t('hosts.watch_required') }}
        <a
          v-if="hostsWriteEnabled"
          :href="hostsWriterHref"
          :target="HOSTS_PROTOCOL_WINDOW"
          rel="noopener"
          class="hosts-writer-link"
        >{{ t('hosts.open_writer') }}</a>
      </Message>
      <p v-else-if="!loading && !hostsProgress" class="status-line">{{ t('hosts.sync_hint') }}</p>
    </div>

    <div v-if="loading" class="domains-loading">
      <DataTable :value="[{}, {}, {}]" :loading="true">
        <Column :header="t('domains.table.domain')" />
        <Column :header="t('domains.table.source')" />
        <Column :header="t('domains.table.hosts')" />
        <Column :header="t('domains.table.actions')" />
      </DataTable>
    </div>
    <div v-else-if="domainEntries.length === 0" class="empty">
      <p>{{ t('domains.empty') }}</p>
      <Button
        type="button"
        :label="t('domains.add')"
        :disabled="busy"
        @click="openHostsDomainAdd"
      />
    </div>
    <div v-else data-tour="domains-table">
      <DataTable :value="domainEntries" data-key="key" striped-rows>
        <Column :header="t('domains.table.domain')">
          <template #body="{ data: row }">
            <a :href="'http://' + row.domain_name" target="_blank" rel="noreferrer">
              {{ row.domain_name }}
            </a>
          </template>
        </Column>
        <Column :header="t('domains.table.source')">
          <template #body="{ data: row }">
            {{ sourceLabel(row) }}
          </template>
        </Column>
        <Column :header="t('domains.table.hosts')">
          <template #body="{ data: row }">
            <Tag
              :value="hostsStateLabel(row.hosts_state)"
              :severity="hostsSeverity(row.hosts_state)"
              rounded
            />
          </template>
        </Column>
        <Column :header="t('domains.table.actions')">
          <template #body="{ data: row }">
            <div class="actions">
              <Button
                v-if="hostsWriteEnabled && row.hosts_state !== 'synced'"
                type="button"
                size="small"
                :label="
                  isPending('hosts-admin', { domain: row.domain_name })
                    ? t('action.working')
                    : t('domains.write_admin')
                "
                :loading="isPending('hosts-admin', { domain: row.domain_name })"
                :disabled="busy"
                :title="t('domains.write_admin_hint')"
                @click="writeDomainHostsAdmin(row.domain_name)"
              />
              <Button
                type="button"
                size="small"
                :label="t('action.edit')"
                :disabled="busy"
                @click="openDomainEdit(row.key)"
              />
              <Button
                type="button"
                size="small"
                severity="danger"
                outlined
                :label="
                  isPending('delete', { key: row.key })
                    ? t('action.working')
                    : t('action.delete')
                "
                :loading="isPending('delete', { key: row.key })"
                :disabled="busy"
                :title="
                  row.source === 'hosts'
                    ? t('domains.delete_hosts_hint')
                    : t('domains.delete_server_hint')
                "
                @click="removeDomainEntry(row)"
              />
            </div>
          </template>
        </Column>
      </DataTable>
    </div>
  </section>

  <Dialog
    :visible="domainModalOpen"
    modal
    :header="domainModalMode === 'add' ? t('domains.add_title') : t('domains.edit_title')"
    :closable="!busy"
    :dismissable-mask="!busy"
    :style="{ width: 'min(640px, 100%)' }"
    @update:visible="(v) => { if (!v) closeDomainModal() }"
  >
    <form class="domains-modal-form" @submit.prevent="saveDomain">
      <fieldset :disabled="busy" class="modal-fieldset">
        <label>{{ t('form.domain') }}</label>
        <LocalDomainInput
          v-model:name="domainForm.domain_label"
          v-model:tld="domainForm.domain_tld"
          v-model:custom="domainForm.domain_custom"
        />
        <small v-if="domainFieldErrors.domain_name" class="p-error">
          {{ domainFieldErrors.domain_name }}
        </small>
        <p v-if="domainModalMode === 'add'" class="status-line">{{ t('domains.add_hosts_only') }}</p>
      </fieldset>
      <div class="form-actions">
        <Button
          type="submit"
          :label="
            isPending('domain-save')
              ? t('action.working')
              : domainModalMode === 'add'
                ? t('domains.add')
                : t('domains.save')
          "
          :loading="isPending('domain-save')"
          :disabled="busy"
        />
        <Button
          type="button"
          severity="secondary"
          outlined
          :label="t('action.cancel')"
          :disabled="busy"
          @click="closeDomainModal"
        />
      </div>
    </form>
  </Dialog>

  <Dialog
    :visible="hostsManualOpen && !!hostsManual"
    modal
    :header="t('hosts.manual_title')"
    :style="{ width: 'min(640px, 100%)' }"
    @update:visible="(v) => { if (!v) closeHostsManual() }"
  >
    <div v-if="hostsManual" class="domains-manual-body">
      <p class="status-line">{{ t('hosts.manual_intro') }}</p>
      <label>{{ t('hosts.manual_path_windows') }}</label>
      <code class="manual-path">{{
        hostsManual.hosts_path_windows || hostsManual.hosts_path || 'C:\\Windows\\System32\\drivers\\etc\\hosts'
      }}</code>
      <label>{{ t('hosts.manual_path_unix') }}</label>
      <code class="manual-path">{{ hostsManual.hosts_path_unix || '/etc/hosts' }}</code>
      <label>{{ t('hosts.manual_lines') }}</label>
      <pre class="manual-lines">{{ (hostsManual.lines || []).join('\n') }}</pre>
      <div class="form-actions">
        <Button
          type="button"
          :label="copied ? t('hosts.manual_copied') : t('hosts.manual_copy')"
          @click="copyManualLines"
        />
        <Button
          type="button"
          severity="secondary"
          outlined
          :label="t('hosts.manual_close')"
          @click="closeHostsManual"
        />
      </div>
    </div>
  </Dialog>
</template>
