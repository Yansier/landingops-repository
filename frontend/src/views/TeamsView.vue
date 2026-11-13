<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { createTeam, listTeams, listUsers } from '@/services/resources'
import { ApiError } from '@/services/api'
import type { Team, User } from '@/types/api'
import PageState from '@/components/PageState.vue'; import StatusBadge from '@/components/StatusBadge.vue'; import ModalDialog from '@/components/ModalDialog.vue'; import IconBase from '@/components/IconBase.vue'
const teams=ref<Team[]>([]), users=ref<User[]>([]), loading=ref(true), error=ref(''), modal=ref(false), saving=ref(false), formError=ref('')
const form=ref({name:'',slug:''})
const counts=computed(()=>Object.fromEntries(teams.value.map(t=>[t.id,users.value.filter(u=>u.team_id===t.id).length])))
async function load(){loading.value=true;error.value='';try{[teams.value,users.value]=await Promise.all([listTeams(),listUsers()])}catch(e){error.value=e instanceof ApiError?e.message:'团队载入失败。'}finally{loading.value=false}}
async function submit(){saving.value=true;formError.value='';try{await createTeam(form.value);modal.value=false;form.value={name:'',slug:''};await load()}catch(e){formError.value=e instanceof ApiError?e.message:'创建失败。'}finally{saving.value=false}}
onMounted(load)
</script>
<template><section><header class="page-header"><div><span class="eyebrow">Organization</span><h1>团队管理</h1><p>平台级团队及所属用户，仅总管理员可访问。</p></div><button class="btn btn-primary" @click="modal=true"><IconBase name="plus"/>创建团队</button></header>
<PageState :loading="loading" :error="error" :empty="!teams.length" empty-title="还没有团队" empty-text="创建第一个团队后，即可为其分配团队管理员和员工。" @retry="load"><template #action><button class="btn btn-primary" @click="modal=true">创建团队</button></template>
<div class="content-card"><div class="table-wrap"><table><thead><tr><th>团队</th><th>Slug</th><th>成员</th><th>状态</th><th>创建时间</th></tr></thead><tbody><tr v-for="team in teams" :key="team.id"><td><div class="identity-cell"><span class="team-glyph">{{ team.name.slice(0,1) }}</span><b>{{ team.name }}</b></div></td><td><code>{{ team.slug }}</code></td><td>{{ counts[team.id] || 0 }} 人</td><td><StatusBadge :value="team.status"/></td><td>{{ new Date(team.created_at).toLocaleDateString('zh-CN') }}</td></tr></tbody></table></div></div></PageState>
<ModalDialog :open="modal" title="创建团队" description="团队 Slug 创建后用于稳定标识，建议使用小写英文和短横线。" @close="modal=false"><form class="modal-form" @submit.prevent="submit"><div v-if="formError" class="alert">{{ formError }}</div><label>团队名称<input v-model.trim="form.name" required maxlength="120" placeholder="例如：增长一组"/></label><label>团队 Slug<input v-model.trim="form.slug" required pattern="[a-z0-9][a-z0-9-]{1,78}[a-z0-9]" placeholder="growth-team"/></label><footer><button type="button" class="btn btn-secondary" @click="modal=false">取消</button><button class="btn btn-primary" :disabled="saving">{{ saving?'创建中…':'确认创建' }}</button></footer></form></ModalDialog></section></template>
