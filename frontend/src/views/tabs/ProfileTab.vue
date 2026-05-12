<script setup lang="ts">
import http from '@/api/http'
import axios from 'axios'
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const name = ref('')
const sex = ref('')
const services_description = ref('')
const msg = ref('')
const msgIsError = ref(false)

const reloadCabinetUser = inject<(() => Promise<void>) | undefined>('reloadCabinetUser', undefined)

const botPaused = ref(true)
const subscriptionActive = ref(false)
const botToggleLoading = ref(false)
const botToggleMsg = ref('')
const botToggleMsgIsError = ref(false)

const botSwitchDisabled = computed(
  () => botToggleLoading.value || (botPaused.value && !subscriptionActive.value),
)

const botStatusLine = computed(() => {
  if (!botPaused.value) {
    return 'Бот отвечает клиентам в сообщениях сообщества.'
  }
  if (!subscriptionActive.value) {
    return 'Чтобы снова включить бота, нужен активный срок подписки. Продлите доступ на странице «Оплата».'
  }
  return 'Бот не отвечает. Включите переключатель, когда будете готовы.'
})

const vkAccessToken = ref('')
const vkGroupId = ref('')
const vkConfirmationCode = ref('')
const vkCallbackSecret = ref('')
const vkStatus = ref<'none' | 'pending_confirmation' | 'attached'>('none')
const vkWebhookUrl = ref('')
const vkGroupIdDisplay = ref('')
const vkMsg = ref('')
const vkMsgIsError = ref(false)
const vkFieldErrors = ref<Record<string, string>>({})
const vkSaving = ref(false)
const vkDetaching = ref(false)

let vkPollTimer: ReturnType<typeof setInterval> | null = null

function clearVkPoll(): void {
  if (vkPollTimer !== null) {
    clearInterval(vkPollTimer)
    vkPollTimer = null
  }
}

function scheduleVkPoll(): void {
  clearVkPoll()
  if (vkStatus.value !== 'pending_confirmation') {
    return
  }
  vkPollTimer = setInterval(() => {
    void loadVkGroup()
  }, 4000)
}

watch(vkStatus, (s) => {
  if (s === 'pending_confirmation') {
    scheduleVkPoll()
  } else {
    clearVkPoll()
  }
})

async function loadVkGroup(): Promise<void> {
  const { data } = await http.get<{
    status: 'none' | 'pending_confirmation' | 'attached'
    group_id: string | null
    webhook_url: string
  }>('/vk/group')
  vkStatus.value = data.status
  vkWebhookUrl.value = data.webhook_url
  vkGroupIdDisplay.value = data.group_id ?? ''
  if (data.status === 'none') {
    vkGroupId.value = ''
  }
}

async function load(): Promise<void> {
  const u = await http.get<{
    name: string
    sex: string | null
    services_description: string | null
    bot_paused: boolean
    subscription_active: boolean
  }>('/user')
  name.value = u.data.name
  sex.value = u.data.sex || ''
  services_description.value = u.data.services_description || ''
  botPaused.value = u.data.bot_paused
  subscriptionActive.value = u.data.subscription_active
  await loadVkGroup()
}

async function onBotSwitchChange(ev: Event): Promise<void> {
  const el = ev.target as HTMLInputElement
  const wantEnabled = el.checked
  const nextPaused = !wantEnabled
  if (wantEnabled && !subscriptionActive.value) {
    el.checked = false
    return
  }
  botToggleMsg.value = ''
  botToggleMsgIsError.value = false
  botToggleLoading.value = true
  const previousPaused = botPaused.value
  botPaused.value = nextPaused
  el.checked = !nextPaused
  try {
    const { data } = await http.patch<{
      user: { bot_paused: boolean; subscription_active: boolean }
    }>('/profile', { bot_paused: nextPaused })
    botPaused.value = data.user.bot_paused
    subscriptionActive.value = data.user.subscription_active
    el.checked = !data.user.bot_paused
    await reloadCabinetUser?.()
  } catch (e: unknown) {
    botPaused.value = previousPaused
    el.checked = !previousPaused
    botToggleMsgIsError.value = true
    botToggleMsg.value = axios.isAxiosError(e)
      ? String((e.response?.data as { message?: string })?.message || e.message || 'Не удалось изменить')
      : 'Не удалось изменить'
  } finally {
    botToggleLoading.value = false
  }
}

