<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import SelectButton from 'primevue/selectbutton'
import {
  PRIMARY_COLORS,
  SURFACE_COLORS,
  applyPrimaryColor,
  applySurfaceColor,
  applyThemeMode,
  readStoredPrimaryId,
  readStoredSurfaceId,
  readStoredThemeMode,
} from '../lib/appearance'

const { t } = useI18n()

const panel = ref(null)
const themeMode = ref(readStoredThemeMode())
const primaryId = ref(readStoredPrimaryId())
const surfaceId = ref(readStoredSurfaceId())

const themeOptions = computed(() => [
  { label: t('theme.system'), value: 'system', icon: 'pi pi-desktop' },
  { label: t('theme.light'), value: 'light', icon: 'pi pi-sun' },
  { label: t('theme.dark'), value: 'dark', icon: 'pi pi-moon' },
])

function toggle(event) {
  panel.value?.toggle(event)
}

function onThemeChange(mode) {
  if (!mode) return
  themeMode.value = applyThemeMode(mode)
}

function onPrimaryChange(id) {
  primaryId.value = applyPrimaryColor(id)
}

function onSurfaceChange(id) {
  surfaceId.value = applySurfaceColor(id)
}
</script>

<template>
  <div class="appearance-menu">
    <Button
      type="button"
      class="appearance-trigger"
      icon="pi pi-palette"
      severity="secondary"
      outlined
      size="small"
      :aria-label="t('appearance.menu')"
      :title="t('appearance.menu')"
      data-tour="appearance-menu"
      @click="toggle"
    />
    <Popover ref="panel" class="appearance-popover">
      <div class="appearance-panel">
        <div class="appearance-section">
          <span class="appearance-label">{{ t('appearance.primary') }}</span>
          <div class="appearance-swatches" role="listbox" :aria-label="t('appearance.primary')">
            <button
              v-for="color in PRIMARY_COLORS"
              :key="color.id"
              type="button"
              class="appearance-swatch"
              :class="{
                'is-active': primaryId === color.id,
                'is-noir': color.id === 'noir',
              }"
              :style="{ '--swatch-color': color.swatch, backgroundColor: color.swatch }"
              :title="t(`appearance.color_${color.id}`)"
              :aria-label="t(`appearance.color_${color.id}`)"
              :aria-selected="primaryId === color.id"
              role="option"
              @click="onPrimaryChange(color.id)"
            />
          </div>
        </div>

        <div class="appearance-section">
          <span class="appearance-label">{{ t('appearance.surface') }}</span>
          <div class="appearance-swatches" role="listbox" :aria-label="t('appearance.surface')">
            <button
              v-for="color in SURFACE_COLORS"
              :key="color.id"
              type="button"
              class="appearance-swatch"
              :class="{ 'is-active': surfaceId === color.id }"
              :style="{ '--swatch-color': color.swatch, backgroundColor: color.swatch }"
              :title="t(`appearance.surface_${color.id}`)"
              :aria-label="t(`appearance.surface_${color.id}`)"
              :aria-selected="surfaceId === color.id"
              role="option"
              @click="onSurfaceChange(color.id)"
            />
          </div>
        </div>

        <div class="appearance-section">
          <span class="appearance-label">{{ t('theme.label') }}</span>
          <SelectButton
            :model-value="themeMode"
            :options="themeOptions"
            option-label="label"
            option-value="value"
            :allow-empty="false"
            class="appearance-theme-select"
            :aria-label="t('theme.label')"
            @update:model-value="onThemeChange"
          >
            <template #option="{ option }">
              <i :class="option.icon" aria-hidden="true" />
              <span>{{ option.label }}</span>
            </template>
          </SelectButton>
        </div>
      </div>
    </Popover>
  </div>
</template>
