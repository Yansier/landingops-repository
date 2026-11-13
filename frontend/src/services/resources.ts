import { api, apiList, jsonBody } from './api'
import type { Instance, ManagedTemplate, Team, TemplateUploadResult, User } from '@/types/api'

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
