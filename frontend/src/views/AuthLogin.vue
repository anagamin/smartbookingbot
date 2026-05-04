<script setup lang="ts">
import http from '@/api/http'
import { ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

const router = useRouter()
const route = useRoute()
const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    const { data } = await http.post('/login', {
      email: email.value,
      password: password.value,
    })
    localStorage.setItem('auth_token', data.token)
    router.push((route.query.redirect as string) || '/app')
  } catch (e: unknown) {
    error.value = 'Не удалось войти. Проверьте email и пароль.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-950 px-4">
    <div class="w-full max-w-md rounded-2xl border border-white/10 bg-slate-900/60 p-8 shadow-xl">
      <h1 class="text-xl font-semibold text-white">Вход</h1>
      <p class="mt-1 text-sm text-slate-400">Email и пароль, либо соцсети с главной через отдельные ссылки.</p>
      <form class="mt-6 space-y-4" @submit.prevent="submit">
        <div>
          <label class="text-xs text-slate-400">Email</label>
          <input
            v-model="email"
            type="email"
            required
            class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white outline-none focus:border-indigo-500"
          />
        </div>
        <div>
          <label class="text-xs text-slate-400">Пароль</label>
          <input
            v-model="password"
            type="password"
            required
            class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white outline-none focus:border-indigo-500"
          />
        </div>
        <p v-if="error" class="text-sm text-rose-400">
          {{ error }}
        </p>
        <button
          type="submit"
          :disabled="loading"
          class="w-full rounded-lg bg-indigo-500 py-2.5 text-sm font-medium text-white hover:bg-indigo-400 disabled:opacity-50"
        >
          {{ loading ? '…' : 'Войти' }}
        </button>
      </form>
      <div class="mt-6 space-y-2 border-t border-white/10 pt-6">
        <a
          href="/api/oauth/vk/start"
          class="block w-full rounded-lg border border-white/15 py-2 text-center text-sm text-white hover:bg-white/5"
        >
          Войти через VK ID
        </a>
        <a
          href="/api/oauth/yandex/start"
          class="block w-full rounded-lg border border-white/15 py-2 text-center text-sm text-white hover:bg-white/5"
        >
          Войти через Яндекс
        </a>
      </div>
      <RouterLink to="/register" class="mt-6 block text-center text-sm text-indigo-300 hover:text-indigo-200">
        Создать аккаунт
      </RouterLink>
    </div>
  </div>
</template>
