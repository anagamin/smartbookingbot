<script setup lang="ts">
import publicHttp from '@/api/publicHttp'
import Multiselect from '@vueform/multiselect'
import axios from 'axios'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

type MasterItem = { id: number; name: string }

type ServiceItem = {
  id: number
  master_id: number | null
  title: string
  description: string | null
  price_kopecks: number
  duration_minutes: number
}

type SlotItem = {
  starts_at: string
  ends_at: string
  time: string
  master_id?: number
  master_name?: string | null
}

type SlotDay = {
  date: string
  label: string
  slots: SlotItem[]
}

type BookingConfirmation = {
  clientName: string
  masterName: string | null
  dateLabel: string
  timeLabel: string
  services: string[]
  durationMinutes: number
  priceRub: number | null
}

const BOOKING_TIMEZONE = 'Europe/Moscow'

const route = useRoute()
const slug = computed(() => String(route.params.slug ?? ''))

const loading = ref(true)
const notFound = ref(false)
const ownerName = ref('')
const isSalon = ref(false)
const masters = ref<MasterItem[]>([])
const selectedMasterId = ref<number | null>(null)
const allServices = ref<ServiceItem[]>([])
const services = ref<ServiceItem[]>([])

const clientName = ref('')
const serviceIds = ref<number[]>([])
const selectedStartsAt = ref('')
const comment = ref('')

const slotDays = ref<SlotDay[]>([])
const slotsLoading = ref(false)
const submitLoading = ref(false)
const bookingConfirmation = ref<BookingConfirmation | null>(null)
const errorMessage = ref('')

const showMasterPicker = computed(() => isSalon.value && masters.value.length > 1)

const selectedServices = computed(() =>
  services.value.filter((s) => serviceIds.value.includes(s.id)),
)

const servicesForMaster = computed(() => {
  if (!showMasterPicker.value || selectedMasterId.value == null) {
    return allServices.value
  }
  return allServices.value.filter((s) => s.master_id === selectedMasterId.value)
})

watch(selectedMasterId, () => {
  services.value = servicesForMaster.value
  serviceIds.value = serviceIds.value.filter((id) => services.value.some((s) => s.id === id))
  selectedStartsAt.value = ''
})

const totalDurationMinutes = computed(() => {
  const list = selectedServices.value
  if (!list.length) return 0
  return list.reduce((sum, s) => sum + Math.max(1, Number(s.duration_minutes) || 0), 0)
})

const totalPriceRub = computed(() => {
  const kopecks = selectedServices.value.reduce((sum, s) => sum + (s.price_kopecks ?? 0), 0)
  return kopecks / 100
})

const hasPrice = computed(() => selectedServices.value.some((s) => (s.price_kopecks ?? 0) > 0))

function formatBookingDate(iso: string): string {
  return new Intl.DateTimeFormat('ru-RU', {
    timeZone: BOOKING_TIMEZONE,
    day: 'numeric',
    month: 'long',
    weekday: 'long',
  }).format(new Date(iso))
}

function formatBookingTimeRange(startIso: string, endIso: string): string {
  const timeOpts: Intl.DateTimeFormatOptions = {
    timeZone: BOOKING_TIMEZONE,
    hour: '2-digit',
    minute: '2-digit',
  }
  const start = new Intl.DateTimeFormat('ru-RU', timeOpts).format(new Date(startIso))
  const end = new Intl.DateTimeFormat('ru-RU', timeOpts).format(new Date(endIso))
  return `${start} – ${end}`
}

function startNewBooking(): void {
  bookingConfirmation.value = null
  clientName.value = ''
  serviceIds.value = services.value.length === 1 ? [services.value[0].id] : []
  selectedStartsAt.value = ''
  comment.value = ''
  errorMessage.value = ''
  if (showMasterPicker.value && !selectedMasterId.value && masters.value[0]) {
    selectedMasterId.value = masters.value[0].id
  }
  void loadSlots()
}

