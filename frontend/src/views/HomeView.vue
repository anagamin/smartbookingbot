<script setup lang="ts">
import { RouterLink } from 'vue-router'

const audience = [
  'Маникюр',
  'Массаж',
  'Тату',
  'Психологи',
  'Ресницы',
  'Стрижки',
  'И другие частные мастера',
]

const dialog = [
  { role: 'client' as const, text: 'Снятие входит в ценник?' },
  {
    role: 'bot' as const,
    text: 'Снятие входит в стоимость маникюра, дополнительная оплата не требуется.',
  },
  { role: 'client' as const, text: 'Какие свободные окошки на завтра на маникюр?' },
  {
    role: 'bot' as const,
    text: 'Могу предложить: 9 мая: 10:00, 11:30, 15:30, 18:00. Какой вариант вам подходит?',
  },
  { role: 'client' as const, text: 'Запишите на 18:00' },
  {
    role: 'bot' as const,
    text: 'Запись зафиксирована на 9 мая, 18:00. Ждём вас!',
  },
]

const steps = [
  {
    title: 'Зарегистрироваться',
    body: 'Создайте аккаунт — email или через удобный способ входа.',
  },
  {
    title: 'Описать услуги',
    body: 'В свободной форме: например, маникюр, наращивание ногтей, принимаю у себя дома по адресу …; снятие входит в стоимость; трёхэтапная обработка, крафт-пакеты открываю при вас — чтобы бот отвечал на вопросы клиентов.',
  },
  {
    title: 'Указать рабочие дни и время',
    body: 'Например, пн–пт с 10:00 до 19:00 — бот будет предлагать только реальные слоты.',
  },
  {
    title: 'Прикрепить сообщество ВК',
    body: 'В профиле укажите группу, из личных сообщений которой бот принимает заявки.',
  },
  {
    title: 'Включить бота',
    body: 'Активируйте бота в профиле — он начнёт вести диалоги от имени сообщества.',
  },
  {
    title: 'Наслаждаться процессом',
    body: 'Клиенты получают быстрые ответы, вы — меньше рутины и больше фокуса на работе.',
  },
]

const solutionPoints = [
  {
    title: 'Расписание',
    text: 'Бот знает ваш график и предлагает время в рамках рабочих часов.',
  },
  {
    title: 'Слоты',
    text: 'Видит занятые и свободные окна — без пересечений и двойных записей.',
  },
  {
    title: 'Услуги',
    text: 'Название, стоимость, продолжительность — учитывается при подборе времени.',
  },
  {
    title: 'Контекст',
    text: 'Общая информация об услугах — чтобы отвечать на типовые вопросы в переписке.',
  },
]

const plans = [
  { name: 'Старт', price: '0 ₽', period: 'первый месяц', highlight: true, note: 'Попробуйте без оплаты' },
  { name: 'Месяц', price: '1 000 ₽', period: 'за месяц', highlight: false, note: 'Гибко продлевать' },
  { name: 'Полгода', price: '5 000 ₽', period: 'за 6 месяцев', highlight: false, note: 'Экономия по сравнению с помесячно' },
  { name: 'Год', price: '8 000 ₽', period: 'за 12 месяцев', highlight: false, note: 'Максимально выгодно при постоянной работе' },
]
</script>

