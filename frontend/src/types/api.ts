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
  manage_cloudflare: boolean
  manage_domains: boolean
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

export type VerificationStatus = 'valid' | 'unverified' | 'invalid'
export type AdminState = 'enabled' | 'disabled'
export interface CloudflareConnection {
  id: string
  label: string
  account_external_id: string | null
  token_hint: string
  status: string
  admin_state: AdminState
  verification_status: VerificationStatus
  credential_context_version: number
  last_verified_at: string | null
  last_verification_attempt_at: string | null
  last_verification_result: Record<string, unknown> | null
  version: number
  created_at: string
  updated_at: string
}
export interface CloudflareZone {
  id: string
  connection_id: string
  external_zone_id: string
  zone_name: string
  normalized_zone_name: string
  status: string
  admin_state: AdminState
  verification_status: VerificationStatus
  verified_credential_context_version: number | null
  cloudflare_status: string | null
  last_verification_result: Record<string, unknown> | null
  version: number
  connection_label: string
  connection_status: string
  connection_admin_state: AdminState
  connection_verification_status: VerificationStatus
  connection_credential_context_version: number
  last_synced_at: string | null
  created_at: string
  updated_at: string
}
export interface DomainGrant {
  team_id: string
  team_name: string
  status: 'active' | 'revoked'
  granted_at: string
  revoked_at: string | null
}
export interface PrimaryDomain {
  id: string
  zone_id: string
  domain_name: string
  normalized_domain_name: string
  domain_status: 'active' | 'disabled'
  zone_name: string
  normalized_zone_name: string
  zone_status: string
  zone_admin_state: AdminState
  zone_verification_status: VerificationStatus
  zone_verified_credential_context_version: number | null
  zone_cloudflare_status: string | null
  connection_id: string
  connection_label: string
  connection_status: string
  connection_admin_state: AdminState
  connection_verification_status: VerificationStatus
  connection_credential_context_version: number
  available: boolean
  grants: DomainGrant[]
  created_at: string
  updated_at: string
}

export interface DnsPrecheckResult {
  hostname: string
  classification: 'clear' | 'compatible' | 'needs_configuration' | 'conflict'
  records: Array<{ id: string; type: string; name: string; content: string; proxied: boolean | null; ttl: number | null }>
  confirmed_public_ipv4: string | null
  issues: Array<{ code: string; record_id: string | null; message: string }>
  limitations: string[]
  message: string
}

export interface ApiEnvelope<T> { data: T; request_id: string }
export interface ApiListEnvelope<T> extends ApiEnvelope<T[]> {
  page: { next_cursor: string | null; has_more: boolean }
}
