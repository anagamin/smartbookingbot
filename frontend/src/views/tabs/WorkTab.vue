<script setup lang="ts">
import http from '@/api/http'
import { onMounted, reactive, ref } from 'vue'

const description = ref('')
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

async function load() {
  const u = await http.get('/user')
  description.value = u.data.services_description || ''
  const s = await http.get('/services')
  services.value = s.data
  const wh = await http.get('/working-hours')
  for (const row of wh.data as Array<{ weekday: number; opens_at: string; closes_at: string }>) {
    const slot = slots.find((x) => x.weekday === row.weekday)
    if (slot) {
      slot.opens_at = String(row.opens_at).slice(0, 5)
      slot.closes_at = String(row.closes_at).slice(0, 5)
      slot.enabled = true
    }
  }
}

async function saveDescription() {
  await http.patch('/profile', { services_description: description.value })
}

async function addService() {
  await http.post('/services', {
    title: newSvc.title,
    description: newSvc.description || null,
    price_kopecks: Math.round(newSvc.price_rub * 100),
    duration_minutes: newSvc.duration_minutes,
    is_active: true,
  })
  newSvc.title = ''
  newSvc.description = ''
  await load()
}

async function removeService(id: number) {
  await http.delete(`/services/${id}`)
  await load()
}

async function saveHours() {
  const payload = slots
    .filter((s) => s.enabled)
    .map((s) => ({
      weekday: s.weekday,
      opens_at: s.opens_at,
      closes_at: s.closes_at,
    }))
  await http.put('/working-hours', { slots: payload })
}

const dayNames = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб']

onMounted(load)
</script>

<template>
  <div class="space-y-10">
    <h2 class="text-xl font-semibold text-white">Данные о работе</h2>

    <section class="rounded-xl border border-white/10 bg-slate-900/40 p-6">
      <h3 class="font-medium text-white">Свободное описание услуг</h3>
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
          <input
            v-model="s.opens_at"
            type="time"
            class="rounded border border-white/10 bg-slate-950 px-2 py-1 text-white"
          />
          <span class="text-slate-500">—</span>
          <input
            v-model="s.closes_at"
            type="time"
            class="rounded border border-white/10 bg-slate-950 px-2 py-1 text-white"
          />
        </div>
      </div>
      <button type="button" class="mt-4 rounded-lg bg-indigo-500 px-4 py-2 text-sm text-white" @click="saveHours">
        Сохранить график
      </button>
    </section>
  </div>
</template>
