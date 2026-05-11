<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="min-h-screen bg-gradient-to-b from-slate-950 via-indigo-950/40 to-slate-950">
    <header class="sticky top-0 z-30 border-b border-white/10 bg-slate-950/70 backdrop-blur">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
        <div class="flex items-center gap-3">
          <div class="grid h-9 w-9 place-items-center rounded-xl bg-indigo-500/15 ring-1 ring-indigo-400/20">
            <span class="text-sm font-semibold text-indigo-200">SB</span>
          </div>
          <span class="text-base font-semibold tracking-tight text-white">SmartBookingBot</span>
        </div>
        <div class="flex items-center gap-3">
          <RouterLink to="/login" class="rounded-lg px-4 py-2 text-sm text-slate-300 transition hover:text-white">
            Войти
          </RouterLink>
          <RouterLink
            to="/register"
            class="rounded-lg bg-indigo-500 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-indigo-500/30 transition hover:bg-indigo-400"
          >
            Попробовать бесплатно
          </RouterLink>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-6">
      <section class="grid items-center gap-10 py-16 md:grid-cols-2 md:py-20">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.22em] text-indigo-300">AI‑секретарь в VK‑сообществе</p>
          <h1 class="mt-4 text-4xl font-bold leading-tight text-white md:text-5xl">
            Запись клиентов без хаоса в переписках и&nbsp;двойных броней
          </h1>
          <p class="mt-6 text-lg leading-relaxed text-slate-300">
            SmartBookingBot отвечает клиентам в личных сообщениях сообщества, подбирает свободные слоты по вашему графику и
            фиксирует записи в календаре. Вы управляете услугами, ценами и рабочими часами из кабинета — бот делает рутину.
          </p>
          <div class="mt-10 flex flex-wrap items-center gap-3">
            <RouterLink
              to="/register"
              class="rounded-xl bg-white px-6 py-3 text-sm font-semibold text-slate-900 shadow hover:bg-slate-100"
            >
              Начать бесплатно
            </RouterLink>
            <RouterLink
              to="/login"
              class="rounded-xl border border-white/20 px-6 py-3 text-sm font-medium text-white hover:bg-white/5"
            >
              Войти в кабинет
            </RouterLink>
            <p class="w-full pt-2 text-xs text-slate-500">
              Подходит для специалистов услуг: бьюти, ремонт, обучение, консультации — когда запись идёт через VK.
            </p>
          </div>
        </div>

        <div class="relative">
          <div
            class="absolute -inset-6 rounded-[2rem] bg-gradient-to-tr from-indigo-500/20 via-fuchsia-500/10 to-cyan-500/10 blur-2xl"
          />
          <div class="relative rounded-3xl border border-white/10 bg-slate-950/40 p-6 shadow-2xl">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="text-xs font-medium text-slate-400">Кабинет</p>
                <p class="mt-1 text-base font-semibold text-white">Календарь, логи, уведомления</p>
              </div>
              <div class="rounded-xl bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-200 ring-1 ring-emerald-400/15">
                Без пересечений
              </div>
            </div>
            <div class="mt-6 grid gap-3">
              <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-indigo-300">Услуги</p>
                <p class="mt-1 text-sm text-slate-300">Название, длительность, цена — бот учитывает это при записи.</p>
              </div>
              <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-indigo-300">Рабочие часы</p>
                <p class="mt-1 text-sm text-slate-300">График по дням недели → предложения клиенту только по свободным слотам.</p>
              </div>
              <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-indigo-300">Контроль</p>
                <p class="mt-1 text-sm text-slate-300">Лог действий бота и уведомления — видно, что происходило в диалогах.</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="border-t border-white/10 py-14 md:py-16">
        <div class="grid gap-6 md:grid-cols-3">
          <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
            <h3 class="text-base font-semibold text-white">Проблема: «я всегда онлайн»</h3>
            <p class="mt-2 text-sm leading-relaxed text-slate-400">
              Клиенты пишут вечером и в выходные, задают одни и те же вопросы, ждут ответа — а вы теряете фокус и силы.
            </p>
          </div>
          <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
            <h3 class="text-base font-semibold text-white">Проблема: ручная запись = ошибки</h3>
            <p class="mt-2 text-sm leading-relaxed text-slate-400">
              Переносы, отмены, пересечения, «а вы точно меня записали?» — всё это съедает время и ухудшает опыт клиента.
            </p>
          </div>
          <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
            <h3 class="text-base font-semibold text-white">Решение: бот + календарь</h3>
            <p class="mt-2 text-sm leading-relaxed text-slate-400">
              Бот помогает довести диалог до результата, а записи фиксируются в календаре и не конфликтуют по времени.
            </p>
          </div>
        </div>
      </section>

      <section class="border-t border-white/10 py-14 md:py-16">
        <div class="grid gap-10 md:grid-cols-2">
          <div>
            <h2 class="text-2xl font-semibold text-white">Как это работает</h2>
            <p class="mt-3 text-sm leading-relaxed text-slate-400">
              Вы настраиваете услуги и график, подключаете VK‑сообщество — дальше бот отвечает и аккуратно ведёт записи.
            </p>
            <ol class="mt-6 space-y-4 text-sm text-slate-300">
              <li class="flex gap-3">
                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-lg bg-indigo-500/15 text-xs font-semibold text-indigo-200 ring-1 ring-indigo-400/20"
                  >1</span
                >
                <div>
                  <p class="font-medium text-white">Заполняете кабинет</p>
                  <p class="mt-1 text-slate-400">Услуги (длительность/цена) и рабочие часы по дням недели.</p>
                </div>
              </li>
              <li class="flex gap-3">
                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-lg bg-indigo-500/15 text-xs font-semibold text-indigo-200 ring-1 ring-indigo-400/20"
                  >2</span
                >
                <div>
                  <p class="font-medium text-white">Подключаете VK‑сообщество</p>
                  <p class="mt-1 text-slate-400">
                    Бот принимает сообщения сообщества через callback и ведёт диалоги от имени сообщества.
                  </p>
                </div>
              </li>
              <li class="flex gap-3">
                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-lg bg-indigo-500/15 text-xs font-semibold text-indigo-200 ring-1 ring-indigo-400/20"
                  >3</span
                >
                <div>
                  <p class="font-medium text-white">Получаете записи без пересечений</p>
                  <p class="mt-1 text-slate-400">
                    Клиент подтверждает дату/время — запись создаётся, отмены фиксируются, а вы видите всё в календаре.
                  </p>
                </div>
              </li>
            </ol>
          </div>

          <div class="rounded-3xl border border-white/10 bg-slate-950/40 p-6">
            <h3 class="text-base font-semibold text-white">Что уже есть в продукте</h3>
            <div class="mt-5 grid gap-3 sm:grid-cols-2">
              <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-sm font-medium text-white">Услуги и цены</p>
                <p class="mt-1 text-xs text-slate-400">Список услуг, длительность, стоимость.</p>
              </div>
              <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-sm font-medium text-white">Рабочие часы</p>
                <p class="mt-1 text-xs text-slate-400">График по дням → слот‑менеджмент.</p>
              </div>
              <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-sm font-medium text-white">Календарь записей</p>
                <p class="mt-1 text-xs text-slate-400">Неделя/месяц, создание и правки.</p>
              </div>
              <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-sm font-medium text-white">Логи и уведомления</p>
                <p class="mt-1 text-xs text-slate-400">Прозрачность действий бота.</p>
              </div>
              <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-sm font-medium text-white">Авторизация</p>
                <p class="mt-1 text-xs text-slate-400">Email/пароль, вход через VK ID и Яндекс.</p>
              </div>
              <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-sm font-medium text-white">Оплата и баланс</p>
                <p class="mt-1 text-xs text-slate-400">Пополнение и абонплата, есть триал‑период.</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="border-t border-white/10 py-14 md:py-16">
        <div class="grid gap-10 md:grid-cols-2">
          <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
            <h2 class="text-2xl font-semibold text-white">Интеграция с VK</h2>
            <p class="mt-3 text-sm leading-relaxed text-slate-400">
              Бот подключается к вашему сообществу и работает там, где уже находятся клиенты. Подходит, если основная
              запись идёт через личные сообщения сообщества.
            </p>
            <ul class="mt-5 space-y-2 text-sm text-slate-300">
              <li class="flex gap-2">
                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-300" />
                Подключение сообщества через callback (ID группы, токен, секрет, строка подтверждения).
              </li>
              <li class="flex gap-2">
                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-300" />
                История диалога и сообщения доступны в карточке записи — проще разбирать спорные моменты.
              </li>
            </ul>
          </div>
          <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
            <h2 class="text-2xl font-semibold text-white">Контроль и предсказуемость</h2>
            <p class="mt-3 text-sm leading-relaxed text-slate-400">
              Никакой «магии без следов»: действия фиксируются, записи не пересекаются, а в кабинете видно состояние.
            </p>
            <ul class="mt-5 space-y-2 text-sm text-slate-300">
              <li class="flex gap-2">
                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-300" />
                Защита от пересечений по времени при создании и редактировании записей.
              </li>
              <li class="flex gap-2">
                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-300" />
                Лог событий: создание/отмена, системные действия, важные состояния.
              </li>
              <li class="flex gap-2">
                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-300" />
                Уведомления в кабинете (и опционально пуш‑уведомления браузера).
              </li>
            </ul>
          </div>
        </div>
      </section>

      <section class="border-t border-white/10 py-14 md:py-16">
        <div class="rounded-3xl border border-white/10 bg-slate-950/40 p-8">
          <div class="grid items-start gap-8 md:grid-cols-2">
            <div>
              <h2 class="text-2xl font-semibold text-white">Частые вопросы</h2>
              <p class="mt-2 text-sm text-slate-400">Коротко о том, что обычно важно перед стартом.</p>
            </div>
            <div class="space-y-3">
              <details class="group rounded-2xl border border-white/10 bg-white/5 p-5">
                <summary class="cursor-pointer list-none text-sm font-medium text-white">
                  Сколько времени занимает настройка?
                </summary>
                <p class="mt-2 text-sm text-slate-400">
                  Обычно достаточно заполнить услуги и график, затем подключить VK‑сообщество в профиле. Дальше можно
                  начинать принимать заявки.
                </p>
              </details>
              <details class="group rounded-2xl border border-white/10 bg-white/5 p-5">
                <summary class="cursor-pointer list-none text-sm font-medium text-white">
                  Можно ли править записи вручную?
                </summary>
                <p class="mt-2 text-sm text-slate-400">
                  Да — в календаре можно создавать записи, редактировать время, услугу, статус и цену.
                </p>
              </details>
              <details class="group rounded-2xl border border-white/10 bg-white/5 p-5">
                <summary class="cursor-pointer list-none text-sm font-medium text-white">
                  Что с оплатой?
                </summary>
                <p class="mt-2 text-sm text-slate-400">
                  В продукте предусмотрены баланс и пополнение, а также абонплата после триал‑периода.
                </p>
              </details>
            </div>
          </div>
        </div>
      </section>

      <section class="py-14 md:py-16">
        <div class="rounded-3xl border border-white/10 bg-gradient-to-r from-indigo-500/15 via-fuchsia-500/10 to-cyan-500/10 p-8">
          <div class="grid gap-6 md:grid-cols-2 md:items-center">
            <div>
              <h2 class="text-2xl font-semibold text-white">Хотите перестать «жить в переписках»?</h2>
              <p class="mt-2 text-sm leading-relaxed text-slate-300">
                Создайте аккаунт, настройте услуги и график — и подключите сообщество VK. Дальше бот возьмёт на себя
                ответы и запись.
              </p>
            </div>
            <div class="flex flex-wrap gap-3 md:justify-end">
              <RouterLink
                to="/register"
                class="rounded-xl bg-white px-6 py-3 text-sm font-semibold text-slate-900 shadow hover:bg-slate-100"
              >
                Начать бесплатно
              </RouterLink>
              <RouterLink
                to="/login"
                class="rounded-xl border border-white/20 px-6 py-3 text-sm font-medium text-white hover:bg-white/5"
              >
                Войти
              </RouterLink>
            </div>
          </div>
        </div>
        <footer class="mx-auto mt-10 max-w-6xl border-t border-white/10 pt-6 text-xs text-slate-500">
          SmartBookingBot — кабинет для управления записью и бот для VK‑сообщества.
        </footer>
      </section>
    </main>
  </div>
</template>
