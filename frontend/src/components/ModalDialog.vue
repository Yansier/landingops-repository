<script setup lang="ts">
defineProps<{ open: boolean; title: string; description?: string }>()
defineEmits<{ close: [] }>()
</script>
<template>
  <Teleport to="body"><div v-if="open" class="modal-backdrop" @click.self="$emit('close')">
    <section class="modal" role="dialog" aria-modal="true" :aria-label="title">
      <header><div><h2>{{ title }}</h2><p v-if="description">{{ description }}</p></div><button class="icon-btn" aria-label="关闭" @click="$emit('close')"><IconBase name="close"/></button></header>
      <slot />
    </section>
  </div></Teleport>
</template>
<script lang="ts">import IconBase from './IconBase.vue'; export default { components: { IconBase } }</script>
