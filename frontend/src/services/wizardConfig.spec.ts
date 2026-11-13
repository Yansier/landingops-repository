import { describe, expect, it } from 'vitest'
import { buildConfig, initialWizardValues, WizardConfigError } from './wizardConfig'
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

  it('可空数字保持 null，且可空 URL 可以从值恢复为 null',()=>{
    const nullable: ConfigFormField[] = [
      {path:'amount',label:'amount',type:'number',required:true,nullable:true,visibility:'public'},
      {path:'url',label:'url',type:'string',required:true,nullable:true,visibility:'public'},
    ]
    const values=initialWizardValues(nullable)
    expect(buildConfig(nullable,values)).toEqual({amount:null,url:null})
    values.amount='12.50'; values.url='https://example.test/file'
    expect(buildConfig(nullable,values)).toEqual({amount:12.5,url:'https://example.test/file'})
    values.url=null
    expect(buildConfig(nullable,values)).toEqual({amount:12.5,url:null})
  })

  it('严格解析整数而不截断，并接受完整数字语法',()=>{
    const numeric: ConfigFormField[] = [
      {path:'count',label:'count',type:'integer',required:true,nullable:false,visibility:'public'},
      {path:'ratio',label:'ratio',type:'number',required:true,nullable:false,visibility:'public'},
    ]
    expect(()=>buildConfig(numeric,{count:'1.9',ratio:'2'})).toThrow(WizardConfigError)
    expect(()=>buildConfig(numeric,{count:'12abc',ratio:'2'})).toThrow('不能包含小数或尾随字符')
    expect(buildConfig(numeric,{count:'12',ratio:'1.25e2'})).toEqual({count:12,ratio:125})
  })

  it('按 items 类型转换数组并拒绝非法布尔和小数整数',()=>{
    const arrays: ConfigFormField[] = [
      {path:'strings',label:'s',type:'array',item_type:'string',item_pattern:'^[a-z]+$',required:true,nullable:false,visibility:'public'},
      {path:'numbers',label:'n',type:'array',item_type:'number',item_minimum:0,required:true,nullable:false,visibility:'public'},
      {path:'integers',label:'i',type:'array',item_type:'integer',required:true,nullable:false,visibility:'public'},
      {path:'flags',label:'b',type:'array',item_type:'boolean',required:true,nullable:false,visibility:'public'},
    ]
    expect(buildConfig(arrays,{strings:'a,b',numbers:'1.5\n2',integers:'1,2',flags:'true,false'}))
      .toEqual({strings:['a','b'],numbers:[1.5,2],integers:[1,2],flags:[true,false]})
    expect(()=>buildConfig(arrays,{strings:[],numbers:[],integers:'1.2',flags:[]})).toThrow(WizardConfigError)
    expect(()=>buildConfig(arrays,{strings:[],numbers:[],integers:[],flags:'yes'})).toThrow(WizardConfigError)
    expect(()=>buildConfig(arrays,{strings:'UPPER',numbers:[],integers:[],flags:[]})).toThrow('pattern')
    expect(()=>buildConfig(arrays,{strings:[],numbers:'-1',integers:[],flags:[]})).toThrow('minimum')
  })

  it('可选字段默认完全省略，必填字段缺失时报错',()=>{
    const optional: ConfigFormField[] = [
      {path:'optional_note',label:'note',type:'string',required:false,nullable:false,visibility:'private'},
      {path:'required_note',label:'required',type:'string',required:true,nullable:false,visibility:'private'},
    ]
    const values=initialWizardValues(optional)
    values.required_note='ok'
    expect(buildConfig(optional,values)).toEqual({required_note:'ok'})
    expect(()=>buildConfig(optional,{optional_note:'x'})).toThrow('必填字段不可省略')
  })

  it('可选对象未使用时省略，启用其中字段后执行条件必填检查',()=>{
    const nested: ConfigFormField[] = [
      {path:'group.count',label:'count',type:'integer',required:false,conditionally_required:true,optional_ancestors:['group'],nullable:false,visibility:'public'},
      {path:'group.note',label:'note',type:'string',required:false,optional_ancestors:['group'],nullable:false,visibility:'public'},
    ]
    expect(buildConfig(nested,initialWizardValues(nested))).toEqual({})
    expect(()=>buildConfig(nested,{'group.note':'enabled'})).toThrow('group.count')
    expect(buildConfig(nested,{'group.note':'enabled','group.count':'2'})).toEqual({group:{note:'enabled',count:2}})
  })
})
