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
  manage_baota: boolean
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
  classification: 'wildcard_covered'|'matched_server'|'other_server'|'conflict'|'needs_attention'|'unsupported'
  dns_strategy: 'reuse_existing_wildcard'|'manual_dns_prerequisite'
  dns_write_required: false
  dns_records_to_create: 0
  dns_records_to_update: 0
  dns_records_to_delete: 0
  applicable_wildcard: string | null
  records: { exact: DnsRecord[]; wildcard: DnsRecord[]; delegations: DnsRecord[] }
  confirmed_public_ipv4: string | null
  issues: Array<{ code: string; record_id: string | null; message: string }>
  limitations: string[]
  message: string
}
export interface DnsRecord { id: string; type: string; name: string; content: string; proxied: boolean | null; ttl: number | null }
export interface CloudflareZoneDiscovery {
  classification: 'complete'|'incomplete'|'manual_registration_required'
  zones: Array<{ id:string; name:string; status:string; account_id:string|null; classification:'matched_server'|'other_server'|'needs_attention'|'unsupported'; wildcard_record_count:number|null; already_registered:boolean; team_grants_created:0 }>
  manual_registration_available: true
  write_operations_performed: false
  observed_at?: string
}

export interface BaotaConfig {
  id: string
  base_url_hint: string
  api_key_hint: string
  status: string
  admin_state: AdminState
  verification_status: VerificationStatus
  panel_version: string | null
  capabilities: Record<string, string> | null
  last_verified_at: string | null
  last_verification_attempt_at: string | null
  last_verification_result: Record<string, unknown> | null
  version: number
  created_at: string
  updated_at: string
}

export interface BaotaSite {
  external_id: number | null
  name: string | null
  path: string | null
  normalized_path: string | null
  status: string | null
  metadata_complete: boolean
  domains: string[]
  domain_capability: string
  php_capability: string
  php_version: string | null
  ownership: { classification: 'external'|'unverified'|'conflict'|'managed'; instance_id: string | null; reasons: string[] }
}
export interface BaotaInventory {
  complete: boolean
  listing_complete: boolean
  details_complete: boolean
  reason: string | null
  pages: number
  site_limit: number
  detail_query_budget: number
  detail_queries_used: number
  detail_failures: number
}
export interface BaotaSiteDiscovery { sites: BaotaSite[]; inventory: BaotaInventory; batch?: { offset:number; limit:number; processed:number; complete:boolean; next_offset:number|null; total_sites:number; observed_at:string; write_operations_performed:false } }
export interface BaotaSiteDetailBatch extends BaotaSiteDiscovery { batch: { offset:number; limit:number; processed:number; complete:boolean; next_offset:number|null; total_sites:number; observed_at:string; write_operations_performed:false } }

export interface BaotaPrecheck {
  hostname: string
  candidate_path: string | null
  classification: 'clear'|'conflict'|'needs_attention'|'unsupported'
  issues: Array<{ code: string; message: string; site_id: number | null }>
  directory_observation: Record<string, unknown>
  message: string
}

export interface BaotaIsolationResult {
  checks: Array<{ key: string; status: string; message: string }>
  php_deployment: { status: 'disabled'; message: string }
  mutations_performed: false
}

export interface ConfigFormField {
  path: string
  label: string
  description?: string
  type: 'string'|'number'|'integer'|'boolean'|'array'
  item_type?: 'string'|'number'|'integer'|'boolean'
  item_enum?: unknown[]
  item_pattern?: string
  item_minimum?: number
  item_maximum?: number
  item_min_length?: number
  item_max_length?: number
  required: boolean
  conditionally_required?: boolean
  optional_ancestors?: string[]
  nullable: boolean
  visibility: 'public'|'private'
  format?: string
  pattern?: string
  minimum?: number
  maximum?: number
  min_length?: number
  max_length?: number
  min_items?: number
  max_items?: number
  enum?: unknown[]
  default?: unknown
}
export interface ConfigFormContract { template_version_id: string; schema_hash: string; fields: ConfigFormField[] }
export interface DryRunIssue { code: string; message: string }
export interface DeploymentDryRun {
  status: 'blocked'|'needs_attention'|'unsupported'|'ready_for_test_deployment'
  dry_run: true
  mutations_performed: false
  template: { id: string; slug: string; name: string; version_id: string; version: string; zip_sha256: string }
  domain: { primary_domain_id: string; fqdn: string; authorization: 'valid' }
  configuration: { valid: boolean; errors: ValidationIssue[]; warnings: ValidationIssue[]; schema_hash: string; submitted_field_count: number; values_returned: false }
  external_checks: { baota: Record<string, unknown>; dns: Record<string, unknown> }
  plan: { target_path: string | null; steps: Array<{ order: number; kind: string; action: string; execute: false }>; dns: Record<string, unknown>; baota: Record<string, unknown>; files: Record<string, unknown>; ssl: Record<string, unknown> }
  blockers: DryRunIssue[]
  risks: DryRunIssue[]
  unsupported: DryRunIssue[]
  disclaimer: string
}

export interface ApiEnvelope<T> { data: T; request_id: string }
export interface ApiListEnvelope<T> extends ApiEnvelope<T[]> {
  page: { next_cursor: string | null; has_more: boolean }
}
