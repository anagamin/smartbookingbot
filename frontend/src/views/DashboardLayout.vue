<script setup lang="ts">
import http from '@/api/http'
import { cabinetBrowserPushActive } from '@/lib/cabinetBrowserPush'
import { computed, onMounted, onUnmounted, provide, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'

const router = useRouter()
const route = useRoute()

type CabinetUser = {
  name: string
  balance_kopecks: number
  bot_paused: boolean
  subscription_active?: boolean
  subscription_ends_at?: string | null
  trial_active?: boolean
  days_until_subscription_ends?: number | null
  bot_responds_to_clients?: boolean
}

const user = ref<CabinetUser | null>(null)

const unreadCount = ref(0)
let lastUnreadBaseline = -1
let pollTimer: ReturnType<typeof setInterval> | null = null

async function loadUser() {
  const { data } = await http.get<CabinetUser>('/user')
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
  if (!cabinetBrowserPushActive()) {
    return
  }
  try {
    new Notification(title, { body, lang: 'ru' })
  } catch {
    /* ignore */
  }
}

async function logout() {
  await http.post('/logout')
  localStorage.removeItem('auth_token')
  router.push('/login')
}

provide('refreshCabinetUnread', pollUnreadSnapshot)
provide('reloadCabinetUser', loadUser)

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
  { to: '/app/billing', label: 'Оплата', showUnreadBadge: false },
  { to: '/app/notifications', label: 'Уведомления', showUnreadBadge: true },
  { to: '/app/contact', label: 'Свяжитесь с нами', showUnreadBadge: false },
]

const subscriptionEndLabel = computed(() => {
  const u = user.value
  if (!u?.subscription_ends_at) {
    return '—'
  }
  const d = new Date(u.subscription_ends_at)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' })
})

const subscriptionChipClass = computed(() => {
  const u = user.value
  if (!u?.subscription_active) {
    return 'text-rose-300 ring-rose-500/40 bg-rose-950/40'
  }
  const days = u.days_until_subscription_ends
  if (typeof days === 'number' && days <= 3) {
    return 'text-amber-200 ring-amber-500/45 bg-amber-950/50'
  }
  if (typeof days === 'number' && days <= 7) {
    return 'text-amber-100/90 ring-amber-400/30 bg-amber-950/30'
  }
  if (u.trial_active) {
    return 'text-sky-200 ring-sky-500/35 bg-sky-950/40'
  }
  return 'text-emerald-200 ring-emerald-500/35 bg-emerald-950/35'
})

const subscriptionChipHint = computed(() => {
  const u = user.value
  if (!u) {
    return ''
  }
  if (!u.subscription_active) {
    return 'Подписка не активна'
  }
  if (u.trial_active) {
    return 'Пробный период'
  }
  const days = u.days_until_subscription_ends
  if (typeof days === 'number' && days === 0) {
    return 'Истекает сегодня'
  }
  if (typeof days === 'number' && days <= 7) {
    return `Осталось дн.: ${days}`
  }
  return 'Подписка активна'
})

const showExpiredBanner = computed(() => user.value && !user.value.subscription_active)

const showExpiryWarningBanner = computed(() => {
  const u = user.value
  if (!u?.subscription_active) {
    return false
  }
  const days = u.days_until_subscription_ends
  return typeof days === 'number' && days > 0 && days <= 7
})

const expiryWarningText = computed(() => {
  const u = user.value
  if (!u?.subscription_ends_at) {
    return ''
  }
  const days = u.days_until_subscription_ends ?? 0
  const dateStr = new Date(u.subscription_ends_at).toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
  if (days <= 1) {
    return `Подписка заканчивается ${dateStr}. Продлите заранее, чтобы бот не пропал из переписки с клиентами.`
  }
  return `Подписка заканчивается ${dateStr} (через ${days} дн.). Продлите заранее.`
})

const showPausedWithSubBanner = computed(
  () => user.value?.subscription_active && user.value.bot_paused && !showExpiredBanner.value,
)

const mobileNavOpen = ref(false)

function toggleMobileNav() {
  mobileNavOpen.value = !mobileNavOpen.value
}

function closeMobileNav() {
  mobileNavOpen.value = false
}

watch(
  () => route.path,
  () => {
    closeMobileNav()
  },
)
</script>

