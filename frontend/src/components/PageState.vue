<script setup lang="ts">
defineProps<{ loading?: boolean; error?: string; empty?: boolean; emptyTitle?: string; emptyText?: string }>()
defineEmits<{ retry: [] }>()
</script>
<template>
  <div v-if="loading" class="state-card" aria-live="polite"><span class="spinner"/>正在加载真实数据…</div>
  <div v-else-if="error" class="state-card state-error" role="alert">
    <strong>暂时无法载入</strong><p>{{ error }}</p><button class="btn btn-secondary" @click="$emit('retry')">重新加载</button>
  </div>
  <div v-else-if="empty" class="state-card state-empty">
    <span class="empty-mark">∅</span><strong>{{ emptyTitle || '暂无数据' }}</strong><p>{{ emptyText }}</p><slot name="action" />
  </div>
  <slot v-else />
</template>
