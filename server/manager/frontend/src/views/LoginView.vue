<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend, setCsrfToken } from '../api'
import { applySessionPayload, authState } from '../lib/authState'

const { t } = useI18n()
const router = useRouter()

const username = ref('')
const password = ref('')
const busy = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    const session = await apiGet('/api/session')
    if (session.csrf_token) setCsrfToken(session.csrf_token)
    applySessionPayload(session)
    if (authState.authenticated && !authState.locked) {
      await router.replace('/')
    }
  } catch (_) {
    error.value = t('error.load')
  }
})

async function submit() {
  busy.value = true
  error.value = ''
  try {
    if (authState.locked) {
      error.value = t('login.locked')
      return
    }
    const result = await apiSend('POST', '/api/login', {
      username: username.value,
      password: password.value,
    })
    if (result.csrf_token) setCsrfToken(result.csrf_token)
    applySessionPayload({
      remote: true,
      authenticated: true,
      locked: false,
      domain: authState.domain,
    })
    password.value = ''
    await router.replace('/')
  } catch (err) {
    const key = err?.payload?.error?.key
    if (key === 'error.manager_remote_locked') error.value = t('login.locked')
    else if (key === 'error.login_rate_limited') error.value = t('login.rate_limited')
    else error.value = t('login.error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="login-panel">
    <h2>{{ t('login.title') }}</h2>
    <p v-if="authState.locked" class="notice failure">{{ t('login.locked') }}</p>
    <form v-else class="login-form" @submit.prevent="submit">
      <label>
        <span>{{ t('login.username') }}</span>
        <input v-model="username" type="text" autocomplete="username" required />
      </label>
      <label>
        <span>{{ t('login.password') }}</span>
        <input v-model="password" type="password" autocomplete="current-password" required />
      </label>
      <p v-if="error" class="notice failure">{{ error }}</p>
      <button type="submit" class="primary" :disabled="busy">
        {{ t('login.submit') }}
      </button>
    </form>
  </section>
</template>
