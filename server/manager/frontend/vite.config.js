import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  base: '/server-manage/',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    proxy: {
      '/server-manage/api': {
        target: 'http://127.0.0.1:8080',
        rewrite: (path) => path.replace(/^\/server-manage/, ''),
      },
      '/api': 'http://127.0.0.1:8080',
    },
  },
  optimizeDeps: {
    include: ['monaco-editor'],
  },
  worker: {
    format: 'es',
  },
  build: {
    outDir: '../public',
    emptyOutDir: true,
    assetsDir: 'assets',
    chunkSizeWarningLimit: 2000,
  },
})
