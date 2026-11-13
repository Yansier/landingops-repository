import { describe, expect, it } from 'vitest'
import { buildConfig, initialWizardValues } from './wizardConfig'
import type { ConfigFormField } from '@/types/api'

const fields: ConfigFormField[] = [
  {path:'links.download_url',label:'url',type:'string',required:true,nullable:true,visibility:'public'},
  {path:'events.purchase.enabled',label:'enabled',type:'boolean',required:true,nullable:false,visibility:'public'},
  {path:'pixel_ids',label:'pixels',type:'array',item_type:'string',required:true,nullable:false,visibility:'public'},
  {path:'private_note',label:'note',type:'string',required:true,nullable:false,visibility:'private'},
]

describe('部署向导配置构造',()=>{
  it('按 schema 路径构造嵌套对象并保留私有字段供后端私有配置使用',()=>{
    const values=initialWizardValues(fields)
    values['links.download_url']='https://example.test/file'; values['events.purchase.enabled']=true
    values.pixel_ids='px-1, px-2'; values.private_note='server only'
    expect(buildConfig(fields,values)).toEqual({links:{download_url:'https://example.test/file'},events:{purchase:{enabled:true}},pixel_ids:['px-1','px-2'],private_note:'server only'})
  })
})
