<script setup lang="ts">
import type { CalendarOptions, EventInput, EventSourceFuncArg } from '@fullcalendar/core'
import ruLocale from '@fullcalendar/core/locales/ru'
import http from '@/api/http'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction'
import timeGridPlugin from '@fullcalendar/timegrid'
import FullCalendar from '@fullcalendar/vue3'
import Multiselect from '@vueform/multiselect'
import { onMounted, ref } from 'vue'

/**
 * Must match `timeZone` in calendarOptions — API stores datetimes in app timezone (Europe/Moscow).
 * Without `@fullcalendar/moment-timezone` (or Luxon TZ), named zones use FullCalendar "UTC-coercion":
 * the clicked slot's wall time is read via UTC getters on `Date`, not via `toISOString()` + IANA format
 * (that would treat the coerced clock as a real UTC instant and shift by offset — e.g. 12:00 → 15:00 MSK).
 */
const CALENDAR_TIMEZONE = 'Europe/Moscow'

const calendarRef = ref<InstanceType<typeof FullCalendar> | null>(null)
const services = ref<Array<{ id: number; title: string; duration_minutes: number }>>([])

const modal = ref<'create' | 'edit' | null>(null)
const editId = ref<number | null>(null)
const form = ref({
  client_name: '',
  service_ids: [] as number[],
  starts_at: '',
  ends_at: '',
  price_rub: '' as string | number,
  status: 'confirmed' as 'confirmed' | 'cancelled',
})
const sessionMessages = ref<Array<{ direction: string; text: string; created_at: string }>>([])

function appointmentServiceIdsFromDetail(data: {
  service_id?: number | null
  service?: { id: number } | null
  extra_service_ids?: Array<number | string> | null
}): number[] {
  const ids: number[] = []
  const main = data.service_id ?? data.service?.id ?? null
  if (main != null) ids.push(Number(main))
  for (const raw of data.extra_service_ids ?? []) {
    const id = Number(raw)
    if (!Number.isFinite(id) || ids.includes(id)) continue
    ids.push(id)
  }
  return ids
}

