<script setup lang="ts">
import type { CalendarOptions, EventInput, EventSourceFuncArg } from '@fullcalendar/core'
import ruLocale from '@fullcalendar/core/locales/ru'
import http from '@/api/http'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction'
import timeGridPlugin from '@fullcalendar/timegrid'
import FullCalendar from '@fullcalendar/vue3'
import { onMounted, ref } from 'vue'

const calendarRef = ref<InstanceType<typeof FullCalendar> | null>(null)
const services = ref<Array<{ id: number; title: string }>>([])

const modal = ref<'create' | 'edit' | null>(null)
const editId = ref<number | null>(null)
const form = ref({
  client_name: '',
  service_id: null as number | null,
  starts_at: '',
  ends_at: '',
  price_kopecks: '' as string | number,
  status: 'confirmed' as 'confirmed' | 'cancelled',
})
const sessionMessages = ref<Array<{ direction: string; text: string; created_at: string }>>([])

async function loadServices() {
  const { data } = await http.get('/services')
  services.value = data
}

async function fetchEvents(
  info: EventSourceFuncArg,
  successCallback: (events: EventInput[]) => void,
  failureCallback: (error: Error) => void,
) {
  try {
    const from = info.start.toISOString().slice(0, 10)
    const to = info.end.toISOString().slice(0, 10)
    const { data } = await http.get('/appointments', { params: { from, to } })
    successCallback(data as EventInput[])
  } catch (e) {
    failureCallback(e instanceof Error ? e : new Error(String(e)))
  }
}

function onDateClick(arg: { dateStr: string }) {
  modal.value = 'create'
  editId.value = null
  form.value = {
    client_name: '',
    service_id: null,
    starts_at: `${arg.dateStr}T10:00`,
    ends_at: `${arg.dateStr}T11:00`,
    price_kopecks: '',
    status: 'confirmed',
  }
  sessionMessages.value = []
}

async function onEventClick(arg: { event: { id: string } }) {
  modal.value = 'edit'
  editId.value = Number(arg.event.id)
  const { data } = await http.get(`/appointments/${editId.value}`)
  form.value = {
    client_name: data.client_name,
    service_id: data.service_id,
    starts_at: data.starts_at.slice(0, 16),
    ends_at: data.ends_at.slice(0, 16),
    price_kopecks: data.price_kopecks ?? '',
    status: data.status,
  }
  sessionMessages.value = data.messages || []
}

async function saveAppointment() {
  const payload = {
    client_name: form.value.client_name,
    service_id: form.value.service_id,
    starts_at: new Date(form.value.starts_at).toISOString(),
    ends_at: new Date(form.value.ends_at).toISOString(),
    price_kopecks: form.value.price_kopecks === '' ? null : Number(form.value.price_kopecks),
    status: form.value.status,
  }
  if (modal.value === 'create') {
    await http.post('/appointments', payload)
  } else if (editId.value) {
    await http.patch(`/appointments/${editId.value}`, payload)
  }
  modal.value = null
  calendarRef.value?.getApi().refetchEvents()
}

function closeModal() {
  modal.value = null
}

const calendarOptions: CalendarOptions = {
  plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
  initialView: 'timeGridWeek',
  headerToolbar: {
    left: 'prev,next today',
    center: 'title',
    right: 'dayGridMonth,timeGridWeek',
  },
  locales: [ruLocale],
  locale: 'ru',
  timeZone: 'Europe/Moscow',
  height: 'auto',
  slotMinTime: '08:00:00',
  slotMaxTime: '22:00:00',
  events: fetchEvents,
  dateClick: onDateClick,
  eventClick: onEventClick,
}

onMounted(loadServices)
</script>

<template>
  <div>
    <h2 class="mb-4 text-xl font-semibold text-white">Календарь</h2>
    <div class="rounded-xl border border-white/10 bg-white p-2 text-slate-900">
      <FullCalendar ref="calendarRef" :options="calendarOptions" />
    </div>

    <div
      v-if="modal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      @click.self="closeModal"
    >
      <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-white/10 bg-slate-900 p-6 shadow-xl">
        <h3 class="text-lg font-medium text-white">
          {{ modal === 'create' ? 'Новая запись' : 'Запись' }}
        </h3>
        <div class="mt-4 space-y-3 text-sm">
          <div>
            <label class="text-xs text-slate-400">Клиент</label>
            <input v-model="form.client_name" class="mt-1 w-full rounded border border-white/10 bg-slate-950 px-2 py-1 text-white" />
          </div>
          <div>
            <label class="text-xs text-slate-400">Услуга</label>
            <select v-model.number="form.service_id" class="mt-1 w-full rounded border border-white/10 bg-slate-950 px-2 py-1 text-white">
              <option :value="null">—</option>
              <option v-for="s in services" :key="s.id" :value="s.id">{{ s.title }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs text-slate-400">Начало</label>
            <input v-model="form.starts_at" type="datetime-local" class="mt-1 w-full rounded border border-white/10 bg-slate-950 px-2 py-1 text-white" />
          </div>
          <div>
            <label class="text-xs text-slate-400">Конец</label>
            <input v-model="form.ends_at" type="datetime-local" class="mt-1 w-full rounded border border-white/10 bg-slate-950 px-2 py-1 text-white" />
          </div>
          <div>
            <label class="text-xs text-slate-400">Цена (коп.)</label>
            <input v-model="form.price_kopecks" type="number" class="mt-1 w-full rounded border border-white/10 bg-slate-950 px-2 py-1 text-white" />
          </div>
          <div v-if="modal === 'edit'">
            <label class="text-xs text-slate-400">Статус</label>
            <select v-model="form.status" class="mt-1 w-full rounded border border-white/10 bg-slate-950 px-2 py-1 text-white">
              <option value="confirmed">Активна</option>
              <option value="cancelled">Отменена</option>
            </select>
          </div>
        </div>
        <div v-if="sessionMessages.length" class="mt-4 border-t border-white/10 pt-4">
          <h4 class="text-xs font-medium uppercase text-slate-500">Сообщения сессии</h4>
          <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs text-slate-300">
            <li v-for="m in sessionMessages" :key="m.created_at + m.text">
              <span class="text-indigo-400">{{ m.direction }}</span>
              : {{ m.text }}
            </li>
          </ul>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="rounded px-3 py-1.5 text-slate-400 hover:bg-white/5" @click="closeModal">Закрыть</button>
          <button type="button" class="rounded bg-indigo-500 px-4 py-1.5 text-white" @click="saveAppointment">Сохранить</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
:deep(.sb-event-cancelled) {
  opacity: 0.4 !important;
  background-color: #cbd5e1 !important;
  border-color: #94a3b8 !important;
  color: #64748b !important;
  font-weight: 400 !important;
  box-shadow: none !important;
}
:deep(.sb-event-cancelled .fc-event-title) {
  font-style: italic;
}
</style>
