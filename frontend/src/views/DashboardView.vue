<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { listInstances, listTeams, listUsers } from '@/services/resources'
import { ApiError } from '@/services/api'
import type { Instance, Team, User } from '@/types/api'
import IconBase from '@/components/IconBase.vue'
import PageState from '@/components/PageState.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const auth = useAuthStore(), loading = ref(true), error = ref('')
const instances = ref<Instance[]>([]), teams = ref<Team[]>([]), users = ref<User[]>([])
const title = computed(() => auth.isSuperAdmin ? '平台总览' : auth.user?.role === 'team_admin' ? '团队总览' : '我的工作台')
const subtitle = computed(() => auth.isSuperAdmin ? '查看当前平台授权范围内的团队、用户和实例。' : auth.user?.role === 'team_admin' ? '查看本团队当前数据。' : '查看由你负责的网站实例。')
async function load() {
  loading.value=true; error.value=''
  try {
    const jobs: Promise<unknown>[] = [listInstances().then(v=>instances.value=v)]
    if (auth.isAdmin) jobs.push(listUsers().then(v=>users.value=v))
    if (auth.isSuperAdmin) jobs.push(listTeams().then(v=>teams.value=v))
    await Promise.all(jobs)
  } catch(e) { error.value=e instanceof ApiError?e.message:'数据载入失败。' } finally { loading.value=false }
}
const recent = computed(()=>instances.value.slice(0,5))
const ownerName = (id:string|null) => users.value.find(u=>u.id===id)?.display_name || (id===auth.user?.id?auth.user.display_name:'未分配')
onMounted(load)
</script>
<template><section>
  <header class="page-header"><div><span class="eyebrow">Dashboard</span><h1>{{ title }}</h1><p>{{ subtitle }}</p></div><div class="date-chip">当前授权范围</div></header>
  <PageState :loading="loading" :error="error" @retry="load">
    <div class="stat-grid">
      <article v-if="auth.isSuperAdmin" class="stat-card accent-purple"><span>团队</span><strong>{{ teams.length }}</strong><small>可管理团队</small><IconBase name="teams"/></article>
      <article v-if="auth.isAdmin" class="stat-card accent-blue"><span>用户</span><strong>{{ users.length }}</strong><small>当前可见账号</small><IconBase name="users"/></article>
      <article class="stat-card accent-green"><span>网站实例</span><strong>{{ instances.length }}</strong><small>{{ auth.user?.role==='staff'?'本人负责':'当前可见实例' }}</small><IconBase name="instances"/></article>
      <article class="stat-card accent-orange"><span>运行中</span><strong>{{ instances.filter(i=>i.status==='active').length }}</strong><small>状态为 active</small><IconBase name="shield"/></article>
    </div>
    <div class="content-card recent-card"><div class="card-heading"><div><h2>最近实例</h2><p>数据来自当前登录账号授权的实例列表。</p></div><RouterLink to="/instances" class="text-link">查看全部 <IconBase name="arrow"/></RouterLink></div>
      <div v-if="!recent.length" class="inline-empty">当前范围内还没有网站实例。</div>
      <div v-else class="table-wrap"><table><thead><tr><th>域名</th><th>状态</th><th>负责人</th><th>创建时间</th></tr></thead><tbody><tr v-for="item in recent" :key="item.id"><td><b>{{ item.fqdn }}</b></td><td><StatusBadge :value="item.status"/></td><td>{{ ownerName(item.current_owner_user_id) }}</td><td>{{ new Date(item.created_at).toLocaleDateString('zh-CN') }}</td></tr></tbody></table></div>
    </div>
  </PageState>
</section></template>
