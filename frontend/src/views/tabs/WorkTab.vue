<script setup lang="ts">
import http from '@/api/http'
import axios from 'axios'
import { computed, onMounted, reactive, ref, watch } from 'vue'

type MasterRow = { id: number; name: string }

const description = ref('')
const masters = ref<MasterRow[]>([])
const selectedMasterId = ref<number | null>(null)
const businessMode = ref<'solo' | 'salon'>('solo')

const services = ref<
  Array<{
    id: number
    title: string
    description: string | null
    price_kopecks: number
    duration_minutes: number
    is_active: boolean
  }>
>([])
const slots = reactive(
  [0, 1, 2, 3, 4, 5, 6].map((weekday) => ({
    weekday,
    opens_at: '10:00',
    closes_at: '19:00',
    enabled: weekday >= 1 && weekday <= 5,
  })),
)

const newSvc = reactive({
  title: '',
  description: '',
  price_rub: 1000,
  duration_minutes: 60,
})

const showMasterTabs = computed(() => businessMode.value === 'salon' && masters.value.length > 1)
const hoursSaveMessage = ref<{ type: 'ok' | 'error'; text: string } | null>(null)

function formatTimeForInput(value: string): string {
  const match = String(value).match(/(\d{2}):(\d{2})/)
  return match ? `${match[1]}:${match[2]}` : '10:00'
}

function formatTimeForApi(value: string): string {
  return formatTimeForInput(value)
}

function resetSlotsDefaults() {
  for (const slot of slots) {
    slot.opens_at = '10:00'
    slot.closes_at = '19:00'
    slot.enabled = false
  }
}

async function loadMasterData() {
  if (selectedMasterId.value == null) return
  const [s, wh] = await Promise.all([
    http.get('/services', { params: { master_id: selectedMasterId.value } }),
    http.get('/working-hours', { params: { master_id: selectedMasterId.value } }),
  ])
  services.value = s.data
  resetSlotsDefaults()
  for (const row of wh.data as Array<{ weekday: number; opens_at: string; closes_at: string }>) {
    const weekday = Number(row.weekday)
    const slot = slots.find((x) => x.weekday === weekday)
    if (slot) {
      slot.opens_at = formatTimeForInput(row.opens_at)
      slot.closes_at = formatTimeForInput(row.closes_at)
      slot.enabled = true
    }
  }
}

async function load() {
  const u = await http.get<{
    services_description: string | null
    business_mode: string
    masters: MasterRow[]
  }>('/user')
  description.value = u.data.services_description || ''
  businessMode.value = u.data.business_mode === 'salon' ? 'salon' : 'solo'
  masters.value = u.data.masters ?? []
  if (!masters.value.length) {
    const m = await http.get<MasterRow[]>('/masters')
    masters.value = m.data
  }
  if (selectedMasterId.value == null && masters.value[0]) {
    selectedMasterId.value = masters.value[0].id
  }
  await loadMasterData()
}

watch(selectedMasterId, () => {
  void loadMasterData()
})

async function saveDescription() {
  await http.patch('/profile', { services_description: description.value })
}

async function addService() {
  if (selectedMasterId.value == null) return
  await http.post('/services', {
    master_id: selectedMasterId.value,
    title: newSvc.title,
    description: newSvc.description || null,
    price_kopecks: Math.round(newSvc.price_rub * 100),
    duration_minutes: newSvc.duration_minutes,
    is_active: true,
  })
  newSvc.title = ''
  newSvc.description = ''
  await loadMasterData()
}

async function removeService(id: number) {
  await http.delete(`/services/${id}`)
  await loadMasterData()
}

async function saveHours() {
  hoursSaveMessage.value = null
  if (selectedMasterId.value == null) {
    hoursSaveMessage.value = { type: 'error', text: 'Мастер не выбран.' }
    return
  }
  const payload = slots
    .filter((s) => s.enabled)
    .map((s) => ({
      weekday: s.weekday,
      opens_at: formatTimeForApi(s.opens_at),
      closes_at: formatTimeForApi(s.closes_at),
    }))
  try {
    await http.put('/working-hours', { master_id: selectedMasterId.value, slots: payload })
    await loadMasterData()
    hoursSaveMessage.value = { type: 'ok', text: 'График сохранён.' }
  } catch (e) {
    if (axios.isAxiosError(e) && e.response?.status === 422) {
      const body = e.response.data as { errors?: Record<string, string[]>; message?: string }
      const flat = body.errors ? Object.values(body.errors).flat().join(' ') : ''
      hoursSaveMessage.value = {
        type: 'error',
        text: flat || body.message || 'Не удалось сохранить график.',
      }
    } else {
      hoursSaveMessage.value = { type: 'error', text: 'Не удалось сохранить график.' }
    }
  }
}

