<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

onMounted(() => {
  const token = route.query.token as string | undefined
  const err = route.query.error as string | undefined
  if (token) {
    localStorage.setItem('auth_token', token)
    router.replace('/app')
  } else {
    router.replace({ name: 'login', query: { error: err || 'oauth_failed' } })
  }
})
</script>

<template>
  <p class="p-8 text-center text-slate-400">Завершение входа…</p>
</template>
