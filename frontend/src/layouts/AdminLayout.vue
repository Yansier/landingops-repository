<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import IconBase from '@/components/IconBase.vue'
import { navigationFor } from '@/router/navigation'

const auth = useAuthStore()
const router = useRouter()
const mobileOpen = ref(false)
const roleNames = { super_admin: '总管理员', team_admin: '团队管理员', staff: '员工' }
async function logout() { await auth.logout(); await router.push('/login') }
</script>
<template>
  <div class="app-shell">
    <div v-if="mobileOpen" class="nav-scrim" @click="mobileOpen=false"/>
    <aside class="sidebar" :class="{ open: mobileOpen }">
      <div class="brand"><span class="brand-mark">L</span><div><b>LandingOps</b><small>Control plane</small></div><button class="icon-btn mobile-close" @click="mobileOpen=false"><IconBase name="close"/></button></div>
      <nav class="main-nav" aria-label="主导航">
        <RouterLink v-for="item in navigationFor(auth.user?.role || 'staff')" :key="item.to" :to="item.to" @click="mobileOpen=false"><IconBase :name="item.icon"/>{{ item.label }}</RouterLink>
      </nav>
      <div class="future-nav"><small>即将开放</small><span>模板 · 域名 · 部署任务</span><span>系统设置</span></div>
      <div class="sidebar-user">
        <span class="avatar">{{ auth.user?.display_name.slice(0,1).toUpperCase() }}</span><div><b>{{ auth.user?.display_name }}</b><small>{{ roleNames[auth.user?.role || 'staff'] }}</small></div>
        <button class="icon-btn" aria-label="退出登录" @click="logout"><IconBase name="logout"/></button>
      </div>
    </aside>
    <main class="content-shell">
      <header class="topbar"><button class="icon-btn menu-button" @click="mobileOpen=true"><IconBase name="menu"/></button><span class="environment"><i/>管理控制面</span><RouterLink to="/profile" class="profile-link"><IconBase name="profile"/>个人设置</RouterLink></header>
      <div class="page-container"><RouterView /></div>
    </main>
  </div>
</template>