<template>
  <div class="min-h-screen bg-slate-950">
    <header class="border-b border-white/10 bg-slate-900/50">
      <div class="mx-auto max-w-6xl px-4 py-4">
        <div class="flex items-center justify-between gap-3">
          <RouterLink to="/app" class="font-semibold text-white" @click="closeMobileNav">
            SmartBookingBot
          </RouterLink>

          <button
            type="button"
            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-white/15 text-slate-200 hover:bg-white/5 md:hidden"
            :aria-expanded="mobileNavOpen"
            aria-controls="cabinet-mobile-nav"
            aria-label="Меню"
            @click="toggleMobileNav"
          >
            <svg
              v-if="!mobileNavOpen"
              class="h-5 w-5"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
            </svg>
            <svg
              v-else
              class="h-5 w-5"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>

          <nav class="hidden items-center gap-2 md:flex">
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
          </nav>

          <div class="hidden items-center justify-end gap-3 text-sm md:flex">
          <RouterLink
            v-if="user"
            to="/app/billing"
            class="group max-w-[min(100vw-2rem,16rem)] rounded-xl px-3 py-2 text-right ring-1 transition hover:brightness-110"
            :class="subscriptionChipClass"
          >
            <div class="text-[10px] font-medium uppercase tracking-wide text-white/50 group-hover:text-white/70">
              Сервис до
            </div>
            <div class="truncate text-sm font-semibold leading-tight">{{ subscriptionEndLabel }}</div>
            <div class="truncate text-[11px] leading-tight opacity-90">{{ subscriptionChipHint }}</div>
          </RouterLink>
            <button
              type="button"
              class="rounded-lg border border-white/15 px-3 py-1.5 text-slate-300 hover:bg-white/5"
              @click="logout"
            >
              Выйти
            </button>
          </div>
        </div>

        <div
          v-show="mobileNavOpen"
          id="cabinet-mobile-nav"
          class="mt-4 border-t border-white/10 pt-4 md:hidden"
        >
          <nav class="flex flex-col gap-1">
            <RouterLink
              v-for="item in nav"
              :key="item.to"
              :to="item.to"
              class="inline-flex items-center justify-between rounded-lg px-3 py-2.5 text-sm text-slate-300 hover:bg-white/5 hover:text-white"
              active-class="bg-white/10 text-white"
              @click="closeMobileNav"
            >
              <span>{{ item.label }}</span>
              <span
                v-if="item.showUnreadBadge && unreadCount > 0"
                class="min-w-[1.25rem] rounded-full bg-rose-500 px-1.5 py-0.5 text-center text-[10px] font-semibold leading-none text-white"
              >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
              </span>
            </RouterLink>
          </nav>
          <div class="mt-4 flex flex-col gap-3 border-t border-white/10 pt-4 text-sm">
            <RouterLink
              v-if="user"
              to="/app/billing"
              class="group rounded-xl px-3 py-2 ring-1 transition hover:brightness-110"
              :class="subscriptionChipClass"
              @click="closeMobileNav"
            >
              <div class="text-[10px] font-medium uppercase tracking-wide text-white/50">Сервис до</div>
              <div class="text-sm font-semibold leading-tight">{{ subscriptionEndLabel }}</div>
              <div class="text-[11px] leading-tight opacity-90">{{ subscriptionChipHint }}</div>
            </RouterLink>
            <button
              type="button"
              class="w-full rounded-lg border border-white/15 px-3 py-2 text-slate-300 hover:bg-white/5"
              @click="logout"
            >
              Выйти
            </button>
          </div>
        </div>
      </div>
    </header>

    <div
      v-if="showExpiredBanner"
      class="border-b border-rose-500/40 bg-gradient-to-r from-rose-950/90 to-slate-950 px-4 py-4 text-rose-50"
      role="alert"
    >
      <div class="mx-auto flex max-w-6xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p class="text-base font-semibold text-white">Бот не работает — подписка не активна</p>
          <p class="mt-1 max-w-2xl text-sm text-rose-100/90">
            Клиенты не получают ответы от бота в сообщениях сообщества. Оформите подписку, чтобы восстановить доступ.
          </p>
        </div>
        <RouterLink
          to="/app/billing"
          class="inline-flex shrink-0 items-center justify-center rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-rose-900 shadow hover:bg-rose-50"
        >
          Перейти к оплате
        </RouterLink>
      </div>
    </div>

    <div
      v-else-if="showExpiryWarningBanner"
      class="border-b border-amber-500/35 bg-amber-950/35 px-4 py-3 text-amber-50"
      role="status"
    >
      <div class="mx-auto flex max-w-6xl flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm leading-relaxed">{{ expiryWarningText }}</p>
        <RouterLink
          to="/app/billing"
          class="inline-flex shrink-0 justify-center rounded-lg border border-amber-400/50 bg-amber-500/15 px-3 py-1.5 text-sm font-medium text-amber-100 hover:bg-amber-500/25"
        >
          Продлить
        </RouterLink>
      </div>
    </div>

    <div
      v-else-if="showPausedWithSubBanner"
      class="border-b border-slate-600/50 bg-slate-900/80 px-4 py-3 text-slate-200"
      role="status"
    >
      <div class="mx-auto flex max-w-6xl flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-3">
        <p class="text-sm font-medium text-white">Бот на паузе</p>
        <p class="text-sm text-slate-400">
          Подписка активна, но ответы клиентам отключены вручную. Включите бота в разделе «Профиль».
        </p>
      </div>
    </div>

    <main class="mx-auto max-w-6xl px-4 py-8">
      <RouterView />
    </main>
  </div>
</template>
