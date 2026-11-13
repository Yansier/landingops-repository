import type { ApiEnvelope, ApiListEnvelope } from '@/types/api'

type UnauthorizedHandler = () => void
let csrfReader: () => string | null = () => null
let unauthorizedHandler: UnauthorizedHandler = () => undefined

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: unknown,
    public readonly requestId?: string,
  ) { super(message) }
}

export function configureApi(options: { csrf: () => string | null; unauthorized: UnauthorizedHandler }) {
  csrfReader = options.csrf
  unauthorizedHandler = options.unauthorized
}

const fallbackMessages: Record<number, string> = {
  401: '登录状态已失效，请重新登录。',
  403: '没有权限执行此操作。',
  404: '请求的资源不存在。',
  409: '数据已被其他操作更新，请刷新后重试。',
  422: '提交内容未通过校验。',
  501: '该功能尚未开放。',
}

async function requestEnvelope<T>(path: string, init: RequestInit = {}): Promise<ApiEnvelope<T>> {
  const method = (init.method ?? 'GET').toUpperCase()
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body !== undefined && !(typeof FormData !== 'undefined' && init.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
    const token = csrfReader()
    if (token) headers.set('X-CSRF-Token', token)
  }

  let response: Response
  try {
    response = await fetch(path, { ...init, method, headers, credentials: 'include' })
  } catch {
    throw new ApiError(0, 'network_error', '无法连接管理接口，请检查本地 API 是否已启动。', null)
  }

  const payload = await response.json().catch(() => null)
  if (!response.ok) {
    if (response.status === 401) unauthorizedHandler()
    throw new ApiError(
      response.status,
      payload?.error?.code ?? 'request_failed',
      payload?.error?.message ?? fallbackMessages[response.status] ?? '请求失败。',
      payload?.error?.details ?? null,
      payload?.request_id,
    )
  }
  return payload as ApiEnvelope<T>
}

export async function api<T>(path: string, init?: RequestInit): Promise<T> {
  return (await requestEnvelope<T>(path, init)).data
}

export async function apiList<T>(path: string): Promise<T[]> {
  return ((await requestEnvelope<T[]>(path)) as ApiListEnvelope<T>).data
}

export function jsonBody(value: unknown): string { return JSON.stringify(value) }
