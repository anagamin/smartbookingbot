<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="min-h-screen bg-gradient-to-b from-slate-950 via-indigo-950/40 to-slate-950">
    <header class="border-b border-white/10">
      <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
        <span class="text-lg font-semibold tracking-tight text-white">SmartBookingBot</span>
        <div class="flex gap-3">
          <RouterLink
            to="/login"
            class="rounded-lg px-4 py-2 text-sm text-slate-300 transition hover:text-white"
          >
            Войти
          </RouterLink>
          <RouterLink
            to="/register"
            class="rounded-lg bg-indigo-500 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-indigo-500/30 transition hover:bg-indigo-400"
          >
            Регистрация
          </RouterLink>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-20">
      <p class="text-sm font-medium uppercase tracking-widest text-indigo-300">AI-секретарь для самозанятых</p>
      <h1 class="mt-4 max-w-3xl text-4xl font-bold leading-tight text-white md:text-5xl">
        Записывайте клиентов из VK без хаоса в переписках и двойных броней
      </h1>
      <p class="mt-6 max-w-2xl text-lg text-slate-300">
        Бот слушает сообщения сообщества, понимает намерение, отвечает на типовые вопросы и ведёт календарь так,
        чтобы пересечений не было. Вы видите занятость и лог действий в одном кабинете.
      </p>

      <div class="mt-12 grid gap-6 md:grid-cols-3">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-6 backdrop-blur">
          <h3 class="font-semibold text-white">Боль: вы всегда «на связи»</h3>
          <p class="mt-2 text-sm text-slate-400">
            Клиенты пишут вечером и в выходные. Постоянно переключаться — выгорание.
          </p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-6 backdrop-blur">
          <h3 class="font-semibold text-white">Календарь и конфликты</h3>
          <p class="mt-2 text-sm text-slate-400">
            Критичные действия делает Laravel: проверка слотов, запись, отмена, уведомления.
          </p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-6 backdrop-blur">
          <h3 class="font-semibold text-white">Прозрачная монетизация</h3>
          <p class="mt-2 text-sm text-slate-400">
            Баланс и абонплата через ЮKassa. Первый месяц — бесплатно, дальше фиксированная сумма.
          </p>
        </div>
      </div>

      <div class="mt-14 flex flex-wrap gap-4">
        <RouterLink
          to="/register"
          class="rounded-xl bg-white px-6 py-3 text-sm font-semibold text-slate-900 shadow hover:bg-slate-100"
        >
          Попробовать бесплатно
        </RouterLink>
        <RouterLink
          to="/login"
          class="rounded-xl border border-white/20 px-6 py-3 text-sm font-medium text-white hover:bg-white/5"
        >
          Уже есть аккаунт
        </RouterLink>
      </div>
    </main>
  </div>
</template>
