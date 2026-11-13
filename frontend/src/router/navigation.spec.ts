import { describe, expect, it } from 'vitest'
import { navigationFor } from './navigation'

describe('三级账号导航隔离', () => {
  it('总管理员可见平台管理入口', () => expect(navigationFor('super_admin').map(i=>i.to)).toEqual(['/', '/instances', '/teams', '/users']))
  it('团队管理员不可见团队平台入口', () => expect(navigationFor('team_admin').map(i=>i.to)).toEqual(['/', '/instances', '/users']))
  it('员工只可见工作台与本人实例', () => expect(navigationFor('staff').map(i=>i.to)).toEqual(['/', '/instances']))
})
