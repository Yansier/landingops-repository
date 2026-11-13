<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore(), route = useRoute(), router = useRouter()
const email = ref(''), password = ref(''), error = ref('')
async function submit() {
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/'
    await router.replace(redirect)
  } catch (e) { error.value = e instanceof ApiError ? e.message : '登录失败，请稍后重试。' }
}
</script>
<template>
  <main class="login-page">
    <section class="login-intro">
      <div class="brand brand-light"><span class="brand-mark">L</span><div><b>LandingOps</b><small>Control plane</small></div></div>
      <div class="intro-copy"><span class="eyebrow">部署控制面</span><h1>让每个落地页，<br><em>清晰可控。</em></h1><p>管理团队、账号和网站实例。已部署站点始终独立运行，不依赖 LandingOps 响应访客请求。</p></div>
      <div class="boundary-note"><span>✓</span><div><b>运行时隔离</b><small>控制面离线不影响已部署网站</small></div></div>
    </section>
    <section class="login-panel"><form class="login-card" @submit.prevent="submit">
      <div><span class="eyebrow">欢迎回来</span><h2>登录管理后台</h2><p>使用管理员为你创建的账号。</p></div>
      <div v-if="route.query.reason==='api'" class="alert">管理接口当前不可用，请确认 PHP API 已启动。</div>
      <div v-if="route.query.reason==='expired'" class="alert alert-neutral">登录状态已过期，请重新登录。</div>
      <div v-if="route.query.redirect && !error" class="alert alert-neutral">登录状态已失效，请重新登录。</div>
      <div v-if="error" class="alert" role="alert">{{ error }}</div>
      <label>邮箱地址<input v-model.trim="email" type="email" autocomplete="username" required placeholder="name@example.com" /></label>
      <label>密码<input v-model="password" type="password" autocomplete="current-password" required placeholder="输入登录密码" /></label>
      <button class="btn btn-primary btn-wide" :disabled="auth.busy"><span v-if="auth.busy" class="spinner spinner-light"/>{{ auth.busy ? '正在验证…' : '安全登录' }}</button>
      <p class="secure-note">Cookie Session · CSRF 防护 · 凭据不会保存到浏览器存储</p>
    </form></section>
  </main>
</template>
