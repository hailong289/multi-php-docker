<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ensureMonacoEnvironment, monacoThemeFromDocument, registerManagerMonacoThemes } from '../lib/monaco'

const props = defineProps({
  modelValue: { type: String, default: '' },
  language: { type: String, default: 'ini' },
  readOnly: { type: Boolean, default: false },
  minHeight: { type: String, default: '420px' },
  options: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue', 'ready'])

const { t } = useI18n()
const host = ref(null)
const expanded = ref(false)
let editor = null
let monaco = null
let themeObserver = null
let suppressEmit = false

async function mountEditor() {
  if (!host.value || editor) return
  ensureMonacoEnvironment()
  monaco = await import('monaco-editor/esm/vs/editor/editor.api')
  await Promise.all([
    import('monaco-editor/esm/vs/basic-languages/ini/ini.contribution'),
    import('monaco-editor/esm/vs/basic-languages/php/php.contribution'),
    import('monaco-editor/esm/vs/basic-languages/yaml/yaml.contribution'),
  ])
  registerManagerMonacoThemes(monaco)
  editor = monaco.editor.create(host.value, {
    value: props.modelValue,
    language: props.language,
    theme: monacoThemeFromDocument(),
    automaticLayout: true,
    minimap: { enabled: false },
    scrollBeyondLastLine: false,
    wordWrap: 'on',
    fontSize: 13,
    lineHeight: 20,
    tabSize: 4,
    insertSpaces: true,
    renderLineHighlight: 'line',
    padding: { top: 8, bottom: 8 },
    readOnly: props.readOnly,
    ...props.options,
  })

  editor.onDidChangeModelContent(() => {
    if (suppressEmit) return
    emit('update:modelValue', editor.getValue())
  })

  themeObserver = new MutationObserver(() => {
    monaco.editor.setTheme(monacoThemeFromDocument())
  })
  themeObserver.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['data-theme'],
  })

  emit('ready', editor)
}

watch(
  () => props.modelValue,
  (value) => {
    if (!editor) return
    if (editor.getValue() === value) return
    suppressEmit = true
    editor.setValue(value ?? '')
    suppressEmit = false
  },
)

watch(
  () => props.language,
  (language) => {
    if (!editor || !monaco) return
    const model = editor.getModel()
    if (model) monaco.editor.setModelLanguage(model, language)
  },
)

watch(
  () => props.readOnly,
  (readOnly) => {
    editor?.updateOptions({ readOnly })
  },
)

watch(
  () => props.options,
  (options) => {
    if (editor && options) editor.updateOptions(options)
  },
  { deep: true },
)

function onExpandedKey(event) {
  if (event.key === 'Escape') expanded.value = false
}

watch(expanded, (value) => {
  document.body.style.overflow = value ? 'hidden' : ''
  if (value) window.addEventListener('keydown', onExpandedKey)
  else window.removeEventListener('keydown', onExpandedKey)
  editor?.focus()
})

onMounted(mountEditor)

onBeforeUnmount(() => {
  if (expanded.value) expanded.value = false
  window.removeEventListener('keydown', onExpandedKey)
  document.body.style.overflow = ''
  themeObserver?.disconnect()
  themeObserver = null
  editor?.dispose()
  editor = null
  monaco = null
})

defineExpose({
  getEditor: () => editor,
  focus: () => editor?.focus(),
})
</script>

<template>
  <div class="monaco-editor-wrap" :class="{ 'is-expanded': expanded }">
    <div
      ref="host"
      class="monaco-editor-host"
      :style="expanded ? null : { minHeight }"
      :aria-readonly="readOnly ? 'true' : undefined"
    ></div>
    <button
      type="button"
      class="monaco-editor-expand"
      :aria-label="expanded ? t('editor.collapse') : t('editor.expand')"
      :title="expanded ? t('editor.collapse') : t('editor.expand')"
      @click="expanded = !expanded"
    >
      <i :class="expanded ? 'pi pi-window-minimize' : 'pi pi-window-maximize'" aria-hidden="true"></i>
    </button>
  </div>
</template>
