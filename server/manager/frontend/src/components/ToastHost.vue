<script setup>
import { useManager } from '../composables/useManager'

const { toasts, dismissToast } = useManager()
</script>

<template>
  <div class="toast-host" aria-live="polite" aria-relevant="additions">
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="toast"
      :class="toast.type"
      role="status"
    >
      <span class="toast-text">{{ toast.text }}</span>
      <button
        type="button"
        class="toast-close"
        :aria-label="$t('action.cancel')"
        @click="dismissToast(toast.id)"
      >
        ×
      </button>
    </div>
  </div>
</template>
