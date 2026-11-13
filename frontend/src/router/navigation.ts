import type { Role } from '@/types/api'

export interface NavigationItem { to: string; label: string; icon: 'dashboard'|'instances'|'teams'|'users'; roles: Role[] }

export const navigation: NavigationItem[] = [
  { to: '/', label: '仪表盘', icon: 'dashboard', roles: ['super_admin', 'team_admin', 'staff'] },
  { to: '/instances', label: '网站实例', icon: 'instances', roles: ['super_admin', 'team_admin', 'staff'] },
  { to: '/teams', label: '团队管理', icon: 'teams', roles: ['super_admin'] },
  { to: '/users', label: '用户管理', icon: 'users', roles: ['super_admin', 'team_admin'] },
]

export const navigationFor = (role: Role) => navigation.filter(item => item.roles.includes(role))
