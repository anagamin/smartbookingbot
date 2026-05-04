<script setup lang="ts">
import http from '@/api/http'
import { onMounted, onUnmounted, provide, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'

const router = useRouter()
const route = useRoute()
const user = ref<{
  name: string
  balance_kopecks: number
  bot_paused: boolean
} | null>(null)

const unreadCount = ref(0)
let lastUnreadBaseline = -1
let pollTimer: ReturnType<typeof setInterval> | null = null

const supportsBrowserPush = typeof globalThis !== 'undefined' && 'Notification' in globalThis

async function loadUser() {
  const { data } = await http.get('/user')
  user.value = data
}

async function pollUnreadSnapshot() {
  try {
    const { data } = await http.get<{
      count: number
      latest: { title: string; body: string | null } | null
    }>('/notifications/unread-snapshot')
    const c = data.count
    if (lastUnreadBaseline >= 0 && c > lastUnreadBaseline && data.latest) {
      pushBrowserNotification(data.latest.title, data.latest.body ?? '')
    }
    lastUnreadBaseline = c
    unreadCount.value = c
  } catch {
    /* ignore */
  }
}

function pushBrowserNotification(title: string, body: string) {
  if (!('Notification' in window) || Notification.permission !== 'granted') {
    return
  }
  try {
    new Notification(title, { body, lang: 'ru' })
  } catch {
    /* ignore */
  }
}

async function requestBrowserNotifications() {
  if (!('Notification' in window)) {
    return
  }
  await Notification.requestPermission()
}

async function logout() {
  await http.post('/logout')
  localStorage.removeItem('auth_token')
  router.push('/login')
}

provide('refreshCabinetUnread', pollUnreadSnapshot)

watch(
  () => route.path,
  (p) => {
    if (p === '/app/notifications') {
      void pollUnreadSnapshot()
    }
  },
)

onMounted(() => {
  void loadUser()
  void pollUnreadSnapshot()
  pollTimer = setInterval(() => void pollUnreadSnapshot(), 45_000)
})

onUnmounted(() => {
  if (pollTimer !== null) {
    clearInterval(pollTimer)
  }
})

const nav = [
  { to: '/app/profile', label: 'Профиль', showUnreadBadge: false },
  { to: '/app/work', label: 'Работа', showUnreadBadge: false },
  { to: '/app/calendar', label: 'Календарь', showUnreadBadge: false },
  { to: '/app/logs', label: 'Лог', showUnreadBadge: false },
  { to: '/app/notifications', label: 'Уведомления', showUnreadBadge: true },
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
        <nav class="flex flex-wrap items-center gap-2">
          <RouterLink
            v-for="item in nav"
            :key="item.to"
            :to="item.to"
            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm text-slate-300 hover:bg-white/5 hover:text-white"
            active-class="bg-white/10 text-white"
          >
            {{ item.label }}
            <span
              v-if="item.showUnreadBadge && unreadCount > 0"
              class="min-w-[1.25rem] rounded-full bg-rose-500 px-1.5 py-0.5 text-center text-[10px] font-semibold leading-none text-white"
            >
              {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
          </RouterLink>
          <button
            v-if="supportsBrowserPush"
            type="button"
            class="rounded-lg border border-white/15 px-2 py-1 text-xs text-slate-400 hover:bg-white/5 hover:text-slate-200"
            title="Показывать всплывающие уведомления браузера при новых сообщениях в кабинете"
            @click="requestBrowserNotifications"
          >
            Пуш в браузере
          </button>
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
