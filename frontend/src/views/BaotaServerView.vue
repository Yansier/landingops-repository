<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError } from '@/services/api'
import { getBaotaConfig, getBaotaIsolation, listBaotaSites, precheckBaota, saveBaotaConfig, verifyBaota } from '@/services/resources'
import type { BaotaConfig, BaotaInventory, BaotaIsolationResult, BaotaPrecheck, BaotaSite } from '@/types/api'
import IconBase from '@/components/IconBase.vue'
import ModalDialog from '@/components/ModalDialog.vue'
import PageState from '@/components/PageState.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const config=ref<BaotaConfig|null>(null),sites=ref<BaotaSite[]>([]),isolation=ref<BaotaIsolationResult|null>(null)
const inventory=ref<BaotaInventory|null>(null)
const loading=ref(true),error=ref(''),notice=ref(''),open=ref(false),busy=ref(false),formError=ref('')
const hostname=ref(''),precheck=ref<BaotaPrecheck|null>(null),precheckBusy=ref(false)
const form=reactive({base_url:'',api_key:''})
const message=(e:unknown)=>e instanceof ApiError?e.message:'操作失败，请稍后重试。'

async function load(){loading.value=true;error.value='';try{config.value=await getBaotaConfig();sites.value=[];inventory.value=null;isolation.value=null;if(config.value?.verification_status==='valid'&&config.value.admin_state==='enabled'){const results=await Promise.allSettled([listBaotaSites(),getBaotaIsolation()]);if(results[0].status==='fulfilled'){sites.value=results[0].value.sites;inventory.value=results[0].value.inventory}if(results[1].status==='fulfilled')isolation.value=results[1].value}}catch(e){error.value=message(e)}finally{loading.value=false}}
function edit(){Object.assign(form,{base_url:'',api_key:''});formError.value='';open.value=true}
async function save(){busy.value=true;formError.value='';try{const payload:{base_url?:string;api_key?:string;expected_version?:number}={};if(form.base_url)payload.base_url=form.base_url;if(form.api_key)payload.api_key=form.api_key;if(config.value)payload.expected_version=config.value.version;config.value=await saveBaotaConfig(payload);notice.value=config.value.version===1?'已登记唯一宝塔连接，尚未验证。':'连接已更新；地址或 API Key 变化会重置验证状态。';open.value=false;await load()}catch(e){formError.value=message(e)}finally{busy.value=false}}
async function setState(state:'enabled'|'disabled'){if(!config.value||!confirm(`${state==='disabled'?'停用':'重新启用'}宝塔只读连接？`))return;try{config.value=await saveBaotaConfig({admin_state:state,expected_version:config.value.version});notice.value=state==='disabled'?'连接已停用；未修改任何站点。':'连接已重新启用，必要时请重新验证。';await load()}catch(e){error.value=message(e)}}
async function verify(){if(!config.value)return;busy.value=true;error.value='';try{await verifyBaota(config.value.version);notice.value='官方白名单只读连接检查通过。';await load()}catch(e){error.value=message(e);await load()}finally{busy.value=false}}
async function runPrecheck(){precheckBusy.value=true;formError.value='';precheck.value=null;try{precheck.value=await precheckBaota(hostname.value)}catch(e){formError.value=message(e)}finally{precheckBusy.value=false}}
onMounted(load)
</script>

