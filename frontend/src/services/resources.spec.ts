import { beforeEach, describe, expect, it, vi } from 'vitest'
import { configureApi } from './api'
import { createUser, disableUser, listInstances, transferOwner } from './resources'

const ok=(data:unknown)=>new Response(JSON.stringify({data,request_id:'req'}),{status:200,headers:{'Content-Type':'application/json'}})
describe('业务请求契约',()=>{
  const fetchMock=vi.fn()
  beforeEach(()=>{fetchMock.mockReset();vi.stubGlobal('fetch',fetchMock);configureApi({csrf:()=>'token',unauthorized:vi.fn()})})
  it('用户创建和停用严格使用现有接口',async()=>{
    fetchMock.mockResolvedValueOnce(ok({id:'u'})).mockResolvedValueOnce(ok(null))
    await createUser({email:'staff@example.test',display_name:'员工',role:'staff',team_id:'team',password:'long password 123'})
    await disableUser('01ARZ3NDEKTSV4RRFFQ69G5FAV')
    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/users')
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({role:'staff',team_id:'team'})
    expect(fetchMock.mock.calls[1][0]).toContain('/users/01ARZ3NDEKTSV4RRFFQ69G5FAV')
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({status:'disabled'})
  })
  it('负责人转交携带乐观锁版本',async()=>{
    fetchMock.mockResolvedValue(ok({version:8}))
    await transferOwner('01ARZ3NDEKTSV4RRFFQ69G5FAV',{to_user_id:'01ARZ3NDEKTSV4RRFFQ69G5FAA',expected_instance_version:7,reason:'轮班'})
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({expected_instance_version:7,reason:'轮班'})
  })
  it('空数据库实例列表返回正式空数组',async()=>{fetchMock.mockResolvedValue(ok([]));await expect(listInstances()).resolves.toEqual([])})
})
