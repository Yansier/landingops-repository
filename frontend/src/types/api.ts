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

export interface ValidationIssue { code: string; message: string; path: string | null }
export interface ValidationReport { valid: boolean; errors: ValidationIssue[]; warnings: ValidationIssue[] }
export interface TemplateGrant {
  team_id: string
  team_name: string
  status: 'active' | 'revoked'
  granted_at: string
  revoked_at: string | null
}
export interface TemplateVersion {
  id: string
  version: string
  standard_version: string
  zip_sha256: string
  validation_status: 'pending' | 'valid' | 'invalid'
  validation_report: ValidationReport
  uploaded_by: { id: string; display_name: string }
  published_at: string | null
  created_at: string
  available: boolean
  grants: TemplateGrant[]
}
export interface ManagedTemplate {
  id: string
  slug: string
  name: string
  description: string
  status: string
  owner_team: { id: string; name: string } | null
  created_by: { id: string; display_name: string }
  created_at: string
  versions: TemplateVersion[]
}
export interface TemplateUploadResult {
  template_id: string
  template_version_id: string
  slug: string
  version: string
  standard_version: string
  zip_sha256: string
  validation_status: 'valid'
  validation_report: ValidationReport
}

export interface ApiEnvelope<T> { data: T; request_id: string }
export interface ApiListEnvelope<T> extends ApiEnvelope<T[]> {
  page: { next_cursor: string | null; has_more: boolean }
}
