<script setup>
defineProps({
  columns: {
    type: Number,
    default: 4,
  },
  rows: {
    type: Number,
    default: 4,
  },
  headers: {
    type: Array,
    default: () => [],
  },
})
</script>

<template>
  <div class="table-wrap skeleton-wrap" aria-busy="true" aria-live="polite">
    <table class="skeleton-table">
      <thead>
        <tr>
          <th v-for="(header, index) in (headers.length ? headers : columns)" :key="'h-' + index">
            <span v-if="typeof header === 'string'">{{ header }}</span>
            <span v-else class="skeleton-line skeleton-th"></span>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="'r-' + row">
          <td v-for="col in columns" :key="'c-' + row + '-' + col">
            <span class="skeleton-line" :class="'skeleton-w' + ((row + col) % 3)"></span>
            <span
              v-if="col === 1"
              class="skeleton-line skeleton-w1 skeleton-sub"
            ></span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
