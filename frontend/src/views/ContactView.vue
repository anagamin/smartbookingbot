<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import http from '@/api/http'

type MessageType = 'bug' | 'improvement'

type ContactRow = {
  id: number
  created_at: string
  message_type: MessageType
  body: string
  response: string | null
}

const messageType = ref<MessageType>('bug')
const body = ref('')
const submitting = ref(false)
const submitError = ref<string | null>(null)
const submitSuccess = ref(false)
const list = ref<ContactRow[]>([])
const listLoading = ref(true)
const listError = ref<string | null>(null)

const typeLabels: Record<MessageType, string> = {
  bug: 'Сообщить об ошибке',
  improvement: 'Предложить улучшение сайта',
}

const formattedRows = computed(() =>
  list.value.map((row) => ({
    id: row.id,
    date: formatDate(row.created_at),
    question: `${typeLabels[row.message_type]}: ${row.body}`,
    answer: row.response?.trim() ? row.response : 'нет ответа',
  })),
)

function formatDate(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) {
    return iso
  }
  return new Intl.DateTimeFormat('ru-RU', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(d)
}

async function loadList() {
  listLoading.value = true
  listError.value = null
  try {
    const { data } = await http.get<{ data: ContactRow[] }>('/contact-messages')
    list.value = data.data ?? []
  } catch {
    listError.value = 'Не удалось загрузить список обращений.'
    list.value = []
  } finally {
    listLoading.value = false
  }
}

async function onSubmit() {
  submitError.value = null
  submitSuccess.value = false
  submitting.value = true
  try {
    await http.post('/contact-messages', {
      message_type: messageType.value,
      body: body.value.trim(),
    })
    submitSuccess.value = true
    body.value = ''
    await loadList()
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    const msg = err.response?.data?.message
    const firstField = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
    submitError.value = msg ?? firstField ?? 'Не удалось отправить. Попробуйте позже.'
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  void loadList()
})
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold text-white sm:text-3xl">Свяжитесь с нами</h1>
    <p class="mt-2 max-w-2xl text-sm text-slate-400 sm:text-base">
      Сообщите об ошибке или предложите улучшение — мы читаем все обращения. Ответ появится в таблице ниже, когда будет
      готов.
    </p>

    <form
      class="mt-10 max-w-2xl space-y-6 rounded-2xl border border-white/10 bg-white/[0.03] p-6 sm:p-8"
      @submit.prevent="onSubmit"
    >
        <div>
          <p class="text-sm font-medium text-slate-200">Тип сообщения</p>
          <div class="mt-3 space-y-3">
            <label
              v-for="opt in (['bug', 'improvement'] as const)"
              :key="opt"
              class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-slate-950/40 p-4 transition hover:border-indigo-400/30"
              :class="messageType === opt ? 'ring-1 ring-indigo-400/40' : ''"
            >
              <input v-model="messageType" type="radio" name="message_type" :value="opt" class="mt-1 text-indigo-500" />
              <span class="text-sm text-slate-200">{{ typeLabels[opt] }}</span>
            </label>
          </div>
        </div>

        <div>
          <label for="contact-body" class="text-sm font-medium text-slate-200">Текст</label>
          <textarea
            id="contact-body"
            v-model="body"
            required
            rows="8"
            maxlength="10000"
            class="mt-2 w-full resize-y rounded-xl border border-white/10 bg-slate-950/60 px-4 py-3 text-sm text-slate-100 placeholder:text-slate-500 focus:border-indigo-400/50 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
            placeholder="Опишите проблему или идею подробно…"
          />
          <p class="mt-1 text-xs text-slate-500">До 10 000 символов.</p>
        </div>

        <p v-if="submitSuccess" class="rounded-lg bg-emerald-500/15 px-4 py-3 text-sm text-emerald-200 ring-1 ring-emerald-400/25">
          Запрос отправлен.
        </p>
        <p v-if="submitError" class="rounded-lg bg-rose-500/15 px-4 py-3 text-sm text-rose-200 ring-1 ring-rose-400/25">
          {{ submitError }}
        </p>

        <button
          type="submit"
          :disabled="submitting || !body.trim()"
          class="rounded-xl bg-indigo-500 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/30 transition hover:bg-indigo-400 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {{ submitting ? 'Отправка…' : 'Отправить' }}
        </button>
      </form>

      <section class="mt-16">
        <h2 class="text-xl font-semibold text-white sm:text-2xl">Обращения</h2>
        <p class="mt-2 text-sm text-slate-400">Дата, ваш текст и ответ (если уже есть).</p>

        <p v-if="listLoading" class="mt-6 text-sm text-slate-400">Загрузка…</p>
        <p v-else-if="listError" class="mt-6 text-sm text-rose-300">{{ listError }}</p>
        <div
          v-else-if="formattedRows.length === 0"
          class="mt-6 rounded-xl border border-white/10 bg-white/[0.02] px-4 py-8 text-center text-sm text-slate-500"
        >
          Пока нет обращений.
        </div>
        <div v-else class="mt-6 overflow-x-auto rounded-2xl border border-white/10 bg-slate-950/40">
          <table class="min-w-full divide-y divide-white/10 text-left text-sm">
            <thead class="bg-white/[0.04] text-xs font-semibold uppercase tracking-wide text-slate-400">
              <tr>
                <th class="whitespace-nowrap px-4 py-3 sm:px-6">Дата</th>
                <th class="px-4 py-3 sm:px-6">Вопрос</th>
                <th class="px-4 py-3 sm:px-6">Ответ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/10 text-slate-200">
              <tr v-for="row in formattedRows" :key="row.id" class="align-top">
                <td class="whitespace-nowrap px-4 py-4 text-slate-300 sm:px-6">{{ row.date }}</td>
                <td class="max-w-md px-4 py-4 text-slate-200 sm:max-w-xl sm:px-6">
                  <span class="whitespace-pre-wrap break-words">{{ row.question }}</span>
                </td>
                <td class="max-w-md px-4 py-4 text-slate-300 sm:max-w-xl sm:px-6">
                  <span class="whitespace-pre-wrap break-words">{{ row.answer }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
  </div>
</template>
