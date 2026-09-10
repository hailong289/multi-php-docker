<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  items: {
    type: Array,
    default: () => [],
  },
  label: {
    type: String,
    default: '',
  },
  align: {
    type: String,
    default: 'end',
  },
})

const { t } = useI18n()
const open = ref(false)
const rootEl = ref(null)
const triggerEl = ref(null)
const menuEl = ref(null)
const panelStyle = ref({})
const placement = ref('bottom')

const visibleItems = computed(() =>
  (props.items || []).filter((item) => item && item.hidden !== true),
)

const menuSections = computed(() => {
  const safe = []
  const danger = []
  for (const item of visibleItems.value) {
    if (item.danger) danger.push(item)
    else safe.push(item)
  }
  const sections = []
  if (safe.length) sections.push({ id: 'safe', items: safe })
  if (danger.length) sections.push({ id: 'danger', items: danger })
  return sections
})

const triggerLabel = computed(() => props.label || t('action.menu'))

const anyLoading = computed(() => visibleItems.value.some((item) => item.loading))

function close() {
  open.value = false
}

function toggle() {
  open.value = !open.value
}

function onItemClick(item) {
  if (item.disabled || item.loading) return
  close()
  item.run?.()
}

function updatePosition() {
  const trigger = triggerEl.value
  if (!trigger) return

  const rect = trigger.getBoundingClientRect()
  const gap = 6
  const menuWidth = Math.max(196, Math.min(280, window.innerWidth - 16))
  const estimatedHeight = Math.min(
    360,
    18 + visibleItems.value.length * 38 + (menuSections.value.length > 1 ? 12 : 0),
  )
  const spaceBelow = window.innerHeight - rect.bottom - gap
  const spaceAbove = rect.top - gap
  const openUp = spaceBelow < estimatedHeight && spaceAbove > spaceBelow
  placement.value = openUp ? 'top' : 'bottom'

  let left =
    props.align === 'start' ? rect.left : rect.right - menuWidth
  left = Math.min(Math.max(8, left), window.innerWidth - menuWidth - 8)

  const style = {
    position: 'fixed',
    left: `${Math.round(left)}px`,
    width: `${Math.round(menuWidth)}px`,
    zIndex: 1200,
  }

  if (openUp) {
    style.bottom = `${Math.round(window.innerHeight - rect.top + gap)}px`
    style.top = 'auto'
    style.maxHeight = `${Math.max(120, Math.round(spaceAbove - 8))}px`
  } else {
    style.top = `${Math.round(rect.bottom + gap)}px`
    style.bottom = 'auto'
    style.maxHeight = `${Math.max(120, Math.round(spaceBelow - 8))}px`
  }

  panelStyle.value = style
}

function onDocumentPointerDown(event) {
  if (!open.value) return
  const root = rootEl.value
  const menu = menuEl.value
  const target = event.target
  if (root?.contains(target) || menu?.contains(target)) return
  close()
}

function onKeydown(event) {
  if (event.key === 'Escape' && open.value) close()
}

function onViewportChange() {
  if (!open.value) return
  updatePosition()
}

watch(open, async (isOpen) => {
  if (!isOpen) return
  await nextTick()
  updatePosition()
  await nextTick()
  menuEl.value?.querySelector('button:not([disabled])')?.focus?.()
})

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown, true)
  document.addEventListener('keydown', onKeydown)
  window.addEventListener('resize', onViewportChange)
  window.addEventListener('scroll', onViewportChange, true)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown, true)
  document.removeEventListener('keydown', onKeydown)
  window.removeEventListener('resize', onViewportChange)
  window.removeEventListener('scroll', onViewportChange, true)
})
</script>

<template>
  <div ref="rootEl" class="action-menu" :class="{ 'is-open': open }">
    <button
      ref="triggerEl"
      type="button"
      class="action-menu-trigger"
      :class="{ 'is-loading': anyLoading, 'is-active': open }"
      :aria-expanded="open ? 'true' : 'false'"
      aria-haspopup="menu"
      @click.stop="toggle"
    >
      <span v-if="anyLoading" class="btn-spinner" aria-hidden="true"></span>
      <span class="action-menu-trigger-label">{{ triggerLabel }}</span>
      <svg
        class="action-menu-caret"
        viewBox="0 0 12 12"
        width="12"
        height="12"
        aria-hidden="true"
        focusable="false"
      >
        <path
          d="M2.5 4.25 6 7.75l3.5-3.5"
          fill="none"
          stroke="currentColor"
          stroke-width="1.6"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </svg>
    </button>
    <Teleport to="body">
      <Transition name="action-menu">
        <div
          v-if="open"
          ref="menuEl"
          class="action-menu-panel"
          :class="{
            'align-start': align === 'start',
            'is-top': placement === 'top',
          }"
          :style="panelStyle"
          role="menu"
          @click.stop
        >
          <template v-if="menuSections.length">
            <div
              v-for="(section, sectionIndex) in menuSections"
              :key="section.id"
              class="action-menu-section"
              :class="{ 'has-divider': sectionIndex > 0 }"
            >
              <button
                v-for="item in section.items"
                :key="item.id"
                type="button"
                role="menuitem"
                class="action-menu-item"
              :class="{
                danger: item.danger,
                'is-accent': item.primary,
                'is-loading': item.loading,
              }"
                :disabled="item.disabled || item.loading"
                @click="onItemClick(item)"
              >
                <span v-if="item.loading" class="btn-spinner" aria-hidden="true"></span>
                <span class="action-menu-item-label">
                  {{ item.loading ? t('action.working') : item.label }}
                </span>
              </button>
            </div>
          </template>
          <p v-else class="action-menu-empty">{{ t('action.menu_empty') }}</p>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
