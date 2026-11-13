import { api, apiList, jsonBody } from './api'
import type { CloudflareConnection, CloudflareZone, DnsPrecheckResult, Instance, ManagedTemplate, PrimaryDomain, Team, TemplateUploadResult, User } from '@/types/api'

export const listTeams = () => apiList<Team>('/api/v1/teams')
export const createTeam = (input: { name: string; slug: string }) =>
  api<Team>('/api/v1/teams', { method: 'POST', body: jsonBody(input) })

export const listUsers = () => apiList<User>('/api/v1/users')
export const createUser = (input: {
  email: string; display_name: string; role: string; team_id: string | null; password: string
}) => api<User>('/api/v1/users', { method: 'POST', body: jsonBody(input) })
export const disableUser = (id: string) =>
  api<null>(`/api/v1/users/${id}`, { method: 'PATCH', body: jsonBody({ status: 'disabled' }) })

export const listInstances = () => apiList<Instance>('/api/v1/instances')
export const transferOwner = (instanceId: string, input: {
  to_user_id: string; expected_instance_version: number; reason: string
}) => api<Instance>(`/api/v1/instances/${instanceId}/owner-transfers`, {
  method: 'POST', body: jsonBody(input),
})

export const listTemplates = () => apiList<ManagedTemplate>('/api/v1/templates')
export const uploadTemplate = (file: File) => {
  const body = new FormData()
  body.append('template_zip', file, file.name)
  return api<TemplateUploadResult>('/api/v1/templates/uploads', { method: 'POST', body })
}
export const grantTemplate = (versionId: string, teamId: string) =>
  api<null>(`/api/v1/template-versions/${versionId}/grants`, {
    method: 'POST', body: jsonBody({ team_id: teamId }),
  })
export const revokeTemplateGrant = (versionId: string, teamId: string) =>
  api<null>(`/api/v1/template-versions/${versionId}/grants/${teamId}`, { method: 'DELETE' })

export const listCloudflareConnections = () => apiList<CloudflareConnection>('/api/v1/cloudflare-connections')
export const createCloudflareConnection = (input: { label: string; account_external_id: string | null; api_token: string }) =>
  api<CloudflareConnection>('/api/v1/cloudflare-connections', { method: 'POST', body: jsonBody(input) })
export const updateCloudflareConnection = (id: string, input: { expected_version: number } & Partial<{ label: string; account_external_id: string | null; api_token: string; admin_state: 'enabled'|'disabled' }>) =>
  api<CloudflareConnection>(`/api/v1/cloudflare-connections/${id}`, { method: 'PATCH', body: jsonBody(input) })
export const verifyCloudflareConnection = (id: string, input: { zone_id: string; expected_connection_version: number; expected_zone_version: number }) =>
  api<Record<string, unknown>>(`/api/v1/cloudflare-connections/${id}/verifications`, { method: 'POST', body: jsonBody(input) })

export const listCloudflareZones = () => apiList<CloudflareZone>('/api/v1/cloudflare-zones')
export const createCloudflareZone = (input: { connection_id: string; external_zone_id: string; zone_name: string }) =>
  api<CloudflareZone>('/api/v1/cloudflare-zones', { method: 'POST', body: jsonBody(input) })
export const updateCloudflareZone = (id: string, admin_state: 'enabled'|'disabled', expected_version: number) =>
  api<CloudflareZone>(`/api/v1/cloudflare-zones/${id}`, { method: 'PATCH', body: jsonBody({ admin_state, expected_version }) })

export const listPrimaryDomains = () => apiList<PrimaryDomain>('/api/v1/primary-domains')
export const createPrimaryDomain = (input: { zone_id: string; domain_name: string }) =>
  api<PrimaryDomain>('/api/v1/primary-domains', { method: 'POST', body: jsonBody(input) })
export const updatePrimaryDomain = (id: string, status: 'active'|'disabled') =>
  api<PrimaryDomain>(`/api/v1/primary-domains/${id}`, { method: 'PATCH', body: jsonBody({ status }) })
export const grantPrimaryDomain = (id: string, teamId: string) =>
  api<null>(`/api/v1/primary-domains/${id}/grants`, { method: 'POST', body: jsonBody({ team_id: teamId }) })
export const revokePrimaryDomainGrant = (id: string, teamId: string) =>
  api<null>(`/api/v1/primary-domains/${id}/grants/${teamId}`, { method: 'DELETE' })
export const precheckDomainDns = (id: string, hostname: string) =>
  api<DnsPrecheckResult>(`/api/v1/primary-domains/${id}/dns-prechecks`, { method: 'POST', body: jsonBody({ hostname }) })