async function loadPage(): Promise<void> {
  loading.value = true
  notFound.value = false
  errorMessage.value = ''
  bookingConfirmation.value = null
  try {
    const { data } = await publicHttp.get<{
      owner_name: string
      is_salon: boolean
      masters: MasterItem[]
      services: ServiceItem[]
    }>(`/public/book/${encodeURIComponent(slug.value)}`)
    ownerName.value = data.owner_name
    isSalon.value = data.is_salon
    masters.value = data.masters ?? []
    allServices.value = data.services
    services.value = data.services
    if (showMasterPicker.value && masters.value[0]) {
      selectedMasterId.value = masters.value[0].id
      services.value = servicesForMaster.value
    }
    if (services.value.length === 1) {
      serviceIds.value = [services.value[0].id]
    }
  } catch (e: unknown) {
    if (axios.isAxiosError(e) && e.response?.status === 404) {
      notFound.value = true
    } else {
      errorMessage.value = 'Не удалось загрузить страницу записи.'
    }
  } finally {
    loading.value = false
  }
}

async function loadSlots(): Promise<void> {
  if (!serviceIds.value.length) {
    slotDays.value = []
    selectedStartsAt.value = ''
    return
  }
  slotsLoading.value = true
  errorMessage.value = ''
  try {
    const params: Record<string, unknown> = { service_ids: serviceIds.value }
    if (selectedMasterId.value != null) {
      params.master_id = selectedMasterId.value
    }
    const { data } = await publicHttp.get<{ days: SlotDay[] }>(
      `/public/book/${encodeURIComponent(slug.value)}/slots`,
      { params },
    )
    slotDays.value = data.days ?? []
    if (!slotDays.value.some((d) => d.slots.some((s) => s.starts_at === selectedStartsAt.value))) {
      selectedStartsAt.value = ''
    }
  } catch {
    slotDays.value = []
    errorMessage.value = 'Не удалось загрузить свободные слоты.'
  } finally {
    slotsLoading.value = false
  }
}

function onServiceIdsUpdate(v: unknown): void {
  serviceIds.value = Array.isArray(v) ? v.map((id) => Number(id)) : []
}

function onSlotSelect(slot: SlotItem): void {
  selectedStartsAt.value = slot.starts_at
  if (slot.master_id != null) {
    selectedMasterId.value = slot.master_id
  }
}

watch(serviceIds, () => {
  void loadSlots()
})

watch(slug, () => {
  serviceIds.value = []
  selectedMasterId.value = null
  selectedStartsAt.value = ''
  bookingConfirmation.value = null
  void loadPage()
}, { immediate: true })

