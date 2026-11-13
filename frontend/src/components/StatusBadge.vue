<script setup lang="ts">defineProps<{ value: string }>()</script>
<template><span class="badge" :class="`badge-${value}`"><span/>{{ ({active:'正常',enabled:'已启用',unverified:'未验证',valid:'验证通过',invalid:'验证失败',pending:'验证中',revoked:'已撤销',disabled:'已停用',draft:'草稿',deploying:'部署中',failed:'失败',archived:'已归档'} as Record<string,string>)[value] || value }}</span></template>
