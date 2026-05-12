<script setup lang="ts">
import http from '@/api/http'
import {
  cabinetBrowserPushSupported,
  getCabinetBrowserPushOptIn,
  setCabinetBrowserPushOptIn,
} from '@/lib/cabinetBrowserPush'
import { computed, inject, onMounted, ref } from 'vue'

const items = ref<Array<{ id: number; title: string; body: string | null; read_at: string | null; created_at: string }>>([])

const refreshCabinetUnread = inject<() => Promise<void>>('refreshCabinetUnread', async () => {})

const supportsBrowserPush = cabinetBrowserPushSupported()

const browserPushOptIn = ref(false)
const pushBusy = ref(false)
const pushHint = ref('')
const pushHintIsError = ref(false)

const pushActive = computed(
  () => supportsBrowserPush && browserPushOptIn.value && Notification.permission === 'granted',
)

const browserPushStatusLine = computed(() => {
  if (!supportsBrowserPush) {
    return 'В этом браузере недоступны всплывающие уведомления.'
  }
  if (!browserPushOptIn.value) {
    return 'Всплывающие уведомления выключены — новые события в кабинете не показываются вне вкладки.'
  }
  if (Notification.permission === 'granted') {
    return 'Включено: при появлении новых уведомлений в кабинете (раз в ~45 с) покажется сообщение системы.'
  }
  if (Notification.permission === 'denied') {
    return 'Браузер запретил уведомления. Разрешите их в настройках сайта (значок у адресной строки), затем снова включите переключатель.'
  }
  return 'Ожидается разрешение браузера. Выключите переключатель и включите снова, чтобы появился запрос.'
})

function syncOptInFromStorage() {
  browserPushOptIn.value = getCabinetBrowserPushOptIn()
}

async function load() {
  const { data } = await http.get('/notifications')
  items.value = data
}

async function markRead(id: number) {
  await http.patch(`/notifications/${id}/read`)
  await load()
  await refreshCabinetUnread()
}

async function onBrowserPushToggle(ev: Event) {
  const el = ev.target as HTMLInputElement
  const wantOn = el.checked
  pushHint.value = ''
  pushHintIsError.value = false

  if (!supportsBrowserPush) {
    el.checked = false
    return
  }

  if (!wantOn) {
    setCabinetBrowserPushOptIn(false)
    browserPushOptIn.value = false
    el.checked = false
    pushHint.value = 'Всплывающие уведомления выключены.'
    return
  }

  pushBusy.value = true
  el.checked = pushActive.value
  try {
    const p = await Notification.requestPermission()
    if (p !== 'granted') {
      setCabinetBrowserPushOptIn(false)
      browserPushOptIn.value = false
      el.checked = false
      pushHintIsError.value = true
      pushHint.value =
        p === 'denied'
          ? 'Браузер запретил уведомления. Разрешите их в настройках сайта, затем включите снова.'
          : 'Разрешение не получено — уведомления не будут показываться.'
    } else {
      setCabinetBrowserPushOptIn(true)
      browserPushOptIn.value = true
      el.checked = true
      pushHint.value = 'Готово: уведомления включены.'
    }
  } finally {
    pushBusy.value = false
  }
}

onMounted(() => {
  if (supportsBrowserPush && getCabinetBrowserPushOptIn() && Notification.permission === 'denied') {
    setCabinetBrowserPushOptIn(false)
  }
  syncOptInFromStorage()
  void load()
})
</script>

<template>
  <div class="max-w-2xl space-y-8">
    <h2 class="text-xl font-semibold text-white">Уведомления</h2>

    <div
      v-if="supportsBrowserPush"
      class="rounded-xl border border-white/10 bg-slate-900/40 p-6 shadow-lg"
    >
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0 flex-1">
          <h3 class="text-lg font-semibold text-white">Пуш в браузере</h3>
          <p class="mt-1 text-sm leading-relaxed text-slate-300">
            {{ browserPushStatusLine }}
          </p>
          <p v-if="pushHint" class="mt-2 text-sm" :class="pushHintIsError ? 'text-red-400' : 'text-emerald-400'">
            {{ pushHint }}
          </p>
        </div>
        <div class="flex shrink-0 flex-col items-end gap-2 sm:pl-4">
          <span class="text-xs font-medium uppercase tracking-wide text-slate-500">состояние</span>
          <label
            class="relative inline-flex h-9 w-[4.25rem] cursor-pointer items-center rounded-full bg-slate-700 p-1 transition-colors has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-45 has-[:checked]:bg-emerald-600"
          >
            <input
              type="checkbox"
              role="switch"
              class="peer sr-only"
              :checked="pushActive"
              :disabled="pushBusy"
              :aria-label="pushActive ? 'Выключить пуш в браузере' : 'Включить пуш в браузере'"
              @change="onBrowserPushToggle"
            />
            <span
              class="pointer-events-none h-7 w-7 translate-x-0 rounded-full bg-white shadow transition-transform duration-200 ease-out peer-checked:translate-x-[1.75rem]"
              aria-hidden="true"
            />
          </label>
          <span class="text-xs text-slate-400">{{ pushActive ? 'вкл.' : 'выкл.' }}</span>
        </div>
      </div>
    </div>

    <ul class="space-y-3">
      <li
        v-for="n in items"
        :key="n.id"
        class="rounded-lg border border-white/10 bg-slate-900/40 px-4 py-3"
        :class="n.read_at ? 'opacity-60' : ''"
      >
        <div class="flex justify-between gap-4">
          <div>
            <h3 class="font-medium text-white">{{ n.title }}</h3>
            <p v-if="n.body" class="mt-1 text-sm text-slate-300">{{ n.body }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ n.created_at }}</p>
          </div>
          <button
            v-if="!n.read_at"
            type="button"
            class="shrink-0 text-xs text-indigo-300 hover:underline"
            @click="markRead(n.id)"
          >
            Ок
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>