async function submitBooking(): Promise<void> {
  errorMessage.value = ''
  if (!clientName.value.trim()) {
    errorMessage.value = 'Укажите ваше имя.'
    return
  }
  if (!serviceIds.value.length) {
    errorMessage.value = 'Выберите хотя бы одну услугу.'
    return
  }
  if (showMasterPicker.value && selectedMasterId.value == null) {
    errorMessage.value = 'Выберите мастера.'
    return
  }
  if (!selectedStartsAt.value) {
    errorMessage.value = 'Выберите время записи.'
    return
  }
  const startsAt = selectedStartsAt.value
  const bookedServices = selectedServices.value.map((s) => s.title)
  const durationMinutes = totalDurationMinutes.value
  const priceRub = hasPrice.value ? totalPriceRub.value : null
  const name = clientName.value.trim()

  submitLoading.value = true
  try {
    const body: Record<string, unknown> = {
      client_name: name,
      service_ids: serviceIds.value,
      starts_at: startsAt,
      comment: comment.value.trim() || null,
    }
    if (selectedMasterId.value != null) {
      body.master_id = selectedMasterId.value
    }

    const { data } = await publicHttp.post<{
      message: string
      appointment: { starts_at: string; ends_at: string; master_name?: string }
    }>(`/public/book/${encodeURIComponent(slug.value)}/appointments`, body)

    bookingConfirmation.value = {
      clientName: name,
      masterName: data.appointment.master_name ?? masters.value.find((m) => m.id === selectedMasterId.value)?.name ?? null,
      dateLabel: formatBookingDate(data.appointment.starts_at),
      timeLabel: formatBookingTimeRange(data.appointment.starts_at, data.appointment.ends_at),
      services: bookedServices,
      durationMinutes,
      priceRub,
    }
  } catch (e: unknown) {
    if (axios.isAxiosError(e)) {
      const body = e.response?.data as { message?: string }
      errorMessage.value = body?.message || 'Не удалось оформить запись.'
    } else {
      errorMessage.value = 'Не удалось оформить запись.'
    }
  } finally {
    submitLoading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-gradient-to-b from-slate-950 via-indigo-950/40 to-slate-950">
    <header class="border-b border-white/10 bg-slate-950/70 backdrop-blur">
      <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-4 sm:px-6">
        <div class="grid h-9 w-9 place-items-center rounded-xl bg-indigo-500/15 ring-1 ring-indigo-400/20">
          <span class="text-sm font-semibold text-indigo-200">SB</span>
        </div>
        <span class="text-base font-semibold text-white">Запись онлайн</span>
      </div>
    </header>

    <main class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
      <div v-if="loading" class="text-center text-slate-400">Загрузка…</div>

      <div v-else-if="notFound" class="rounded-xl border border-white/10 bg-slate-900/50 p-8 text-center">
        <p class="text-lg font-medium text-white">Страница не найдена</p>
        <p class="mt-2 text-sm text-slate-400">Проверьте ссылку или обратитесь к мастеру.</p>
      </div>

      <template v-else>
        <h1 class="text-2xl font-semibold text-white">{{ ownerName }}</h1>
        <p class="mt-1 text-sm text-slate-400">
          {{ showMasterPicker ? 'Выберите мастера, услуги и удобное время' : 'Выберите услуги и удобное время' }}
        </p>

        <p v-if="errorMessage" class="mt-4 rounded-lg border border-red-500/30 bg-red-950/30 px-4 py-3 text-sm text-red-300">
          {{ errorMessage }}
        </p>

        <div
          v-if="bookingConfirmation"
          class="mt-8 rounded-xl border border-emerald-500/35 bg-gradient-to-br from-emerald-950/40 to-slate-900/50 p-6 shadow-lg"
        >
          <h2 class="text-lg font-semibold text-emerald-100">Запись оформлена</h2>
          <p class="mt-1 text-sm text-emerald-200/80">
            {{ bookingConfirmation.clientName }}, ждём вас
            <template v-if="bookingConfirmation.masterName"> у {{ bookingConfirmation.masterName }}</template>
            <template v-else> у {{ ownerName }}</template>.
          </p>
          <dl class="mt-5 space-y-3 text-sm">
            <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
              <dt class="shrink-0 text-slate-500 sm:w-28">Дата</dt>
              <dd class="capitalize text-white">{{ bookingConfirmation.dateLabel }}</dd>
            </div>
            <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
              <dt class="shrink-0 text-slate-500 sm:w-28">Время</dt>
              <dd class="text-white">{{ bookingConfirmation.timeLabel }}</dd>
            </div>
            <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
              <dt class="shrink-0 text-slate-500 sm:w-28">Услуги</dt>
              <dd class="text-white">{{ bookingConfirmation.services.join(' + ') }}</dd>
            </div>
            <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
              <dt class="shrink-0 text-slate-500 sm:w-28">Длительность</dt>
              <dd class="text-white">{{ bookingConfirmation.durationMinutes }} мин</dd>
            </div>
            <div v-if="bookingConfirmation.priceRub != null" class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
              <dt class="shrink-0 text-slate-500 sm:w-28">Стоимость</dt>
              <dd class="font-medium text-white">
                ~{{ bookingConfirmation.priceRub.toLocaleString('ru-RU') }} ₽
              </dd>
            </div>
          </dl>
          <button
            type="button"
            class="mt-6 rounded-lg border border-white/20 px-4 py-2 text-sm text-white hover:bg-white/5"
            @click="startNewBooking"
          >
            Записаться ещё раз
          </button>
        </div>

        <form v-else class="mt-8 space-y-6" @submit.prevent="submitBooking">
          <div v-if="showMasterPicker" class="rounded-xl border border-white/10 bg-slate-900/40 p-5">
            <label class="text-xs text-slate-400">Мастер</label>
            <select
              v-model="selectedMasterId"
              required
              class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white"
            >
              <option v-for="m in masters" :key="m.id" :value="m.id">{{ m.name }}</option>
            </select>
          </div>

          <div class="rounded-xl border border-white/10 bg-slate-900/40 p-5">
            <label class="text-xs text-slate-400">Ваше имя</label>
            <input
              v-model="clientName"
              required
              autocomplete="name"
              class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white"
              placeholder="Как к вам обращаться"
            />
          </div>

          <div class="rounded-xl border border-white/10 bg-slate-900/40 p-5">
            <label class="text-xs text-slate-400">Услуги</label>
            <p v-if="!services.length" class="mt-2 text-sm text-slate-500">Мастер пока не добавил услуги для записи.</p>
            <Multiselect
              v-else
              :model-value="serviceIds"
              mode="tags"
              class="sb-multiselect mt-2"
              :options="services"
              value-prop="id"
              label="title"
              track-by="id"
              placeholder="Выберите одну или несколько услуг"
              no-options-text="Нет услуг"
              no-results-text="Ничего не найдено"
              :close-on-select="false"
              :searchable="services.length > 5"
              :can-clear="true"
              :create-tag="false"
              :create-option="false"
              @update:model-value="onServiceIdsUpdate"
            />
            <p
              v-if="selectedServices.length"
              class="mt-3 text-sm text-slate-300"
            >
              Длительность: <strong class="text-white">{{ totalDurationMinutes }} мин</strong>
              <template v-if="hasPrice">
                · ориентировочно <strong class="text-white">{{ totalPriceRub.toLocaleString('ru-RU') }} ₽</strong>
              </template>
            </p>
          </div>

          <div class="rounded-xl border border-white/10 bg-slate-900/40 p-5">
            <label class="text-xs text-slate-400">Дата и время</label>
            <p v-if="!serviceIds.length" class="mt-2 text-sm text-slate-500">Сначала выберите услуги.</p>
            <p v-else-if="slotsLoading" class="mt-2 text-sm text-slate-500">Загрузка свободных слотов…</p>
            <p v-else-if="!slotDays.length" class="mt-2 text-sm text-slate-500">Нет свободных окон в ближайшие дни. Свяжитесь с мастером.</p>
            <div v-else class="mt-3 space-y-4">
              <div v-for="day in slotDays" :key="day.date">
                <p class="text-sm font-medium capitalize text-slate-200">{{ day.label }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button
                    v-for="slot in day.slots"
                    :key="slot.starts_at"
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-sm transition"
                    :class="
                      selectedStartsAt === slot.starts_at
                        ? 'border-indigo-400 bg-indigo-500/20 text-white'
                        : 'border-white/15 text-slate-300 hover:border-white/30 hover:bg-white/5'
                    "
                    @click="onSlotSelect(slot)"
                  >
                    {{ slot.time }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="rounded-xl border border-white/10 bg-slate-900/40 p-5">
            <label class="text-xs text-slate-400">Комментарий</label>
            <p class="mt-0.5 text-xs text-slate-500">Необязательно — мастер увидит его в карточке записи.</p>
            <textarea
              v-model="comment"
              rows="3"
              maxlength="2000"
              class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white"
              placeholder="Пожелания, уточнения…"
            />
          </div>

          <div
            v-if="selectedServices.length && selectedStartsAt"
            class="rounded-xl border border-indigo-500/25 bg-indigo-950/25 px-5 py-4 text-sm text-slate-200"
          >
            Итого: {{ totalDurationMinutes }} мин
            <template v-if="hasPrice"> · ~{{ totalPriceRub.toLocaleString('ru-RU') }} ₽</template>
          </div>

          <button
            type="submit"
            class="w-full rounded-lg bg-indigo-500 py-3 text-sm font-medium text-white hover:bg-indigo-400 disabled:opacity-50"
            :disabled="submitLoading || !services.length"
          >
            {{ submitLoading ? 'Отправка…' : 'Записаться' }}
          </button>
        </form>
      </template>
    </main>
  </div>
</template>

<style src="@vueform/multiselect/themes/default.css"></style>
<style>
.sb-multiselect {
  --ms-font-size: 0.875rem;
  --ms-line-height: 1.375;
  --ms-radius: 0.5rem;
  --ms-bg: rgb(2 6 23);
  --ms-border-color: rgb(255 255 255 / 0.1);
  --ms-border-color-active: rgba(99 102 241 / 0.45);
  --ms-py: 0.35rem;
  --ms-px: 0.5rem;
  --ms-placeholder-color: rgb(100 116 139);
  --ms-max-height: 12rem;
  --ms-dropdown-bg: rgb(15 23 42);
  --ms-dropdown-border-color: rgb(255 255 255 / 0.1);
  --ms-option-bg-pointed: rgba(255 255 255 / 0.06);
  --ms-option-color-pointed: rgb(241 245 249);
  --ms-option-bg-selected: rgb(99 102 241);
  --ms-option-color-selected: rgb(255 255 255);
  --ms-tag-bg: rgb(67 56 202);
  --ms-tag-color: rgb(238 242 255);
  --ms-tag-radius: 0.375rem;
  --ms-tag-font-weight: 500;
  --ms-tag-font-size: 0.8125rem;
  color: rgb(248 250 252);
}
</style>