<template>
  <div v-if="inventory&&!inventory.complete" class="alert alert-neutral page-alert">站点清单不完整：{{inventory.reason||'只读证据不足'}}。详情查询 {{inventory.detail_queries_used}} / {{inventory.detail_query_budget}}，不能据此宣称无冲突。</div>
  <div class="page-header"><div><span class="eyebrow">Read-only protection</span><h1>宝塔服务器</h1><p>只读识别单台服务器、既有站点和冲突；不会创建、认领或修改网站。</p></div><button class="btn btn-primary" @click="edit"><IconBase name="server"/>{{config?'编辑连接':'登记连接'}}</button></div>
  <div v-if="notice" class="alert alert-success page-alert">{{notice}}</div>
  <PageState :loading="loading" :error="error" :empty="false" @retry="load">
    <section v-if="!config" class="content-card empty-state"><IconBase name="server"/><h2>尚未登记宝塔服务器</h2><p>V1 只允许一个全局连接。登记后仍需只读验证，不会自动建站。</p><button class="btn btn-primary" @click="edit">登记唯一连接</button></section>
    <template v-else>
      <section class="stat-grid">
        <article class="stat-card"><small>管理员状态</small><StatusBadge :value="config.admin_state"/><b>{{config.admin_state==='enabled'?'允许只读检查':'已停用'}}</b></article>
        <article class="stat-card"><small>验证状态</small><StatusBadge :value="config.verification_status"/><b>{{config.panel_version||'版本尚未确认'}}</b></article>
        <article class="stat-card"><small>API Key</small><b><code>{{config.api_key_hint}}</code></b><span>明文与密文均不返回</span></article>
        <article class="stat-card"><small>只读发现</small><b>{{sites.length}} 个站点</b><span>均默认视为外部资源</span></article>
      </section>
      <section class="content-card"><div class="card-heading"><div><h2>单服务器连接</h2><p><code>{{config.base_url_hint}}</code> · 最近检查 {{config.last_verified_at||config.last_verification_attempt_at||'从未执行'}}</p></div><div><button class="btn btn-secondary" :disabled="busy||config.admin_state!=='enabled'" @click="verify">只读连接检查</button> <button v-if="config.admin_state==='enabled'" class="btn btn-secondary danger-outline" @click="setState('disabled')">停用</button><button v-else class="btn btn-secondary" @click="setState('enabled')">重新启用</button></div></div><div class="security-note"><IconBase name="shield"/>只允许官方文档确认的系统、站点、域名和 PHP 信息查询；没有任意 API 执行入口。</div></section>

      <section class="content-card"><div class="card-heading"><div><h2>现有站点只读清单</h2><p>发现结果不代表 LandingOps 拥有或可以管理这些站点。</p></div></div><div v-if="!sites.length" class="empty-inline">尚无可展示的只读发现结果。</div><div v-else class="table-wrap"><table><thead><tr><th>外部站点</th><th>绑定域名</th><th>根目录</th><th>PHP</th><th>所有权判断</th></tr></thead><tbody><tr v-for="(site,index) in sites" :key="site.external_id??site.name??index"><td><b>{{site.name||'字段缺失'}}</b><small>ID {{site.external_id??'未知'}} · {{site.status??'状态未知'}}</small></td><td><span v-for="domain in site.domains" :key="domain" class="tag">{{domain}}</span><small v-if="site.domain_capability!=='supported'">域名能力：{{site.domain_capability}}</small></td><td><code>{{site.normalized_path||site.path||'无法确认'}}</code></td><td>{{site.php_version||'unsupported'}}</td><td><StatusBadge :value="site.ownership.classification"/><small v-for="reason in site.ownership.reasons" :key="reason">{{reason}}</small></td></tr></tbody></table></div></section>

      <section class="content-card"><div class="card-heading"><div><h2>候选 FQDN 冲突预检查</h2><p>只读结果不会授予写入或建站权限。</p></div></div><form class="baota-inline-form" @submit.prevent="runPrecheck"><input v-model.trim="hostname" required placeholder="page.example.com"/><button class="btn btn-primary" :disabled="precheckBusy||config.verification_status!=='valid'">检查冲突</button></form><div v-if="formError" class="alert">{{formError}}</div><div v-if="precheck" class="security-note baota-result"><b>{{precheck.classification}}：{{precheck.message}}</b><small>候选路径：{{precheck.candidate_path||'未配置'}}</small><small v-for="issue in precheck.issues" :key="issue.code+issue.site_id">{{issue.message}}</small></div></section>

      <section class="content-card"><div class="card-heading"><div><h2>运行隔离能力</h2><p>不足项不会被已有 PHP 服务自动视为安全。</p></div></div><div v-if="!isolation" class="empty-inline">验证后显示能力检查。</div><div v-else class="grant-list"><div v-for="check in isolation.checks" :key="check.key"><div><b>{{check.key}}</b><small>{{check.message}}</small></div><StatusBadge :value="check.status"/></div><div><div><b>未来 PHP 部署</b><small>{{isolation.php_deployment.message}}</small></div><StatusBadge :value="isolation.php_deployment.status"/></div></div></section>
    </template>
  </PageState>

  <ModalDialog :open="open" :title="config?'编辑宝塔连接':'登记唯一宝塔连接'" description="只保存连接配置，不发起建站或修改操作。" @close="open=false"><form class="modal-form" @submit.prevent="save"><div v-if="formError" class="alert">{{formError}}</div><label>{{config?'新面板 HTTPS Origin（留空则不修改）':'面板 HTTPS Origin'}}<input v-model.trim="form.base_url" :required="!config" placeholder="https://panel.example.com:8888"/><small>当前仅显示脱敏地址；新地址必须同时存在于服务端 LANDINGOPS_BAOTA_ALLOWED_BASE_URLS 白名单。</small></label><label>{{config?'新 API Key（留空则不轮换）':'API Key'}}<input v-model="form.api_key" :required="!config" type="password" autocomplete="new-password"/><small>使用宝塔专用 AAD 进行 AES-256-GCM 加密，永不回显。</small></label><div class="security-note"><IconBase name="shield"/>地址或密钥变化会使旧验证状态失效，但不会自动启用已停用连接。</div><footer><button type="button" class="btn btn-secondary" @click="open=false">取消</button><button class="btn btn-primary" :disabled="busy">保存连接</button></footer></form></ModalDialog>
</template>
