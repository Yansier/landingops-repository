import type { Role } from '@/types/api'

export interface NavigationItem { to: string; label: string; icon: 'dashboard'|'instances'|'teams'|'users'|'template'|'domain'|'cloud'|'server'; roles: Role[] }

export const navigation: NavigationItem[] = [
  { to: '/', label: '仪表盘', icon: 'dashboard', roles: ['super_admin', 'team_admin', 'staff'] },
  { to: '/instances', label: '网站实例', icon: 'instances', roles: ['super_admin', 'team_admin', 'staff'] },
  { to: '/templates', label: '模板管理', icon: 'template', roles: ['super_admin', 'team_admin', 'staff'] },
  { to: '/domains', label: '域名管理', icon: 'domain', roles: ['super_admin', 'team_admin', 'staff'] },
  { to: '/cloudflare', label: 'Cloudflare 连接', icon: 'cloud', roles: ['super_admin'] },
  { to: '/baota', label: '宝塔服务器', icon: 'server', roles: ['super_admin'] },
  { to: '/teams', label: '团队管理', icon: 'teams', roles: ['super_admin'] },
  { to: '/users', label: '用户管理', icon: 'users', roles: ['super_admin', 'team_admin'] },
]

export const navigationFor = (role: Role) => navigation.filter(item => item.roles.includes(role))
