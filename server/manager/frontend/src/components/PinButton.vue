<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import { usePinnedContainers } from '../composables/usePinnedContainers'

const props = defineProps({
  kind: { type: String, required: true },
  id: { type: String, required: true },
})

const { t } = useI18n()
const { isPinned, togglePin } = usePinnedContainers()
const pinned = computed(() => isPinned(props.kind, props.id))
const label = computed(() => (pinned.value ? t('pin.from_home') : t('pin.to_home')))

function onClick() {
  togglePin(props.kind, props.id)
}
</script>

<template>
  <Button
    type="button"
    class="pin-button"
    size="small"
    text
    rounded
    :icon="pinned ? 'pi pi-bookmark-fill' : 'pi pi-bookmark'"
    :severity="pinned ? 'primary' : 'secondary'"
    :aria-label="label"
    :title="label"
    :aria-pressed="pinned"
    @click="onClick"
  />
</template>
