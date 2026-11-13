<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError } from '@/services/api'
import { createCloudflareConnection, listCloudflareConnections, updateCloudflareConnection } from '@/services/resources'
import type { CloudflareConnection } from '@/types/api'
import IconBase from '@/components/IconBase.vue'
import ModalDialog from '@/components/ModalDialog.vue'
import PageState from '@/components/PageState.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const items=ref<CloudflareConnection[]>([]),loading=ref(true),error=ref(''),notice=ref(''),open=ref(false),busy=ref(false),formError=ref('')
const editing=ref<CloudflareConnection|null>(null)
const form=reactive({label:'',account_external_id:'',api_token:''})
const message=(e:unknown)=>e instanceof ApiError?e.message:'操作失败，请稍后重试。'
async function load(){loading.value=true;error.value='';try{items.value=await listCloudflareConnections()}catch(e){error.value=message(e)}finally{loading.value=false}}
function create(){editing.value=null;Object.assign(form,{label:'',account_external_id:'',api_token:''});formError.value='';open.value=true}
function rotate(item:CloudflareConnection){editing.value=item;Object.assign(form,{label:item.label,account_external_id:item.account_external_id||'',api_token:''});formError.value='';open.value=true}
async function save(){busy.value=true;formError.value='';try{if(editing.value){await updateCloudflareConnection(editing.value.id,{label:form.label,account_external_id:form.account_external_id||null,...(form.api_token?{api_token:form.api_token}:{})});notice.value='连接元数据已更新；Token 如有轮换仍为未验证。'}else{await createCloudflareConnection({label:form.label,account_external_id:form.account_external_id||null,api_token:form.api_token});notice.value='连接已安全登记，尚未调用 Cloudflare 验证。'}open.value=false;await load()}catch(e){formError.value=message(e)}finally{busy.value=false}}
async function disable(item:CloudflareConnection){if(!confirm(`停用连接“${item.label}”？新域名使用将被阻止。`))return;try{await updateCloudflareConnection(item.id,{status:'disabled'});notice.value='连接已停用，未修改任何 DNS。';await load()}catch(e){error.value=message(e)}}
onMounted(load)
</script>
<template>
  <div class="page-header"><div><span class="eyebrow">Infrastructure registry</span><h1>Cloudflare 连接</h1><p>仅登记加密 Token 与连接元数据；本阶段不调用 Cloudflare API。</p></div><button class="btn btn-primary" @click="create"><IconBase name="plus"/>登记连接</button></div>
  <div v-if="notice" class="alert alert-success page-alert">{{notice}}</div>
  <PageState :loading="loading" :error="error" :empty="!items.length" empty-title="暂无 Cloudflare 连接" empty-text="登记后状态仍为未验证，不会产生 DNS 变更。" @retry="load">
    <div class="content-card table-wrap"><table><thead><tr><th>连接</th><th>账号标识</th><th>Token</th><th>验证状态</th><th>最近验证</th><th class="align-right">操作</th></tr></thead><tbody><tr v-for="item in items" :key="item.id"><td><b>{{item.label}}</b><small class="mono-id">{{item.id}}</small></td><td>{{item.account_external_id||'未填写'}}</td><td><code>{{item.token_hint}}</code><small>原始 Token 永不返回</small></td><td><StatusBadge :value="item.status"/></td><td>{{item.last_verified_at||'从未验证'}}</td><td class="align-right"><button class="btn-link" @click="rotate(item)">编辑 / 轮换</button><button v-if="item.status!=='disabled'" class="btn-link danger" @click="disable(item)">停用</button></td></tr></tbody></table></div>
  </PageState>
  <ModalDialog :open="open" :title="editing?'编辑连接':'登记 Cloudflare 连接'" description="Token 将使用环境主密钥进行 AES-256-GCM 认证加密。" @close="open=false"><form class="modal-form" @submit.prevent="save"><div v-if="formError" class="alert">{{formError}}</div><label>连接名称<input v-model.trim="form.label" required maxlength="120"/></label><label>Cloudflare 账号标识（可选）<input v-model.trim="form.account_external_id" maxlength="128"/></label><label>{{editing?'新 API Token（留空则不轮换）':'API Token'}}<input v-model="form.api_token" :required="!editing" type="password" autocomplete="new-password"/><small>Token 不会回显，也不会写入浏览器持久存储。</small></label><div class="security-note"><IconBase name="cloud"/>连接保存后保持“未验证”，直到未来真实 Cloudflare 验证流程完成。</div><footer><button type="button" class="btn btn-secondary" @click="open=false">取消</button><button class="btn btn-primary" :disabled="busy"><span v-if="busy" class="spinner spinner-light"/>保存</button></footer></form></ModalDialog>
</template>