async function saveProfile(): Promise<void> {
  msg.value = ''
  msgIsError.value = false
  try {
    await http.patch('/profile', {
      name: name.value,
      sex: sex.value || null,
      services_description: services_description.value,
    })
    msg.value = 'Сохранено'
  } catch (e: unknown) {
    msgIsError.value = true
    msg.value = axios.isAxiosError(e)
      ? String((e.response?.data as { message?: string })?.message || e.message || 'Ошибка сохранения')
      : 'Ошибка сохранения'
  }
}

const vkFieldLabels: Record<string, string> = {
  access_token: 'а) API-ключ',
  group_id: 'б) ID сообщества',
  confirmation_code: 'в) Строка подтверждения',
  callback_secret: 'г) Секретный ключ',
}

async function saveVkGroup(): Promise<void> {
  vkMsg.value = ''
  vkMsgIsError.value = false
  vkFieldErrors.value = {}
  vkSaving.value = true
  try {
    await http.post('/vk/group', {
      group_id: vkGroupId.value.trim(),
      access_token: vkAccessToken.value.trim(),
      callback_secret: vkCallbackSecret.value.trim(),
      confirmation_code: vkConfirmationCode.value.trim(),
    })
    vkAccessToken.value = ''
    vkGroupId.value = ''
    vkConfirmationCode.value = ''
    vkCallbackSecret.value = ''
    await loadVkGroup()
    vkMsg.value =
      'Данные сохранены. Дождитесь окончания проверки во ВКонтакте — статус обновится здесь автоматически.'
  } catch (e: unknown) {
    vkMsgIsError.value = true
    if (axios.isAxiosError(e) && e.response?.status === 422) {
      const body = e.response.data as { errors?: Record<string, string[]>; message?: string }
      const next: Record<string, string> = {}
      if (body.errors) {
        for (const [key, msgs] of Object.entries(body.errors)) {
          const label = vkFieldLabels[key] ?? key
          next[key] = `${label}: ${msgs.join(' ')}`
        }
      }
      vkFieldErrors.value = next
      const flat = body.errors ? Object.values(body.errors).flat().join(' ') : ''
      vkMsg.value = flat || body.message || 'Проверьте поля формы.'
    } else if (axios.isAxiosError(e) && e.response?.status === 419) {
      vkMsg.value = 'Сессия CSRF устарела — обновите страницу и попробуйте снова.'
    } else if (axios.isAxiosError(e)) {
      vkMsg.value = String((e.response?.data as { message?: string })?.message || e.message || 'Не удалось сохранить')
    } else {
      vkMsg.value = 'Не удалось сохранить'
    }
  } finally {
    vkSaving.value = false
  }
}

async function detachVkGroup(): Promise<void> {
  if (!window.confirm('Открепить сообщество? Настройки бота во ВКонтакте нужно будет сделать заново.')) {
    return
  }
  vkMsg.value = ''
  vkMsgIsError.value = false
  vkDetaching.value = true
  try {
    await http.post('/vk/group/detach')
    await loadVkGroup()
    vkMsg.value = 'Сообщество откреплено. Можно подключить другое.'
  } catch (e: unknown) {
    vkMsgIsError.value = true
    vkMsg.value = axios.isAxiosError(e)
      ? String((e.response?.data as { message?: string })?.message || e.message || 'Не удалось открепить')
      : 'Не удалось открепить'
  } finally {
    vkDetaching.value = false
  }
}

async function copyWebhookUrl(): Promise<void> {
  try {
    await navigator.clipboard.writeText(vkWebhookUrl.value)
    vkMsg.value = 'Адрес сервера скопирован в буфер обмена.'
    vkMsgIsError.value = false
  } catch {
    vkMsg.value = 'Не удалось скопировать — выделите адрес вручную.'
    vkMsgIsError.value = true
  }
}

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  clearVkPoll()
})
</script>

