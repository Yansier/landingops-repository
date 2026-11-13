<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/services/api'
import { grantTemplate, listTeams, listTemplates, revokeTemplateGrant, uploadTemplate } from '@/services/resources'
import type { ManagedTemplate, Team, TemplateVersion, ValidationIssue } from '@/types/api'
import IconBase from '@/components/IconBase.vue'
import ModalDialog from '@/components/ModalDialog.vue'
import PageState from '@/components/PageState.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const auth=useAuthStore(),templates=ref<ManagedTemplate[]>([]),teams=ref<Team[]>([]),loading=ref(true),error=ref(''),notice=ref('')
const uploadOpen=ref(false),uploading=ref(false),uploadError=ref(''),validationIssues=ref<ValidationIssue[]>([]),file=ref<File|null>(null)
const grantVersion=ref<TemplateVersion|null>(null),grantBusy=ref(''),grantError=ref('')
const canUpload=computed(()=>auth.isAdmin)
const activeGrantIds=computed(()=>new Set((grantVersion.value?.grants||[]).filter(g=>g.status==='active').map(g=>g.team_id)))

async function load(){loading.value=true;error.value='';try{templates.value=await listTemplates();if(auth.isSuperAdmin)teams.value=await listTeams()}catch(e){error.value=e instanceof ApiError?e.message:'模板载入失败。'}finally{loading.value=false}}
function chooseFile(event:Event){const input=event.target as HTMLInputElement;file.value=input.files?.[0]||null;uploadError.value='';validationIssues.value=[]}
function openUpload(){file.value=null;uploadError.value='';validationIssues.value=[];uploadOpen.value=true}
async function submitUpload(){if(!file.value){uploadError.value='请选择一个 ZIP 文件。';return}if(file.value.size>30*1024*1024){uploadError.value='ZIP 不能超过 30 MiB。';return}uploading.value=true;uploadError.value='';validationIssues.value=[];try{const result=await uploadTemplate(file.value);uploadOpen.value=false;notice.value=`模板 ${result.slug} v${result.version} 已验证并保存。`;await load()}catch(e){if(e instanceof ApiError){uploadError.value=e.message;const details=e.details as {validation?:{errors?:ValidationIssue[]}}|null;validationIssues.value=details?.validation?.errors||[]}else uploadError.value='上传失败。'}finally{uploading.value=false}}
function openGrants(version:TemplateVersion){grantVersion.value=version;grantError.value=''}
async function setGrant(teamId:string, active:boolean){if(!grantVersion.value)return;grantBusy.value=teamId;grantError.value='';try{if(active)await grantTemplate(grantVersion.value.id,teamId);else await revokeTemplateGrant(grantVersion.value.id,teamId);await load();const latest=templates.value.flatMap(t=>t.versions).find(v=>v.id===grantVersion.value?.id);grantVersion.value=latest||null;notice.value=active?'团队授权已生效。':'团队授权已撤销，仅影响未来新实例。'}catch(e){grantError.value=e instanceof ApiError?e.message:'授权操作失败。'}finally{grantBusy.value=''}}
const shortHash=(hash:string)=>`${hash.slice(0,12)}…${hash.slice(-8)}`
onMounted(load)
</script>

