import { describe, expect, it } from 'vitest'
import { navigationFor } from './navigation'

describe('三级账号导航隔离', () => {
  it('总管理员可见平台管理入口', () => expect(navigationFor('super_admin').map(i=>i.to)).toEqual(['/', '/instances', '/templates', '/teams', '/users']))
  it('团队管理员不可见团队平台入口', () => expect(navigationFor('team_admin').map(i=>i.to)).toEqual(['/', '/instances', '/templates', '/users']))
  it('员工可查询授权模板但没有管理入口', () => expect(navigationFor('staff').map(i=>i.to)).toEqual(['/', '/instances', '/templates']))
})
