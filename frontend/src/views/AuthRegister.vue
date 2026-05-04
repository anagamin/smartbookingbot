<script setup lang="ts">
import http from '@/api/http'
import { ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

const router = useRouter()
const name = ref('')
const email = ref('')
const password = ref('')
const password_confirmation = ref('')
const sex = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    const { data } = await http.post('/register', {
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: password_confirmation.value,
      sex: sex.value || null,
    })
    localStorage.setItem('auth_token', data.token)
    router.push('/app')
  } catch (e: unknown) {
    error.value = 'Проверьте данные. Возможно, email уже занят.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-950 px-4 py-12">
    <div class="w-full max-w-md rounded-2xl border border-white/10 bg-slate-900/60 p-8 shadow-xl">
      <h1 class="text-xl font-semibold text-white">Регистрация</h1>
      <form class="mt-6 space-y-4" @submit.prevent="submit">
        <div>
          <label class="text-xs text-slate-400">Имя</label>
          <input
            v-model="name"
            required
            class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white"
          />
        </div>
        <div>
          <label class="text-xs text-slate-400">Email</label>
          <input
            v-model="email"
            type="email"
            required
            class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white"
          />
        </div>
        <div>
          <label class="text-xs text-slate-400">Пол (для стиля ответов бота)</label>
          <select v-model="sex" class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white">
            <option value="">Не указывать</option>
            <option value="female">Женский</option>
            <option value="male">Мужской</option>
            <option value="other">Другое</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-slate-400">Пароль</label>
          <input
            v-model="password"
            type="password"
            required
            class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white"
          />
        </div>
        <div>
          <label class="text-xs text-slate-400">Пароль ещё раз</label>
          <input
            v-model="password_confirmation"
            type="password"
            required
            class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white"
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
          {{ loading ? '…' : 'Зарегистрироваться' }}
        </button>
      </form>
      <RouterLink to="/login" class="mt-6 block text-center text-sm text-indigo-300">Уже есть аккаунт</RouterLink>
    </div>
  </div>
</template>
