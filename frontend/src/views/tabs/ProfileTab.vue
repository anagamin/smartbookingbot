<script setup lang="ts">
import http from '@/api/http'
import { onMounted, ref } from 'vue'

const name = ref('')
const sex = ref('')
const services_description = ref('')
const socials = ref<Array<{ id: number; provider: string }>>([])
const vkGroupId = ref('')
const vkToken = ref('')
const vkSecret = ref('')
const vkConfirm = ref('')
const msg = ref('')

async function load() {
  const u = await http.get('/user')
  name.value = u.data.name
  sex.value = u.data.sex || ''
  services_description.value = u.data.services_description || ''
  const s = await http.get('/social-accounts')
  socials.value = s.data
  const g = await http.get('/vk/group')
  if (g.data.connected) {
    vkGroupId.value = g.data.group_id || ''
  }
}

async function saveProfile() {
  msg.value = ''
  await http.patch('/profile', {
    name: name.value,
    sex: sex.value || null,
    services_description: services_description.value,
  })
  msg.value = 'Сохранено'
}

async function saveVkGroup() {
  msg.value = ''
  await http.post('/vk/group', {
    group_id: vkGroupId.value,
    access_token: vkToken.value,
    callback_secret: vkSecret.value,
    confirmation_code: vkConfirm.value,
  })
  msg.value = 'VK-сообщество сохранено. Callback URL: ' + window.location.origin + '/api/webhooks/vk'
  vkToken.value = ''
}

async function startVkLink() {
  const { data } = await http.get('/oauth/vk/link/start')
  window.location.href = data.url
}

async function startYandexLink() {
  const { data } = await http.get('/oauth/yandex/link/start')
  window.location.href = data.url
}

onMounted(load)
</script>

<template>
  <div class="max-w-2xl space-y-8">
    <h2 class="text-xl font-semibold text-white">Профиль</h2>
    <p v-if="msg" class="text-sm text-emerald-400">
      {{ msg }}
    </p>
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

    <div class="rounded-xl border border-white/10 bg-slate-900/40 p-6">
      <h3 class="font-medium text-white">Соцсети</h3>
      <ul class="mt-2 text-sm text-slate-400">
        <li v-for="s in socials" :key="s.id">{{ s.provider }} — привязан</li>
        <li v-if="!socials.length">Пока нет привязанных аккаунтов</li>
      </ul>
      <div class="mt-4 flex flex-wrap gap-2">
        <button type="button" class="rounded-lg border border-white/15 px-3 py-2 text-sm" @click="startVkLink">
          Привязать VK ID
        </button>
        <button type="button" class="rounded-lg border border-white/15 px-3 py-2 text-sm" @click="startYandexLink">
          Привязать Яндекс
        </button>
      </div>
    </div>

    <div class="rounded-xl border border-white/10 bg-slate-900/40 p-6">
      <h3 class="font-medium text-white">VK: сообщество для бота</h3>
      <p class="mt-1 text-xs text-slate-500">
        Укажите ID группы, ключ доступа сообщества, секрет Callback и строку подтверждения из настроек VK.
      </p>
      <form class="mt-4 space-y-3" @submit.prevent="saveVkGroup">
        <input
          v-model="vkGroupId"
          placeholder="group_id"
          class="w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white"
        />
        <input
          v-model="vkToken"
          placeholder="access_token сообщества"
          class="w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white"
        />
        <input
          v-model="vkSecret"
          placeholder="callback secret"
          class="w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white"
        />
        <input
          v-model="vkConfirm"
          placeholder="строка подтверждения"
          class="w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white"
        />
        <button type="submit" class="rounded-lg bg-indigo-500 px-4 py-2 text-sm text-white">Сохранить</button>
      </form>
    </div>
  </div>
</template>
