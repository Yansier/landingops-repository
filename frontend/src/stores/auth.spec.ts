import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from './auth'

const user={id:'01ARZ3NDEKTSV4RRFFQ69G5FAV',team_id:null,email:'admin@example.test',display_name:'Admin',role:'super_admin' as const,status:'active' as const}
const response=(data:unknown,status=200)=>new Response(JSON.stringify(status===200?{data,request_id:'r'}:{error:data,request_id:'r'}),{status,headers:{'Content-Type':'application/json'}})
describe('认证状态',()=>{
  beforeEach(()=>setActivePinia(createPinia()))
  it('登录后用 /me 轮换 CSRF，登出使用新令牌',async()=>{
    const fetchMock=vi.fn().mockResolvedValueOnce(response({user,csrf_token:'login-token',expires_at:'tomorrow'})).mockResolvedValueOnce(response({user,capabilities:{manage_teams:true,manage_users:true,transfer_owner:true},csrf_token:'refresh-token'})).mockResolvedValueOnce(response(null))
    vi.stubGlobal('fetch',fetchMock);const auth=useAuthStore();await auth.login('admin@example.test','secret');expect(auth.csrfToken).toBe('refresh-token');await auth.logout();expect(auth.user).toBeNull();expect((fetchMock.mock.calls[2][1].headers as Headers).get('X-CSRF-Token')).toBe('refresh-token')
  })
  it('刷新页面通过 Cookie 恢复身份和 CSRF',async()=>{vi.stubGlobal('fetch',vi.fn().mockResolvedValue(response({user,capabilities:{manage_teams:true,manage_users:true,transfer_owner:true},csrf_token:'restored'})));const auth=useAuthStore();await auth.restore();expect(auth.user?.email).toBe(user.email);expect(auth.csrfToken).toBe('restored')})
  it('过期 Session 返回 401 后保持未登录',async()=>{vi.stubGlobal('fetch',vi.fn().mockResolvedValue(response({code:'unauthorized',message:'过期'},401)));const auth=useAuthStore();await auth.restore();expect(auth.user).toBeNull();expect(auth.initialized).toBe(true)})
})
