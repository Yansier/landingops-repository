import { describe, expect, it } from 'vitest'
import { logoutDestination } from './logoutFlow'

describe('退出失败路由', () => {
  it('服务器请求失败仍返回登录页并标记仅本地退出', () => {
    expect(logoutDestination(false)).toEqual({ name: 'login', query: { reason: 'logout_local' } })
  })
})
