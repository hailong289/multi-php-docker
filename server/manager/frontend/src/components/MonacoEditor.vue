<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ensureMonacoEnvironment, monacoThemeFromDocument } from '../lib/monaco'

const props = defineProps({
  modelValue: { type: String, default: '' },
  language: { type: String, default: 'ini' },
  readOnly: { type: Boolean, default: false },
  minHeight: { type: String, default: '420px' },
  options: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue', 'ready'])

const host = ref(null)
let editor = null
let monaco = null
let themeObserver = null
let suppressEmit = false

async function mountEditor() {
  if (!host.value || editor) return
  ensureMonacoEnvironment()
  monaco = await import('monaco-editor')
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

onMounted(mountEditor)

onBeforeUnmount(() => {
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
  <div
    ref="host"
    class="monaco-editor-host"
    :style="{ minHeight }"
    :aria-readonly="readOnly ? 'true' : undefined"
  ></div>
</template>
