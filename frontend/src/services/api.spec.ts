import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, configureApi } from './api'

const response = (body: unknown, status=200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type':'application/json' } })

describe('统一 API 客户端', () => {
  beforeEach(() => configureApi({ csrf: ()=>'memory-csrf', unauthorized: vi.fn() }))

  it('写请求携带 Cookie 和内存 CSRF，不写持久存储', async () => {
    const fetchMock=vi.fn().mockResolvedValue(response({data:null,request_id:'r1'}));vi.stubGlobal('fetch',fetchMock)
    await api('/api/v1/logout',{method:'POST'})
    const init=fetchMock.mock.calls[0][1] as RequestInit
    expect(init.credentials).toBe('include')
    expect((init.headers as Headers).get('X-CSRF-Token')).toBe('memory-csrf')
  })

  it.each([[403,'forbidden'],[404,'resource_not_found'],[409,'instance_version_conflict'],[422,'input_invalid'],[501,'not_implemented']])('保留状态 %i 的服务端错误码', async (status,code) => {
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(response({error:{code,message:'明确失败',details:{}},request_id:'r2'},status as number)))
    await expect(api('/api/v1/test')).rejects.toMatchObject({status,code,message:'明确失败'})
  })

  it('401 触发 Session 清理', async () => {
    const unauthorized=vi.fn();configureApi({csrf:()=>null,unauthorized})
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(response({error:{code:'unauthorized',message:'过期'}},401)))
    await expect(api('/api/v1/me')).rejects.toBeInstanceOf(ApiError)
    expect(unauthorized).toHaveBeenCalledOnce()
  })
})
