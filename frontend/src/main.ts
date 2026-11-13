import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './styles/main.css'

window.addEventListener('landingops:unauthorized', () => {
  if (router.currentRoute.value.name !== 'login') {
    void router.replace({ name: 'login', query: { reason: 'expired' } })
  }
})

createApp(App).use(createPinia()).use(router).mount('#app')
