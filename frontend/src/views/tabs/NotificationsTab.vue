<script setup lang="ts">
import http from '@/api/http'
import { onMounted, ref } from 'vue'

const items = ref<Array<{ id: number; title: string; body: string | null; read_at: string | null; created_at: string }>>([])

async function load() {
  const { data } = await http.get('/notifications')
  items.value = data
}

async function markRead(id: number) {
  await http.patch(`/notifications/${id}/read`)
  await load()
}

onMounted(load)
</script>

<template>
  <div>
    <h2 class="text-xl font-semibold text-white">Уведомления</h2>
    <ul class="mt-6 space-y-3">
      <li
        v-for="n in items"
        :key="n.id"
        class="rounded-lg border border-white/10 bg-slate-900/40 px-4 py-3"
        :class="n.read_at ? 'opacity-60' : ''"
      >
        <div class="flex justify-between gap-4">
          <div>
            <h3 class="font-medium text-white">{{ n.title }}</h3>
            <p v-if="n.body" class="mt-1 text-sm text-slate-300">{{ n.body }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ n.created_at }}</p>
          </div>
          <button
            v-if="!n.read_at"
            type="button"
            class="shrink-0 text-xs text-indigo-300 hover:underline"
            @click="markRead(n.id)"
          >
            Ок
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>