<template>
  <div class="min-h-screen bg-gradient-to-b from-slate-950 via-indigo-950/40 to-slate-950">
    <header class="sticky top-0 z-30 border-b border-white/10 bg-slate-950/70 backdrop-blur">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
        <div class="flex items-center gap-3">
          <div class="grid h-9 w-9 place-items-center rounded-xl bg-indigo-500/15 ring-1 ring-indigo-400/20">
            <span class="text-sm font-semibold text-indigo-200">SB</span>
          </div>
          <span class="text-base font-semibold tracking-tight text-white">SmartBookingBot</span>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
          <RouterLink
            to="/login"
            class="rounded-lg px-3 py-2 text-sm text-slate-300 transition hover:text-white sm:px-4"
          >
            Войти
          </RouterLink>
          <RouterLink
            to="/register"
            class="rounded-lg bg-indigo-500 px-3 py-2 text-sm font-medium text-white shadow-lg shadow-indigo-500/30 transition hover:bg-indigo-400 sm:px-4"
          >
            Попробовать бесплатно
          </RouterLink>
        </div>
      </div>
    </header>

    <main>
      <!-- Hero: сервис + CTA -->
      <section class="mx-auto max-w-6xl px-4 pb-16 pt-12 sm:px-6 sm:pb-20 sm:pt-16">
        <div class="mx-auto max-w-3xl text-center">
          <p class="text-xs font-semibold uppercase tracking-[0.22em] text-indigo-300">
            Запись через личные сообщения ВК
          </p>
          <h1 class="mt-4 text-3xl font-bold leading-tight text-white sm:text-4xl md:text-5xl">
            Запись клиентов без хаоса в переписках
          </h1>
          <p class="mx-auto mt-6 max-w-2xl text-base leading-relaxed text-slate-300 sm:text-lg">
            SmartBookingBot отвечает клиентам в личных сообщениях сообщества, подбирает свободные слоты по вашему
            графику и фиксирует записи в календаре. Вы управляете услугами, ценами и рабочими часами из кабинета — бот
            делает рутину.
          </p>
          <div class="mt-10 flex flex-wrap items-center justify-center gap-3">
            <RouterLink
              to="/register"
              class="rounded-xl bg-white px-6 py-3 text-sm font-semibold text-slate-900 shadow transition hover:bg-slate-100"
            >
              Зарегистрироваться бесплатно
            </RouterLink>
            <RouterLink
              to="/login"
              class="rounded-xl border border-white/20 px-6 py-3 text-sm font-medium text-white transition hover:bg-white/5"
            >
              Войти в кабинет
            </RouterLink>
          </div>
          <p class="mt-4 text-xs text-slate-500">Первый месяц — без оплаты. Дальше — прозрачные тарифы ниже.</p>
        </div>
      </section>

      <!-- Боль -->
      <section class="border-t border-white/10 bg-slate-950/50 py-14 sm:py-16">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
          <h2 class="text-center text-2xl font-semibold text-white sm:text-3xl">Знакомо?</h2>
          <p class="mx-auto mt-2 max-w-xl text-center text-sm text-slate-400">
            Большинство мастеров сталкиваются с одним и тем же — пока вы в работе, переписка не ждёт.
          </p>
          <div class="mt-10 grid gap-6 md:grid-cols-2">
            <div class="rounded-2xl border border-rose-500/20 bg-rose-500/5 p-6 sm:p-8">
              <p class="text-sm font-medium text-rose-200/90">Потеря фокуса</p>
              <p class="mt-3 text-base leading-relaxed text-slate-200 sm:text-lg">
                Клиенты пишут вечером и в выходные, задают одни и те же вопросы, ждут ответа — а вы теряете фокус и
                силы.
              </p>
            </div>
            <div class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-6 sm:p-8">
              <p class="text-sm font-medium text-amber-200/90">Хаос в записи</p>
              <p class="mt-3 text-base leading-relaxed text-slate-200 sm:text-lg">
                Переносы, отмены, пересечения, «а вы точно меня записали?» — всё это съедает время и ухудшает опыт
                клиента.
              </p>
            </div>
          </div>
        </div>
      </section>

      <!-- Решение -->
      <section class="border-t border-white/10 py-14 sm:py-16">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
          <div class="grid gap-10 lg:grid-cols-2 lg:items-center lg:gap-14">
            <div>
              <h2 class="text-2xl font-semibold text-white sm:text-3xl">Что делает бот</h2>
              <p class="mt-4 text-base leading-relaxed text-slate-400">
                Он опирается на ваши данные из кабинета: не «угадывает», а отвечает и предлагает слоты так, как вы
                настроили сервис.
              </p>
              <ul class="mt-8 space-y-4">
                <li
                  v-for="(item, i) in solutionPoints"
                  :key="item.title"
                  class="flex gap-4 rounded-2xl border border-white/10 bg-white/[0.03] p-4 sm:p-5"
                >
                  <span
                    class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-indigo-500/20 text-sm font-semibold text-indigo-200 ring-1 ring-indigo-400/25"
                  >
                    {{ i + 1 }}
                  </span>
                  <div>
                    <p class="font-medium text-white">{{ item.title }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-slate-400">{{ item.text }}</p>
                  </div>
                </li>
              </ul>
            </div>
            <div class="relative">
              <div
                class="absolute -inset-4 rounded-[2rem] bg-gradient-to-tr from-indigo-500/20 via-fuchsia-500/10 to-cyan-500/10 blur-2xl lg:-inset-6"
              />
              <div class="relative rounded-3xl border border-white/10 bg-slate-950/60 p-6 shadow-2xl sm:p-8">
                <p class="text-xs font-semibold uppercase tracking-wider text-indigo-300">В кабинете вы задаёте правила</p>
                <p class="mt-2 text-sm text-slate-400">
                  Услуги, цены, длительность, свободный текст про условия — бот использует это в диалоге и при записи в
                  календарь.
                </p>
                <div class="mt-6 flex flex-wrap gap-2">
                  <span class="rounded-lg bg-emerald-500/10 px-3 py-1 text-xs font-medium text-emerald-200 ring-1 ring-emerald-400/20">
                    Без пересечений по времени
                  </span>
                  <span class="rounded-lg bg-white/5 px-3 py-1 text-xs font-medium text-slate-300 ring-1 ring-white/10">
                    Календарь записей
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Для кого -->
      <section class="border-t border-white/10 bg-slate-950/50 py-14 sm:py-16">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
          <h2 class="text-2xl font-semibold text-white sm:text-3xl">Для кого подходит</h2>
          <p class="mt-3 max-w-2xl text-sm leading-relaxed text-slate-400 sm:text-base">
            Частные мастера и специалисты, которые принимают запись из личных сообщений сообщества ВКонтакте.
          </p>
          <div class="mt-8 flex flex-wrap gap-2 sm:gap-3">
            <span
              v-for="tag in audience"
              :key="tag"
              class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-200 transition hover:border-indigo-400/30 hover:bg-indigo-500/10"
            >
              {{ tag }}
            </span>
          </div>
        </div>
      </section>

      <!-- Пример диалога -->
      <section class="border-t border-white/10 py-14 sm:py-16">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
          <h2 class="text-2xl font-semibold text-white sm:text-3xl">Пример диалога</h2>
          <p class="mt-3 max-w-2xl text-sm text-slate-400 sm:text-base">
            Так может выглядеть переписка, когда бот уже знает ваши услуги и расписание.
          </p>
          <div
            class="mx-auto mt-10 max-w-xl rounded-3xl border border-white/10 bg-slate-900/80 p-4 shadow-xl ring-1 ring-white/5 sm:p-6"
          >
            <div class="mb-4 flex items-center gap-2 border-b border-white/10 pb-4">
              <div class="h-2 w-2 rounded-full bg-emerald-400" />
              <span class="text-xs font-medium text-slate-400">Личные сообщения · клиент и бот</span>
            </div>
            <div class="space-y-3">
              <div
                v-for="(line, idx) in dialog"
                :key="idx"
                :class="line.role === 'client' ? 'flex justify-end' : 'flex justify-start'"
              >
                <div
                  :class="[
                    'max-w-[90%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed sm:max-w-[85%]',
                    line.role === 'client'
                      ? 'rounded-br-md bg-indigo-500 text-white'
                      : 'rounded-bl-md border border-white/10 bg-white/5 text-slate-200',
                  ]"
                >
                  {{ line.text }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Порядок работы -->
      <section class="border-t border-white/10 bg-slate-950/50 py-14 sm:py-16">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
          <h2 class="text-2xl font-semibold text-white sm:text-3xl">Порядок работы</h2>
          <p class="mt-3 max-w-2xl text-sm text-slate-400 sm:text-base">
            От регистрации до первых записей — по шагам, без лишней суеты.
          </p>
          <ol class="relative mt-12 space-y-0 sm:mt-14">
            <li
              v-for="(step, index) in steps"
              :key="step.title"
              class="relative flex gap-4 pb-10 pl-2 sm:gap-6 sm:pl-0"
            >
              <div
                v-if="index < steps.length - 1"
                class="absolute left-[19px] top-10 hidden h-[calc(100%-0.5rem)] w-px bg-gradient-to-b from-indigo-500/50 to-transparent sm:left-[23px] sm:block"
                aria-hidden="true"
              />
              <div
                class="relative z-10 grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-500 text-sm font-bold text-white shadow-lg shadow-indigo-500/25 sm:h-12 sm:w-12 sm:text-base"
              >
                {{ index + 1 }}
              </div>
              <div class="min-w-0 flex-1 rounded-2xl border border-white/10 bg-white/[0.03] p-4 sm:p-6">
                <p class="font-semibold text-white">{{ step.title }}</p>
                <p class="mt-2 text-sm leading-relaxed text-slate-400">{{ step.body }}</p>
              </div>
            </li>
          </ol>
        </div>
      </section>

      <!-- Тарифы -->
      <section class="border-t border-white/10 py-14 sm:py-16">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
          <h2 class="text-center text-2xl font-semibold text-white sm:text-3xl">Стоимость</h2>
          <p class="mx-auto mt-2 max-w-lg text-center text-sm text-slate-400">
            Начните с бесплатного месяца — оцените, как бот разгружает переписку и запись.
          </p>
          <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div
              v-for="plan in plans"
              :key="plan.name"
              :class="[
                'flex flex-col rounded-2xl border p-6 transition',
                plan.highlight
                  ? 'border-indigo-400/40 bg-gradient-to-b from-indigo-500/20 to-indigo-950/30 ring-2 ring-indigo-400/30'
                  : 'border-white/10 bg-white/[0.03] hover:border-white/20',
              ]"
            >
              <p class="text-xs font-semibold uppercase tracking-wider text-indigo-300">{{ plan.name }}</p>
              <p class="mt-4 text-3xl font-bold text-white">{{ plan.price }}</p>
              <p class="mt-1 text-sm text-slate-400">{{ plan.period }}</p>
              <p class="mt-4 flex-1 text-xs leading-relaxed text-slate-500">{{ plan.note }}</p>
              <RouterLink
                v-if="plan.highlight"
                to="/register"
                class="mt-6 block w-full rounded-xl bg-white py-2.5 text-center text-sm font-semibold text-slate-900 hover:bg-slate-100"
              >
                Начать бесплатно
              </RouterLink>
            </div>
          </div>
        </div>
      </section>

      <!-- Финальный CTA -->
      <section class="border-t border-white/10 py-14 sm:py-16">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
          <div
            class="rounded-3xl border border-white/10 bg-gradient-to-r from-indigo-500/15 via-fuchsia-500/10 to-cyan-500/10 p-8 sm:p-10"
          >
            <div class="grid gap-8 md:grid-cols-2 md:items-center">
              <div>
                <h2 class="text-2xl font-semibold text-white sm:text-3xl">Войдите или зарегистрируйтесь</h2>
                <p class="mt-3 text-sm leading-relaxed text-slate-300 sm:text-base">
                  Попробуйте SmartBookingBot бесплатно в первый месяц: настройте услуги, график и сообщество ВК — и
                  отдайте рутину боту.
                </p>
              </div>
              <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:justify-end">
                <RouterLink
                  to="/register"
                  class="rounded-xl bg-white px-6 py-3 text-center text-sm font-semibold text-slate-900 shadow hover:bg-slate-100"
                >
                  Зарегистрироваться
                </RouterLink>
                <RouterLink
                  to="/login"
                  class="rounded-xl border border-white/25 px-6 py-3 text-center text-sm font-medium text-white hover:bg-white/5"
                >
                  Войти
                </RouterLink>
              </div>
            </div>
          </div>
          <footer class="mt-10 border-t border-white/10 pt-6 text-center text-xs text-slate-500 sm:text-left">
            SmartBookingBot — кабинет для управления записью и бот для сообщества ВКонтакте.
          </footer>
        </div>
      </section>
    </main>
  </div>
</template>
