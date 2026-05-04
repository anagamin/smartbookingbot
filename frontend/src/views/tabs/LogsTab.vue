<script setup lang="ts">
import http from '@/api/http'
import { onMounted, ref } from 'vue'

const logs = ref<Array<{ id: number; type: string; summary: string; created_at: string; read_at: string | null }>>([])

async function load() {
  const { data } = await http.get('/activity-logs')
  logs.value = data
}

async function markRead(id: number) {
  await http.patch(`/activity-logs/${id}/read`)
  await load()
}

onMounted(load)
</script>

<template>
  <div>
    <h2 class="text-xl font-semibold text-white">Лог действий</h2>
    <ul class="mt-6 space-y-3">
      <li
        v-for="l in logs"
        :key="l.id"
        class="rounded-lg border border-white/10 bg-slate-900/40 px-4 py-3 text-sm"
        :class="l.read_at ? 'opacity-60' : ''"
      >
        <div class="flex justify-between gap-4">
          <div>
            <span class="text-xs uppercase text-indigo-300">{{ l.type }}</span>
            <p class="mt-1 text-slate-200">{{ l.summary }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ l.created_at }}</p>
          </div>
          <button
            v-if="!l.read_at"
            type="button"
            class="shrink-0 text-xs text-indigo-300 hover:underline"
            @click="markRead(l.id)"
          >
            Прочитано
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>