<template>
  <div class="max-w-2xl space-y-8">
    <h2 class="text-xl font-semibold text-white">Профиль</h2>
    <p v-if="msg" class="text-sm" :class="msgIsError ? 'text-red-400' : 'text-emerald-400'">
      {{ msg }}
    </p>

    <div
      class="rounded-xl border p-6 shadow-lg"
      :class="
        botPaused
          ? 'border-white/10 bg-slate-900/40'
          : 'border-emerald-500/35 bg-gradient-to-br from-emerald-950/30 to-slate-900/40'
      "
    >
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0 flex-1">
          <h3 class="text-lg font-semibold text-white">Бот в сообщениях</h3>
          <p class="mt-1 text-sm leading-relaxed text-slate-300">
            {{ botStatusLine }}
          </p>
          <p v-if="botToggleMsg" class="mt-2 text-sm" :class="botToggleMsgIsError ? 'text-red-400' : 'text-emerald-400'">
            {{ botToggleMsg }}
          </p>
        </div>
        <div class="flex shrink-0 flex-col items-end gap-2 sm:pl-4">
          <span class="text-xs font-medium uppercase tracking-wide text-slate-500">состояние</span>
          <label class="relative inline-flex h-9 w-[4.25rem] cursor-pointer items-center rounded-full bg-slate-700 p-1 transition-colors has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-45 has-[:checked]:bg-emerald-600">
            <input
              type="checkbox"
              role="switch"
              class="peer sr-only"
              :checked="!botPaused"
              :disabled="botSwitchDisabled"
              :aria-label="botPaused ? 'Включить бота' : 'Выключить бота'"
              @change="onBotSwitchChange"
            />
            <span
              class="pointer-events-none h-7 w-7 translate-x-0 rounded-full bg-white shadow transition-transform duration-200 ease-out peer-checked:translate-x-[1.75rem]"
              aria-hidden="true"
            />
          </label>
          <span class="text-xs text-slate-400">{{ botPaused ? 'выкл.' : 'вкл.' }}</span>
        </div>
      </div>
    </div>

    <form class="space-y-4 rounded-xl border border-white/10 bg-slate-900/40 p-6" @submit.prevent="saveProfile">
      <div>
        <label class="text-xs text-slate-400">Имя</label>
        <input v-model="name" class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white" />
      </div>
      <div>
        <label class="text-xs text-slate-400">Пол</label>
        <select v-model="sex" class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white">
          <option value="">—</option>
          <option value="female">Женский</option>
          <option value="male">Мужской</option>
          <option value="other">Другое</option>
        </select>
      </div>
      <button type="submit" class="rounded-lg bg-indigo-500 px-4 py-2 text-sm text-white">Сохранить профиль</button>
    </form>

    <section class="space-y-6" aria-labelledby="vk-community-heading">
      <h3 id="vk-community-heading" class="text-lg font-semibold text-white">Сообщество ВКонтакте для бота</h3>
      <p class="text-sm leading-relaxed text-slate-300">
        Здесь вы подключаете <strong class="font-medium text-white">группу</strong>, от имени которой бот будет
        отвечать в личных сообщениях. Сначала прочитайте пошаговую подсказку, затем заполните форму внизу — поля
        названы так же, как в инструкции (а, б, в, г).
      </p>

      <!-- Подсказка -->
      <div
        class="rounded-xl border border-amber-500/25 bg-amber-950/20 p-5 shadow-inner shadow-black/20"
        role="region"
        aria-label="Инструкция по подключению сообщества ВКонтакте"
      >
        <h4 class="flex items-center gap-2 text-base font-semibold text-amber-100">
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-500/20 text-sm text-amber-200" aria-hidden="true">?</span>
          Как подключить сообщество
        </h4>
        <p class="mt-2 text-xs text-amber-200/80">
          Откройте ваше сообщество ВКонтакте и действуйте по шагам. Ничего не спешите: можно вернуться к любому шагу.
        </p>
        <ol class="mt-4 list-none space-y-3 text-sm text-slate-200">
          <li class="flex gap-3">
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">1</span>
            <span><strong class="text-white">Управление</strong> → <strong class="text-white">Сообщения</strong> → <strong class="text-white">Настройки для бота</strong> → включите бота.</span>
          </li>
          <li class="flex gap-3">
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">2</span>
            <span><strong class="text-white">Управление</strong> → <strong class="text-white">Дополнительно</strong> → <strong class="text-white">Работа с API</strong> → нажмите <strong class="text-white">«Создать ключ»</strong>.</span>
          </li>
          <li class="flex gap-3">
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">3</span>
            <span>
              В настройках ключа включите права:
              <span class="mt-1 block text-slate-300">«Разрешить приложению доступ к сообщениям сообщества» и «Разрешить приложению доступ к стене сообщества».</span>
            </span>
          </li>
          <li class="flex gap-3">
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">4</span>
            <span>Скопируйте созданный ключ и вставьте его в поле <strong class="text-amber-100">«а) API-ключ»</strong> в форме ниже.</span>
          </li>
          <li class="flex gap-3">
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">5</span>
            <span>Снова <strong class="text-white">Управление</strong> → <strong class="text-white">Дополнительно</strong> → <strong class="text-white">Работа с API</strong> → откройте вкладку <strong class="text-white">«Callback API»</strong>.</span>
          </li>
          <li class="flex gap-3">
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">6</span>
            <div class="min-w-0 flex-1 space-y-2">
              <p>В поле <strong class="text-white">«Адрес сервера»</strong> вставьте <strong class="text-white">точно</strong> этот адрес (скопируйте кнопкой):</p>
              <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <code class="block max-w-full overflow-x-auto rounded-lg border border-white/15 bg-slate-950 px-3 py-2 text-xs text-emerald-300">{{ vkWebhookUrl || '…' }}</code>
                <button
                  type="button"
                  class="shrink-0 rounded-lg border border-white/20 px-3 py-2 text-xs text-white hover:bg-white/5"
                  @click="copyWebhookUrl"
                >
                  Скопировать адрес
                </button>
              </div>
            </div>
          </li>
          <li class="flex gap-3">
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">7</span>
            <span>
              Найдите строку вида <code class="rounded bg-black/30 px-1 text-emerald-300/90">{ "type": "confirmation", "group_id": … }</code>
              — число после <code class="rounded bg-black/30 px-1">group_id</code> вставьте в поле <strong class="text-amber-100">«б) ID сообщества»</strong>.
            </span>
          </li>
          <li class="flex gap-3">
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">8</span>
            <span>На той же странице найдите текст <strong class="text-white">«Строка, которую должен вернуть сервер»</strong> и вставьте показанное значение в поле <strong class="text-amber-100">«в) Строка подтверждения»</strong>.</span>
          </li>
          <li class="flex gap-3">
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">9</span>
            <span>
              В форме <strong class="text-white">«Секретный ключ»</strong> на сайте ВКонтакте придумайте любой текст, нажмите
              <strong class="text-white">«Сохранить»</strong>. Тот же текст вставьте в поле <strong class="text-amber-100">«г) Секретный ключ»</strong>.
            </span>
          </li>
          <li class="flex gap-3">
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">10</span>
            <span>
              Нажмите зелёную кнопку <strong class="text-white">«Сохранить»</strong> в форме на этой странице (ниже). Затем во ВКонтакте на странице Callback API нажмите
              <strong class="text-white">«Подтвердить»</strong>. Когда ВКонтакте примет сервер, здесь появится сообщение об успехе — обычно это несколько секунд.
            </span>
          </li>
        </ol>
      </div>

      <!-- Сообщения блока ВК -->
      <p v-if="vkMsg" class="text-sm" :class="vkMsgIsError ? 'text-red-400' : 'text-emerald-400'">
        {{ vkMsg }}
      </p>

      <!-- Ожидание подтверждения -->
      <div
        v-if="vkStatus === 'pending_confirmation'"
        class="rounded-xl border border-sky-500/30 bg-sky-950/25 p-6"
        role="status"
        aria-live="polite"
      >
        <p class="text-base font-medium text-sky-100">Сообщество в процессе подтверждения</p>
        <p class="mt-2 text-sm leading-relaxed text-sky-200/90">
          Мы уже сохранили ваши данные. Сейчас нужно нажать <strong class="text-white">«Подтвердить»</strong> во ВКонтакте на странице Callback API, если вы ещё не сделали это.
          Эта страница сама проверяет статус каждые несколько секунд — обновлять вручную не обязательно.
        </p>
        <p class="mt-2 text-xs text-slate-400">
          Если статус не меняется долго, проверьте, что во ВКонтакте в поле «Адрес сервера» указан <strong class="text-slate-200">тот же адрес</strong>, что в шаге 6 инструкции выше (без опечаток, с https).
        </p>
        <p v-if="vkGroupIdDisplay" class="mt-3 text-xs text-slate-400">ID сообщества: {{ vkGroupIdDisplay }}</p>
        <div class="mt-4 flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded-lg border border-red-400/40 px-4 py-2 text-sm text-red-200 hover:bg-red-950/40 disabled:opacity-50"
            :disabled="vkDetaching"
            @click="detachVkGroup"
          >
            Открепить сообщество
          </button>
        </div>
      </div>

      <!-- Подключено -->
      <div
        v-else-if="vkStatus === 'attached'"
        class="rounded-xl border border-emerald-500/30 bg-emerald-950/20 p-6"
        role="status"
      >
        <p class="text-base font-medium text-emerald-100">Сообщество прикреплено</p>
        <p class="mt-2 text-sm text-emerald-200/90">
          Бот может работать с этой группой. ID сообщества:
          <strong class="text-white">{{ vkGroupIdDisplay || '—' }}</strong>
        </p>
        <div class="mt-4">
          <button
            type="button"
            class="rounded-lg border border-red-400/40 px-4 py-2 text-sm text-red-200 hover:bg-red-950/40 disabled:opacity-50"
            :disabled="vkDetaching"
            @click="detachVkGroup"
          >
            Открепить сообщество
          </button>
        </div>
      </div>

      <!-- Форма -->
      <div v-else class="rounded-xl border border-white/10 bg-slate-900/40 p-6">
        <h4 class="text-sm font-medium text-white">Данные из ВКонтакте</h4>
        <p class="mt-1 text-xs text-slate-500">
          Поля соответствуют буквам в подсказке выше. После сохранения поля скрываются до завершения проверки или открепления.
        </p>
        <form class="mt-5 space-y-4" @submit.prevent="saveVkGroup">
          <div>
            <label class="block text-sm font-medium text-slate-200" for="vk-a">а) API-ключ</label>
            <p class="mt-0.5 text-xs text-slate-500">Ключ доступа сообщества из раздела «Работа с API».</p>
            <input
              id="vk-a"
              v-model="vkAccessToken"
              type="password"
              autocomplete="off"
              placeholder="Вставьте ключ целиком"
              class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white placeholder:text-slate-600"
            />
            <p v-if="vkFieldErrors.access_token" class="mt-1 text-xs text-red-400">{{ vkFieldErrors.access_token }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-200" for="vk-b">б) ID сообщества (group_id)</label>
            <p class="mt-0.5 text-xs text-slate-500">Число из примера с confirmation на странице Callback API.</p>
            <input
              id="vk-b"
              v-model="vkGroupId"
              inputmode="numeric"
              placeholder="Например: 123456789"
              class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white placeholder:text-slate-600"
            />
            <p v-if="vkFieldErrors.group_id" class="mt-1 text-xs text-red-400">{{ vkFieldErrors.group_id }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-200" for="vk-v">в) Строка подтверждения</label>
            <p class="mt-0.5 text-xs text-slate-500">То, что ВКонтакте просит «вернуть серверу».</p>
            <input
              id="vk-v"
              v-model="vkConfirmationCode"
              autocomplete="off"
              placeholder="Строка из настроек Callback API"
              class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white placeholder:text-slate-600"
            />
            <p v-if="vkFieldErrors.confirmation_code" class="mt-1 text-xs text-red-400">{{ vkFieldErrors.confirmation_code }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-200" for="vk-g">г) Секретный ключ</label>
            <p class="mt-0.5 text-xs text-slate-500">Тот же текст, что вы ввели в поле «Секретный ключ» на странице Callback API во ВКонтакте.</p>
            <input
              id="vk-g"
              v-model="vkCallbackSecret"
              type="password"
              autocomplete="off"
              placeholder="Секрет из Callback API"
              class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white placeholder:text-slate-600"
            />
            <p v-if="vkFieldErrors.callback_secret" class="mt-1 text-xs text-red-400">{{ vkFieldErrors.callback_secret }}</p>
          </div>
          <button
            type="submit"
            class="rounded-lg bg-indigo-500 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-400 disabled:opacity-50"
            :disabled="vkSaving"
          >
            {{ vkSaving ? 'Сохранение…' : 'Сохранить' }}
          </button>
        </form>
      </div>
    </section>
  </div>
</template>