/** Naive `datetime-local` string from a FullCalendar API `Date` under named-TZ UTC-coercion (see CALENDAR_TIMEZONE). */
function fullCalendarCoercedDateToDatetimeLocal(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`
}

function isoToDatetimeLocalInTimeZone(iso: string, timeZone: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) {
    return iso.length >= 16 ? iso.slice(0, 16) : iso
  }
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(d)
  const v = (t: Intl.DateTimeFormatPartTypes) => parts.find((p) => p.type === t)?.value ?? ''
  return `${v('year')}-${v('month')}-${v('day')}T${v('hour')}:${v('minute')}`
}

/** `datetime-local` value (no TZ) → `Y-m-d H:i:s` for Laravel `APP_TIMEZONE`. */
function datetimeLocalToAppTimezoneSql(naive: string): string {
  const m = naive
    .trim()
    .match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/)
  if (!m) return naive.trim()
  const [, date, hh, mm, ss] = m
  return `${date} ${hh}:${mm}:${ss ?? '00'}`
}

function parseDatetimeLocalToDate(naive: string): Date | null {
  const m = naive.trim().match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/)
  if (!m) return null
  const y = Number(m[1])
  const mo = Number(m[2])
  const d = Number(m[3])
  const h = Number(m[4])
  const mi = Number(m[5])
  return new Date(y, mo - 1, d, h, mi, 0, 0)
}

function formatDateToDatetimeLocal(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function addMinutesToDatetimeLocal(naive: string, addMinutes: number): string {
  const base = parseDatetimeLocalToDate(naive)
  if (!base || Number.isNaN(base.getTime())) return naive
  base.setMinutes(base.getMinutes() + addMinutes)
  return formatDateToDatetimeLocal(base)
}

function totalSelectedDurationMinutes(): number {
  const ids = form.value.service_ids
  if (!ids.length) return 60
  let sum = 0
  for (const id of ids) {
    const s = services.value.find((x) => x.id === Number(id))
    if (s) sum += Math.max(1, Number(s.duration_minutes) || 0)
  }
  return sum > 0 ? sum : 60
}

function recalculateEndFromStart() {
  if (!form.value.starts_at?.trim()) return
  form.value.ends_at = addMinutesToDatetimeLocal(form.value.starts_at, totalSelectedDurationMinutes())
}

/** Use with `@update:model-value`, not `@change`: `change` fires before the value is updated (one-step lag). */
function onServiceIdsUpdate(v: unknown) {
  form.value.service_ids = Array.isArray(v) ? v.map((id) => Number(id)) : []
  recalculateEndFromStart()
}

function onStartsAtInput(e: Event) {
  form.value.starts_at = (e.target as HTMLInputElement).value
  recalculateEndFromStart()
}

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

function onDateClick(arg: { date: Date; dateStr: string; allDay: boolean }) {
  modal.value = 'create'
  editId.value = null
  const starts_at =
    arg.allDay || !arg.dateStr.includes('T')
      ? `${arg.dateStr.slice(0, 10)}T10:00`
      : fullCalendarCoercedDateToDatetimeLocal(arg.date)
  const ends_at = addMinutesToDatetimeLocal(starts_at, 60)
  form.value = {
    client_name: '',
    service_ids: [],
    starts_at,
    ends_at,
    price_rub: '',
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
    service_ids: appointmentServiceIdsFromDetail(data),
    starts_at: isoToDatetimeLocalInTimeZone(data.starts_at, CALENDAR_TIMEZONE),
    ends_at: isoToDatetimeLocalInTimeZone(data.ends_at, CALENDAR_TIMEZONE),
    price_rub: data.price_kopecks != null ? data.price_kopecks / 100 : '',
    status: data.status,
  }
  sessionMessages.value = data.messages || []
}

async function saveAppointment() {
  const ids = form.value.service_ids
  const payload = {
    client_name: form.value.client_name,
    service_id: ids.length ? ids[0] : null,
    extra_service_ids: ids.slice(1),
    starts_at: datetimeLocalToAppTimezoneSql(form.value.starts_at),
    ends_at: datetimeLocalToAppTimezoneSql(form.value.ends_at),
    price_kopecks:
      form.value.price_rub === '' || form.value.price_rub === null || form.value.price_rub === undefined
        ? null
        : Math.round(Number(form.value.price_rub) * 100),
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
  timeZone: CALENDAR_TIMEZONE,
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
            <label class="text-xs text-slate-400">Услуги</label>
            <Multiselect
              :model-value="form.service_ids"
              class="sb-services-multiselect mt-1"
              mode="tags"
              @update:model-value="onServiceIdsUpdate"
              :options="services"
              value-prop="id"
              label="title"
              track-by="id"
              placeholder="Выберите одну или несколько услуг"
              no-options-text="Нет услуг"
              no-results-text="Ничего не найдено"
              :close-on-select="false"
              :searchable="true"
              :can-clear="true"
              :create-tag="false"
              :create-option="false"
            />
          </div>
          <div>
            <label class="text-xs text-slate-400">Начало</label>
            <input
              :value="form.starts_at"
              type="datetime-local"
              class="mt-1 w-full rounded border border-white/10 bg-slate-950 px-2 py-1 text-white"
              @input="onStartsAtInput"
            />
          </div>
          <div>
            <label class="text-xs text-slate-400">Конец</label>
            <input v-model="form.ends_at" type="datetime-local" class="mt-1 w-full rounded border border-white/10 bg-slate-950 px-2 py-1 text-white" />
          </div>
          <div>
            <label class="text-xs text-slate-400">Цена (₽)</label>
            <input
              v-model="form.price_rub"
              type="number"
              min="0"
              step="0.01"
              class="mt-1 w-full rounded border border-white/10 bg-slate-950 px-2 py-1 text-white"
            />
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

<style src="@vueform/multiselect/themes/default.css"></style>

<style scoped>
.sb-services-multiselect {
  --ms-font-size: 0.875rem;
  --ms-line-height: 1.375;
  --ms-radius: 0.375rem;
  --ms-bg: rgb(2 6 23);
  --ms-bg-disabled: rgb(15 23 42);
  --ms-border-color: rgba(255 255 255 / 0.1);
  --ms-border-width: 1px;
  --ms-border-color-active: rgba(99 102 241 / 0.45);
  --ms-ring-width: 2px;
  --ms-ring-color: rgba(99 102 241 / 0.25);
  --ms-py: 0.35rem;
  --ms-px: 0.5rem;
  --ms-placeholder-color: rgb(100 116 139);
  --ms-max-height: 12rem;
  --ms-dropdown-bg: rgb(15 23 42);
  --ms-dropdown-border-color: rgba(255 255 255 / 0.1);
  --ms-dropdown-radius: 0.375rem;
  --ms-option-font-size: 0.875rem;
  --ms-option-bg-pointed: rgba(255 255 255 / 0.06);
  --ms-option-color-pointed: rgb(241 245 249);
  --ms-option-bg-selected: rgb(99 102 241);
  --ms-option-color-selected: rgb(255 255 255);
  --ms-option-bg-selected-pointed: rgb(79 70 229);
  --ms-option-color-selected-pointed: rgb(255 255 255);
  --ms-empty-color: rgb(148 163 184);
  --ms-tag-bg: rgb(67 56 202);
  --ms-tag-color: rgb(238 242 255);
  --ms-tag-radius: 0.375rem;
  --ms-tag-font-weight: 500;
  --ms-tag-font-size: 0.8125rem;
  --ms-spinner-color: rgb(99 102 241);
  --ms-caret-color: rgb(148 163 184);
  --ms-clear-color: rgb(148 163 184);
  --ms-clear-color-hover: rgb(226 232 240);
  color: rgb(248 250 252);
}

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
