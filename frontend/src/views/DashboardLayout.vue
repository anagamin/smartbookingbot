<script setup lang="ts">
import http from '@/api/http'
import { onMounted, ref } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'

const router = useRouter()
const user = ref<{
  name: string
  balance_kopecks: number
  bot_paused: boolean
} | null>(null)

async function loadUser() {
  const { data } = await http.get('/user')
  user.value = data
}

async function logout() {
  await http.post('/logout')
  localStorage.removeItem('auth_token')
  router.push('/login')
}

onMounted(loadUser)

const nav = [
  { to: '/app/profile', label: 'Профиль' },
  { to: '/app/work', label: 'Работа' },
  { to: '/app/calendar', label: 'Календарь' },
  { to: '/app/logs', label: 'Лог' },
  { to: '/app/notifications', label: 'Уведомления' },
]

function formatRub(k: number) {
  return (k / 100).toLocaleString('ru-RU', { style: 'currency', currency: 'RUB' })
}
</script>

<template>
  <div class="min-h-screen bg-slate-950">
    <header class="border-b border-white/10 bg-slate-900/50">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4">
        <RouterLink to="/app" class="font-semibold text-white">SmartBookingBot</RouterLink>
        <nav class="flex flex-wrap gap-2">
          <RouterLink
            v-for="item in nav"
            :key="item.to"
            :to="item.to"
            class="rounded-lg px-3 py-1.5 text-sm text-slate-300 hover:bg-white/5 hover:text-white"
            active-class="bg-white/10 text-white"
          >
            {{ item.label }}
          </RouterLink>
        </nav>
        <div class="flex items-center gap-4 text-sm">
          <div v-if="user" class="text-right">
            <div class="text-slate-400">Баланс</div>
            <div class="font-medium text-emerald-400">{{ formatRub(user.balance_kopecks) }}</div>
            <div v-if="user.bot_paused" class="text-xs text-amber-400">Бот на паузе</div>
          </div>
          <button
            type="button"
            class="rounded-lg border border-white/15 px-3 py-1.5 text-slate-300 hover:bg-white/5"
            @click="logout"
          >
            Выйти
          </button>
        </div>
      </div>
    </header>
    <main class="mx-auto max-w-6xl px-4 py-8">
      <RouterView />
    </main>
  </div>
</template>
