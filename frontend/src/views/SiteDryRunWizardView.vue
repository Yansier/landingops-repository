<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError } from '@/services/api'
import { createDeploymentDryRun, getTemplateConfigForm, listPrimaryDomains, listTemplates } from '@/services/resources'
import { buildConfig, defaultWizardValue, initialWizardValues, WizardConfigError } from '@/services/wizardConfig'
import type { ConfigFormContract, DeploymentDryRun, ManagedTemplate, PrimaryDomain, TemplateVersion } from '@/types/api'
import PageState from '@/components/PageState.vue'
import StatusBadge from '@/components/StatusBadge.vue'

type VersionChoice = TemplateVersion & { templateId:string; templateName:string; slug:string }
const step=ref(1),loading=ref(true),busy=ref(false),error=ref('')
const templates=ref<ManagedTemplate[]>([]),domains=ref<PrimaryDomain[]>([]),formContract=ref<ConfigFormContract|null>(null),result=ref<DeploymentDryRun|null>(null)
const selectedVersionId=ref(''),selectedDomainId=ref(''),prefix=ref('')
const values=reactive<Record<string, any>>({})
const versions=computed<VersionChoice[]>(()=>templates.value.flatMap(template=>template.versions.filter(v=>v.available&&v.validation_status==='valid'&&template.status==='active').map(v=>({...v,templateId:template.id,templateName:template.name,slug:template.slug}))))
const usableDomains=computed(()=>domains.value.filter(domain=>domain.available))
const selectedDomain=computed(()=>usableDomains.value.find(domain=>domain.id===selectedDomainId.value))
const fqdn=computed(()=>prefix.value&&selectedDomain.value?`${prefix.value.toLowerCase().replace(/^\.+|\.+$/g,'')}.${selectedDomain.value.normalized_domain_name}`:'')
const message=(e:unknown)=>e instanceof ApiError||e instanceof WizardConfigError?e.message:'预演请求失败，请稍后重试。'

async function load(){loading.value=true;error.value='';try{[templates.value,domains.value]=await Promise.all([listTemplates(),listPrimaryDomains()])}catch(e){error.value=message(e)}finally{loading.value=false}}
async function nextFromTemplate(){if(!selectedVersionId.value)return;busy.value=true;error.value='';try{formContract.value=await getTemplateConfigForm(selectedVersionId.value);Object.assign(values,initialWizardValues(formContract.value.fields));step.value=2}catch(e){error.value=message(e)}finally{busy.value=false}}
function nextFromDomain(){if(!selectedDomainId.value||!prefix.value)return;step.value=3}
async function dryRun(){if(!formContract.value)return;busy.value=true;error.value='';result.value=null;try{result.value=await createDeploymentDryRun({template_version_id:selectedVersionId.value,primary_domain_id:selectedDomainId.value,subdomain_prefix:prefix.value,config:buildConfig(formContract.value.fields,values)});step.value=4}catch(e){error.value=message(e)}finally{busy.value=false}}
function isIncluded(path:string){return values[path]!==undefined}
function toggleIncluded(field:ConfigFormContract['fields'][number]){values[field.path]=isIncluded(field.path)?undefined:defaultWizardValue(field)}
function setNull(path:string){values[path]=null}
function editNullable(field:ConfigFormContract['fields'][number]){values[field.path]=defaultWizardValue(field,false)}
function reset(){step.value=1;selectedVersionId.value='';selectedDomainId.value='';prefix.value='';formContract.value=null;result.value=null;for(const key of Object.keys(values))delete values[key]}
onMounted(load)
</script>

