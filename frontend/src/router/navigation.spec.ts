import { describe, expect, it } from 'vitest'
import { navigationFor } from './navigation'

describe('三级账号导航隔离', () => {
  it('总管理员可见平台、Cloudflare 与宝塔只读管理入口', () => expect(navigationFor('super_admin').map(i=>i.to)).toEqual(['/', '/instances', '/templates', '/domains', '/cloudflare', '/baota', '/teams', '/users']))
  it('团队管理员可见获授权域名但不可见连接入口', () => expect(navigationFor('team_admin').map(i=>i.to)).toEqual(['/', '/instances', '/templates', '/domains', '/users']))
  it('员工可查询授权模板和当前可用域名', () => expect(navigationFor('staff').map(i=>i.to)).toEqual(['/', '/instances', '/templates', '/domains']))
})
