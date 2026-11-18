<script setup>
import { computed } from 'vue'

/** One entry from /api/health/containers: { cpu_percent, mem_bytes, mem_limit }. */
const props = defineProps({
  stats: { type: Object, required: true },
})

function formatBytes(bytes) {
  if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`
  return `${Math.round(bytes / 1024 ** 2)} MB`
}

function clamp(percent) {
  return Math.max(0, Math.min(100, percent))
}

const cpu = computed(() => {
  const value = props.stats.cpu_percent
  return {
    text: value == null ? '…' : `${value}%`,
    // CPU can pass 100% on multi-core hosts; the bar just tops out.
    percent: value == null ? 0 : clamp(value),
  }
})

const mem = computed(() => {
  const { mem_bytes: used, mem_limit: limit } = props.stats
  return {
    text: formatBytes(used),
    title: limit ? `${formatBytes(used)} / ${formatBytes(limit)}` : formatBytes(used),
    percent: limit ? clamp((used / limit) * 100) : 0,
  }
})
</script>

<template>
  <span class="container-stats">
    <span class="container-stat" :class="{ 'is-high': cpu.percent >= 80 }" :title="`CPU ${cpu.text}`">
      <span class="container-stat-label">CPU</span>
      <span class="container-stat-value">{{ cpu.text }}</span>
      <span class="container-stat-bar" aria-hidden="true"><span :style="{ width: `${cpu.percent}%` }" /></span>
    </span>
    <span class="container-stat" :class="{ 'is-high': mem.percent >= 80 }" :title="`RAM ${mem.title}`">
      <span class="container-stat-label">RAM</span>
      <span class="container-stat-value">{{ mem.text }}</span>
      <span class="container-stat-bar" aria-hidden="true"><span :style="{ width: `${mem.percent}%` }" /></span>
    </span>
  </span>
</template>