<template><section>
  <header class="page-header"><div><span class="eyebrow">Template library</span><h1>模板管理</h1><p>{{ auth.user?.role==='staff'?'仅显示团队当前获授权使用的模板版本。':'上传并管理 LTS v1.0 模板版本，不提供截图或视觉预览。' }}</p></div><button v-if="canUpload" class="btn btn-primary" @click="openUpload"><IconBase name="upload"/>上传 ZIP</button></header>
  <div v-if="notice" class="alert alert-success page-alert">{{ notice }}</div>
  <PageState :loading="loading" :error="error" :empty="!templates.length" empty-title="暂无可用模板" :empty-text="auth.user?.role==='staff'?'当前团队还没有有效模板授权。':'上传一个符合 LTS v1.0 的 ZIP 后，模板版本会显示在这里。'" @retry="load">
    <template #action><button v-if="canUpload" class="btn btn-primary" @click="openUpload">上传第一个模板</button></template>
    <div class="template-grid"><article v-for="template in templates" :key="template.id" class="content-card template-card">
      <header class="template-heading"><span class="template-icon"><IconBase name="template"/></span><div><div class="template-title"><h2>{{ template.name }}</h2><StatusBadge :value="template.status"/></div><code>{{ template.slug }}</code><p>{{ template.description }}</p></div></header>
      <div class="template-meta"><span><small>所属团队</small>{{ template.owner_team?.name||'平台模板' }}</span><span><small>创建人</small>{{ template.created_by.display_name }}</span><span><small>版本数</small>{{ template.versions.length }}</span></div>
      <div class="version-list"><article v-for="version in template.versions" :key="version.id" class="version-row">
        <div class="version-main"><div><strong>v{{ version.version }}</strong><span class="standard-chip">LTS {{ version.standard_version }}</span><StatusBadge :value="version.validation_status"/></div><p>SHA-256 <code :title="version.zip_sha256">{{ shortHash(version.zip_sha256) }}</code></p></div>
        <div class="version-facts"><span><small>上传者</small>{{ version.uploaded_by.display_name }}</span><span><small>发布时间</small>{{ version.published_at?new Date(version.published_at).toLocaleString('zh-CN'):'—' }}</span></div>
        <details v-if="version.validation_report.warnings.length||version.validation_report.errors.length" class="validation-details"><summary>验证报告 · {{ version.validation_report.errors.length }} 错误 / {{ version.validation_report.warnings.length }} 警告</summary><ul><li v-for="issue in [...version.validation_report.errors,...version.validation_report.warnings]" :key="issue.code+issue.path"><b>{{ issue.code }}</b> {{ issue.message }} <code v-if="issue.path">{{ issue.path }}</code></li></ul></details>
        <div class="version-footer"><span class="availability" :class="{available:version.available}"><i/>{{ version.available?'当前可用':'当前不可用' }}</span><button v-if="auth.isSuperAdmin" class="btn btn-secondary btn-small" @click="openGrants(version)">管理团队授权</button></div>
      </article></div>
    </article></div>
  </PageState>

  <ModalDialog :open="uploadOpen" title="上传模板 ZIP" description="文件将进入 Web Root 之外的私有目录，并通过 LTS v1.0 完整校验。" @close="uploadOpen=false"><form class="modal-form" @submit.prevent="submitUpload">
    <div v-if="uploadError" class="alert">{{ uploadError }}</div><ul v-if="validationIssues.length" class="validation-errors"><li v-for="issue in validationIssues" :key="issue.code+issue.path"><b>{{ issue.code }}</b><span>{{ issue.message }}</span><code v-if="issue.path">{{ issue.path }}</code></li></ul>
    <label class="file-drop"><input type="file" accept=".zip,application/zip" @change="chooseFile"/><IconBase name="upload"/><b>{{ file?.name||'选择一个模板 ZIP' }}</b><span>{{ file?`${(file.size/1024/1024).toFixed(2)} MiB`:'单次仅允许 1 个文件，最大 30 MiB' }}</span></label>
    <div class="security-note"><IconBase name="shield"/><span>服务器会检查路径穿越、危险文件、链接、Manifest、Schema、文件数量和解压大小。验证失败不会创建有效版本。</span></div>
    <footer><button type="button" class="btn btn-secondary" @click="uploadOpen=false">取消</button><button class="btn btn-primary" :disabled="uploading||!file"><span v-if="uploading" class="spinner spinner-light"/>{{ uploading?'上传并验证中…':'上传并验证' }}</button></footer>
  </form></ModalDialog>

  <ModalDialog :open="!!grantVersion" :title="`团队授权 · v${grantVersion?.version||''}`" description="授权只控制未来创建新实例；撤销不会修改已有实例。" @close="grantVersion=null"><div class="modal-form">
    <div v-if="grantError" class="alert">{{ grantError }}</div><div class="grant-list"><div v-for="team in teams" :key="team.id"><span class="team-glyph">{{ team.name.slice(0,1) }}</span><div><b>{{ team.name }}</b><small>{{ team.slug }}</small></div><button v-if="activeGrantIds.has(team.id)" class="btn btn-secondary btn-small danger-outline" :disabled="grantBusy===team.id" @click="setGrant(team.id,false)">{{ grantBusy===team.id?'处理中…':'撤销' }}</button><button v-else class="btn btn-primary btn-small" :disabled="grantBusy===team.id" @click="setGrant(team.id,true)">{{ grantBusy===team.id?'处理中…':'授权' }}</button></div></div>
    <footer><button class="btn btn-secondary" @click="grantVersion=null">完成</button></footer>
  </div></ModalDialog>
</section></template>
