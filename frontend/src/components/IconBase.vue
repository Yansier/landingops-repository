<script setup lang="ts">
defineProps<{ name: 'dashboard'|'instances'|'teams'|'users'|'template'|'profile'|'logout'|'menu'|'close'|'plus'|'arrow'|'shield'|'upload' }>()
</script>
<template>
  <svg viewBox="0 0 24 24" aria-hidden="true" class="icon" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
    <template v-if="name==='dashboard'"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></template>
    <template v-else-if="name==='instances'"><rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01"/></template>
    <template v-else-if="name==='teams'"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></template>
    <template v-else-if="name==='template'"><path d="M4 3h16v18H4zM8 7h8M8 11h8M8 15h5"/></template>
    <template v-else-if="name==='users'"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></template>
    <template v-else-if="name==='profile'"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="9" r="3"/><path d="M6.8 19a6 6 0 0 1 10.4 0"/></template>
    <template v-else-if="name==='logout'"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></template>
    <template v-else-if="name==='menu'"><path d="M4 6h16M4 12h16M4 18h16"/></template>
    <template v-else-if="name==='close'"><path d="M6 6l12 12M18 6L6 18"/></template>
    <template v-else-if="name==='plus'"><path d="M12 5v14M5 12h14"/></template>
    <template v-else-if="name==='upload'"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></template>
    <template v-else-if="name==='arrow'"><path d="M5 12h14M13 6l6 6-6 6"/></template>
    <template v-else><path d="M12 3l8 4v5c0 5-3.4 8-8 9-4.6-1-8-4-8-9V7l8-4z"/><path d="M9 12l2 2 4-4"/></template>
  </svg>
</template>