const dayNames = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб']

onMounted(load)
</script>

<template>
  <div class="space-y-10">
    <h2 class="text-xl font-semibold text-white">Данные о работе</h2>

    <section class="rounded-xl border border-white/10 bg-slate-900/40 p-6">
      <h3 class="font-medium text-white">Свободное описание услуг</h3>
      <p class="mt-1 text-xs text-slate-500">Общее описание для бота и клиентов (для всего салона).</p>
      <textarea
        v-model="description"
        rows="6"
        class="mt-3 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white"
        placeholder="Цены, акции, что важно знать клиенту…"
      />
      <button type="button" class="mt-3 rounded-lg bg-indigo-500 px-4 py-2 text-sm text-white" @click="saveDescription">
        Сохранить описание
      </button>
    </section>

    <div v-if="showMasterTabs" class="flex flex-wrap gap-2 border-b border-white/10 pb-2">
      <button
        v-for="m in masters"
        :key="m.id"
        type="button"
        class="rounded-lg px-4 py-2 text-sm transition"
        :class="
          selectedMasterId === m.id
            ? 'bg-indigo-500 text-white'
            : 'border border-white/15 text-slate-300 hover:bg-white/5'
        "
        @click="selectedMasterId = m.id"
      >
        {{ m.name }}
      </button>
    </div>
    <p v-else-if="masters[0]" class="text-sm text-slate-400">
      Мастер: <span class="text-white">{{ masters.find((m) => m.id === selectedMasterId)?.name ?? masters[0].name }}</span>
    </p>

    <section class="rounded-xl border border-white/10 bg-slate-900/40 p-6">
      <h3 class="font-medium text-white">Услуги</h3>
      <div class="mt-4 flex flex-wrap gap-2 border-b border-white/10 pb-4">
        <input
          v-model="newSvc.title"
          placeholder="Название"
          class="rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-sm text-white"
        />
        <input
          v-model.number="newSvc.price_rub"
          type="number"
          class="w-24 rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-sm text-white"
        />
        <input
          v-model.number="newSvc.duration_minutes"
          type="number"
          class="w-20 rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-sm text-white"
        />
        <button type="button" class="rounded-lg bg-emerald-600 px-3 py-1 text-sm text-white" @click="addService">
          Добавить
        </button>
      </div>
      <table class="mt-4 w-full text-left text-sm text-slate-300">
        <thead>
          <tr class="text-xs uppercase text-slate-500">
            <th class="py-2">Название</th>
            <th>Цена ₽</th>
            <th>Мин</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in services" :key="s.id" class="border-t border-white/5">
            <td class="py-2">{{ s.title }}</td>
            <td>{{ (s.price_kopecks / 100).toFixed(0) }}</td>
            <td>{{ s.duration_minutes }}</td>
            <td>
              <button type="button" class="text-rose-400 hover:underline" @click="removeService(s.id)">Удалить</button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="rounded-xl border border-white/10 bg-slate-900/40 p-6">
      <h3 class="font-medium text-white">Рабочие часы по дням недели</h3>
      <div class="mt-4 space-y-2">
        <div v-for="s in slots" :key="s.weekday" class="flex flex-wrap items-center gap-3 text-sm">
          <label class="flex w-28 items-center gap-2 text-slate-300">
            <input v-model="s.enabled" type="checkbox" class="rounded border-white/20" />
            {{ dayNames[s.weekday] }}
          </label>
          <input v-model="s.opens_at" type="time" class="rounded border border-white/10 bg-slate-950 px-2 py-1 text-white" />
          <span class="text-slate-500">—</span>
          <input v-model="s.closes_at" type="time" class="rounded border border-white/10 bg-slate-950 px-2 py-1 text-white" />
        </div>
      </div>
      <button type="button" class="mt-4 rounded-lg bg-indigo-500 px-4 py-2 text-sm text-white" @click="saveHours">
        Сохранить график
      </button>
      <p
        v-if="hoursSaveMessage"
        role="status"
        class="mt-2 text-sm"
        :class="hoursSaveMessage.type === 'ok' ? 'text-emerald-400' : 'text-rose-400'"
      >
        {{ hoursSaveMessage.text }}
      </p>
    </section>
  </div>
</template>
