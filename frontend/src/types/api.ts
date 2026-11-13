export type Role = 'super_admin' | 'team_admin' | 'staff'
export type UserStatus = 'active' | 'disabled'

export interface User {
  id: string
  team_id: string | null
  email: string
  display_name: string
  role: Role
  status: UserStatus
  version?: number
  last_login_at?: string | null
  created_at?: string | null
}

export interface Team {
  id: string
  name: string
  slug: string
  status: string
  version: number
  created_at: string
  updated_at: string
}

export interface Instance {
  id: string
  team_id: string
  fqdn: string
  status: string
  version: number
  current_owner_user_id: string | null
  created_by_user_id: string
  created_at: string
  updated_at: string
}

export interface Capabilities {
  manage_teams: boolean
  manage_users: boolean
  transfer_owner: boolean
}

export interface ApiEnvelope<T> { data: T; request_id: string }
export interface ApiListEnvelope<T> extends ApiEnvelope<T[]> {
  page: { next_cursor: string | null; has_more: boolean }
}
