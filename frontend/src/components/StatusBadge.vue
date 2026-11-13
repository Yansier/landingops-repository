<script setup lang="ts">defineProps<{ value: string }>()</script>
<template><span class="badge" :class="`badge-${value}`"><span/>{{ ({active:'正常',disabled:'已停用',draft:'草稿',deploying:'部署中',failed:'失败',archived:'已归档'} as Record<string,string>)[value] || value }}</span></template>
