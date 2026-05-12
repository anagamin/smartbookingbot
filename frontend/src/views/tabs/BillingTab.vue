<script setup lang="ts">
import http from '@/api/http'
import axios from 'axios'
import { computed, inject, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
const reloadCabinetUser = inject<(() => Promise<void>) | undefined>('reloadCabinetUser', undefined)

type Plan = {
  id: string
  title: string
  months: number
  amount_kopecks: number
  price_rub: number
}

const plans = ref<Plan[]>([])
const loadError = ref('')
const checkoutPlan = ref<string | null>(null)
const checkoutMsg = ref('')
const paidBanner = ref(false)

const highlightById = computed<Record<string, 'save' | 'best'>>(() => ({
  '6m': 'save',
  '12m': 'best',
}))

function perMonthRub(plan: Plan): string {
  const per = plan.price_rub / plan.months
  return Math.round(per).toLocaleString('ru-RU')
}

function formatRub(n: number) {
  return n.toLocaleString('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 })
}

async function loadPlans() {
  loadError.value = ''
  try {
    const { data } = await http.get<{ plans: Plan[] }>('/billing/plans')
    plans.value = data.plans
  } catch (e: unknown) {
    loadError.value = axios.isAxiosError(e)
      ? String((e.response?.data as { message?: string })?.message || e.message || 'Не удалось загрузить тарифы')
      : 'Не удалось загрузить тарифы'
  }
}

async function checkout(planId: string) {
  checkoutMsg.value = ''
  checkoutPlan.value = planId
  const returnUrl = `${window.location.origin}/app/billing?paid=1`
  try {
    const { data } = await http.post<{ confirmation_url: string | null; payment_id: string }>('/billing/checkout', {
      plan_key: planId,
      return_url: returnUrl,
    })
    if (data.confirmation_url) {
      window.location.href = data.confirmation_url
      return
    }
    checkoutMsg.value = 'Не получили ссылку на оплату — попробуйте позже.'
  } catch (e: unknown) {
    checkoutMsg.value = axios.isAxiosError(e)
      ? String((e.response?.data as { message?: string })?.message || e.message || 'Ошибка')
      : 'Ошибка'
  } finally {
    checkoutPlan.value = null
  }
}

watch(
  () => route.query.paid,
  (paid) => {
    if (paid === '1' || paid === 'true') {
      paidBanner.value = true
      void reloadCabinetUser?.()
      void router.replace({ path: '/app/billing', query: {} })
    }
  },
  { immediate: true },
)

onMounted(() => {
  void loadPlans()
})
</script>

<template>
  <div class="max-w-4xl space-y-8">
    <div>
      <h2 class="text-xl font-semibold text-white">Оплата подписки</h2>
      <p class="mt-2 text-sm leading-relaxed text-slate-300">
        Оплата через ЮKassa. После успешной оплаты срок доступа продлевается автоматически (от текущей даты окончания
        или от сегодня, если подписка уже истекла).
      </p>
    </div>

    <div
      v-if="paidBanner"
      class="rounded-xl border border-emerald-500/35 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-100"
      role="status"
    >
      Если платёж прошёл, статус подписки обновится через несколько секунд. При необходимости обновите страницу.
    </div>

    <p v-if="loadError" class="text-sm text-red-400">{{ loadError }}</p>
    <p v-if="checkoutMsg" class="text-sm text-amber-300">{{ checkoutMsg }}</p>

    <div class="grid gap-4 sm:grid-cols-3">
      <div
        v-for="plan in plans"
        :key="plan.id"
        class="relative flex flex-col rounded-xl border bg-slate-900/50 p-5 shadow-lg transition hover:border-indigo-400/40"
        :class="
          highlightById[plan.id] === 'best'
            ? 'border-indigo-500/50 ring-1 ring-indigo-500/25'
            : 'border-white/10'
        "
      >
        <span
          v-if="highlightById[plan.id] === 'best'"
          class="absolute -top-2.5 right-3 rounded-full bg-indigo-500 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white"
        >
          выгоднее
        </span>
        <span
          v-else-if="highlightById[plan.id] === 'save'"
          class="absolute -top-2.5 right-3 rounded-full bg-amber-500/90 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-950"
        >
          −17% к помесячно
        </span>
        <h3 class="text-lg font-semibold text-white">{{ plan.title }}</h3>
        <p class="mt-1 text-2xl font-bold text-white">{{ formatRub(plan.price_rub) }}</p>
        <p class="mt-1 text-xs text-slate-400">≈ {{ perMonthRub(plan) }} ₽ / мес.</p>
        <button
          type="button"
          class="mt-5 w-full rounded-lg bg-indigo-500 py-2.5 text-sm font-medium text-white hover:bg-indigo-400 disabled:opacity-50"
          :disabled="checkoutPlan !== null"
          @click="checkout(plan.id)"
        >
          {{ checkoutPlan === plan.id ? 'Переход…' : 'Оплатить' }}
        </button>
      </div>
    </div>

    <p class="text-xs text-slate-500">
      Нажимая «Оплатить», вы перейдёте на защищённую страницу ЮKassa. Мы не храним данные банковской карты.
    </p>
  </div>
</template>