<template>
  <section>
    <header class="page-header"><div><span class="eyebrow">Safe dry run</span><h1>单站创建预演</h1><p>选择不可变模板版本与已授权域名，生成部署计划；本流程不会创建实例、DNS、站点或文件。</p></div><RouterLink class="btn btn-secondary" to="/instances">返回实例</RouterLink></header>
    <nav class="wizard-steps" aria-label="预演步骤"><span v-for="(label,index) in ['模板版本','主域名','站点配置','预演报告']" :key="label" :class="{active:step===index+1,done:step>index+1}"><i>{{index+1}}</i>{{label}}</span></nav>
    <div v-if="error" class="alert page-alert">{{error}}</div>
    <PageState :loading="loading" :error="''" :empty="false">
      <section v-if="step===1" class="content-card wizard-card"><div class="card-heading"><div><h2>1. 固定模板版本</h2><p>只显示当前账号可用、验证有效且未撤销授权的版本。</p></div></div><div class="wizard-body"><div v-if="!versions.length" class="empty-inline">当前没有可用于新实例的模板版本。</div><label v-for="version in versions" :key="version.id" class="choice-card" :class="{selected:selectedVersionId===version.id}"><input v-model="selectedVersionId" type="radio" :value="version.id"/><span><b>{{version.templateName}} · {{version.version}}</b><small>{{version.slug}} · LTS {{version.standard_version}}</small><code>{{version.id}}</code></span><StatusBadge value="valid"/></label><footer><button class="btn btn-primary" :disabled="!selectedVersionId||busy" @click="nextFromTemplate">下一步</button></footer></div></section>

      <section v-else-if="step===2" class="content-card wizard-card"><div class="card-heading"><div><h2>2. 选择主域名</h2><p>仅使用当前有效授权及可信 Cloudflare 验证上下文。</p></div></div><div class="wizard-body"><label>主域名<select v-model="selectedDomainId" required><option value="" disabled>选择当前可用主域名</option><option v-for="domain in usableDomains" :key="domain.id" :value="domain.id">{{domain.normalized_domain_name}}</option></select></label><label>子域名前缀<input v-model.trim="prefix" required pattern="[A-Za-z0-9.-]+" placeholder="offer"/><small>后端会重新规范化并检查实例、预留及宝塔冲突。</small></label><div class="fqdn-preview"><small>预期 FQDN</small><b>{{fqdn||'等待选择'}}</b></div><footer><button class="btn btn-secondary" @click="step=1">上一步</button><button class="btn btn-primary" :disabled="!selectedDomainId||!prefix" @click="nextFromDomain">下一步</button></footer></div></section>

      <section v-else-if="step===3&&formContract" class="content-card wizard-card"><div class="card-heading"><div><h2>3. 按模板 Schema 编辑配置</h2><p>字段来自固定版本的 LTS v1.0 Schema；private 字段只进入私有配置，不进入公开 JavaScript。</p></div><code>{{formContract.schema_hash.slice(0,12)}}…</code></div><form class="wizard-body config-fields" @submit.prevent="dryRun"><label v-for="field in formContract.fields" :key="field.path"><span>{{field.label}} <em>{{field.visibility}}</em><button v-if="!field.required" type="button" class="field-action" @click="toggleIncluded(field)">{{isIncluded(field.path)?'省略此字段':'填写此字段'}}</button></span><template v-if="isIncluded(field.path)"><div v-if="field.nullable&&values[field.path]===null" class="nullable-state">当前值为 null <button type="button" class="field-action" @click="editNullable(field)">填写值</button></div><template v-else><select v-if="field.enum" v-model="values[field.path]" :required="field.required"><option v-for="option in field.enum" :key="String(option)" :value="option">{{option}}</option></select><input v-else-if="field.type==='boolean'" v-model="values[field.path]" type="checkbox" class="toggle-input"/><textarea v-else-if="field.type==='array'" v-model="values[field.path]" rows="3" :required="field.required" :placeholder="`每行或逗号分隔一个 ${field.item_type||'值'}`"/><input v-else v-model="values[field.path]" :type="field.type==='number'||field.type==='integer'?'number':field.format==='uri'?'url':'text'" :step="field.type==='integer'?'1':field.type==='number'?'any':undefined" :required="field.required&&!field.nullable" :min="field.minimum" :max="field.maximum" :minlength="field.min_length" :maxlength="field.max_length" :pattern="field.pattern"/><button v-if="field.nullable" type="button" class="field-action null-action" @click="setNull(field.path)">恢复为 null</button></template></template><small>{{field.path}}<template v-if="!field.required"> · 可选，可完全省略</template></small></label><footer><button type="button" class="btn btn-secondary" @click="step=2">上一步</button><button class="btn btn-primary" :disabled="busy">{{busy?'校验中…':'生成部署预演'}}</button></footer></form></section>

      <section v-else-if="step===4&&result" class="wizard-report"><div class="report-hero" :class="`report-${result.status}`"><div><span class="eyebrow">Dry run result</span><h2>{{result.domain.fqdn}}</h2><p>{{result.disclaimer}}</p></div><StatusBadge :value="result.status"/></div><div class="report-grid"><article class="content-card"><h3>固定输入</h3><dl><div><dt>模板</dt><dd>{{result.template.name}} v{{result.template.version}}</dd></div><div><dt>版本 ID</dt><dd><code>{{result.template.version_id}}</code></dd></div><div><dt>目标目录</dt><dd><code>{{result.plan.target_path||'尚未配置'}}</code></dd></div><div><dt>配置</dt><dd>{{result.configuration.valid?'Schema 校验通过':'Schema 校验失败'}} · 不回显值</dd></div></dl></article><article class="content-card"><h3>阻断与风险</h3><div v-if="!result.blockers.length" class="empty-inline compact">无配置级阻断；仍需处理下列风险。</div><ul class="issue-list"><li v-for="issue in result.blockers" :key="issue.code" class="blocking"><b>{{issue.code}}</b>{{issue.message}}</li><li v-for="issue in result.risks" :key="issue.code"><b>{{issue.code}}</b>{{issue.message}}</li></ul></article></div><section class="content-card plan-card"><div class="card-heading"><div><h2>结构化部署计划</h2><p>所有步骤 execute=false，本阶段不会执行。</p></div></div><ol><li v-for="item in result.plan.steps" :key="item.order"><i>{{item.order}}</i><div><b>{{item.action}}</b><small>{{item.kind}} · execute={{item.execute}}</small></div></li></ol></section><footer class="report-actions"><button class="btn btn-secondary" @click="reset">重新预演</button><RouterLink class="btn btn-primary" to="/instances">完成并返回</RouterLink></footer></section>
    </PageState>
  </section>
</template>
