<script setup lang="ts">
import publicHttp from '@/api/publicHttp'
import Multiselect from '@vueform/multiselect'
import axios from 'axios'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

type ServiceItem = {
  id: number
  title: string
  description: string | null
  price_kopecks: number
  duration_minutes: number
}

type SlotDay = {
  date: string
  label: string
  slots: Array<{ starts_at: string; ends_at: string; time: string }>
}

const route = useRoute()
const slug = computed(() => String(route.params.slug ?? ''))

const loading = ref(true)
const notFound = ref(false)
const ownerName = ref('')
const services = ref<ServiceItem[]>([])

const clientName = ref('')
const serviceIds = ref<number[]>([])
const selectedStartsAt = ref('')
const comment = ref('')

const slotDays = ref<SlotDay[]>([])
const slotsLoading = ref(false)
const submitLoading = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const selectedServices = computed(() =>
  services.value.filter((s) => serviceIds.value.includes(s.id)),
)

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

async function loadPage(): Promise<void> {
  loading.value = true
  notFound.value = false
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const { data } = await publicHttp.get<{
      owner_name: string
      services: ServiceItem[]
    }>(`/public/book/${encodeURIComponent(slug.value)}`)
    ownerName.value = data.owner_name
    services.value = data.services
    if (data.services.length === 1) {
      serviceIds.value = [data.services[0].id]
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
    const { data } = await publicHttp.get<{ days: SlotDay[] }>(
      `/public/book/${encodeURIComponent(slug.value)}/slots`,
      { params: { service_ids: serviceIds.value } },
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

watch(serviceIds, () => {
  void loadSlots()
})

watch(slug, () => {
  serviceIds.value = []
  selectedStartsAt.value = ''
  void loadPage()
}, { immediate: true })

async function submitBooking(): Promise<void> {
  errorMessage.value = ''
  successMessage.value = ''
  if (!clientName.value.trim()) {
    errorMessage.value = 'Укажите ваше имя.'
    return
  }
  if (!serviceIds.value.length) {
    errorMessage.value = 'Выберите хотя бы одну услугу.'
    return
  }
  if (!selectedStartsAt.value) {
    errorMessage.value = 'Выберите время записи.'
    return
  }
  submitLoading.value = true
  try {
    const { data } = await publicHttp.post<{ message: string }>(
      `/public/book/${encodeURIComponent(slug.value)}/appointments`,
      {
        client_name: clientName.value.trim(),
        service_ids: serviceIds.value,
        starts_at: selectedStartsAt.value,
        comment: comment.value.trim() || null,
      },
    )
    successMessage.value = data.message || 'Запись оформлена.'
    clientName.value = ''
    comment.value = ''
    selectedStartsAt.value = ''
    await loadSlots()
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
        <p class="mt-1 text-sm text-slate-400">Выберите услуги и удобное время</p>

        <p v-if="successMessage" class="mt-4 rounded-lg border border-emerald-500/30 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-300">
          {{ successMessage }}
        </p>
        <p v-if="errorMessage" class="mt-4 rounded-lg border border-red-500/30 bg-red-950/30 px-4 py-3 text-sm text-red-300">
          {{ errorMessage }}
        </p>

        <form class="mt-8 space-y-6" @submit.prevent="submitBooking">
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
              mode="multiple"
              :options="services.map((s) => ({ value: s.id, label: s.title }))"
              :close-on-select="false"
              :searchable="services.length > 5"
              placeholder="Выберите одну или несколько услуг"
              class="mt-2 sb-multiselect"
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
                    @click="selectedStartsAt = slot.starts_at"
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
  --ms-bg: rgb(2 6 23);
  --ms-border-color: rgb(255 255 255 / 0.1);
  --ms-tag-bg: rgb(79 70 229);
  --ms-option-bg-selected: rgb(79 70 229);
  --ms-dropdown-bg: rgb(2 6 23);
  --ms-font-size: 0.875rem;
}
</style>
