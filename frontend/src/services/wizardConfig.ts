import type { ConfigFormField } from '@/types/api'

export type WizardValues = Record<string, unknown>

export class WizardConfigError extends Error {
  constructor(public readonly path: string, message: string) {
    super(`${path}：${message}`)
    this.name = 'WizardConfigError'
  }
}

export function defaultWizardValue(field: ConfigFormField, preferNull = true): unknown {
  if (field.default !== undefined) return structuredClone(field.default)
  if (field.nullable && preferNull) return null
  if (field.type === 'boolean') return false
  if (field.type === 'array') return []
  return ''
}

export function initialWizardValues(fields: ConfigFormField[]): WizardValues {
  return Object.fromEntries(fields.map(field => [field.path, field.required ? defaultWizardValue(field) : undefined]))
}

export function buildConfig(fields: ConfigFormField[], values: WizardValues): Record<string, unknown> {
  const result: Record<string, unknown> = {}
  const activeParents = new Set<string>()
  for (const field of fields) {
    if (values[field.path] === undefined) continue
    for (const parent of field.optional_ancestors ?? []) activeParents.add(parent)
  }
  for (const field of fields) {
    if (!(field.path in values) || values[field.path] === undefined) {
      const parentActive = (field.optional_ancestors ?? []).every(parent => activeParents.has(parent))
      if ((field.required || field.conditionally_required) && parentActive) throw new WizardConfigError(field.path, '必填字段不可省略。')
      continue
    }
    const value = validateValue(field, convertValue(field, values[field.path]))
    const parts = field.path.split('.')
    let cursor = result
    for (const part of parts.slice(0, -1)) {
      const current = cursor[part]
      if (!current || typeof current !== 'object' || Array.isArray(current)) cursor[part] = {}
      cursor = cursor[part] as Record<string, unknown>
    }
    cursor[parts.at(-1)!] = value
  }
  return result
}

function convertValue(field: ConfigFormField, value: unknown): unknown {
  if (value === null) {
    if (field.nullable) return null
    throw new WizardConfigError(field.path, '该字段不允许 null。')
  }
  if (field.type === 'string') {
    if (typeof value !== 'string') throw new WizardConfigError(field.path, '必须是字符串。')
    return value
  }
  if (field.type === 'number') return parseNumber(field.path, value, false)
  if (field.type === 'integer') return parseNumber(field.path, value, true)
  if (field.type === 'boolean') {
    if (typeof value !== 'boolean') throw new WizardConfigError(field.path, '必须是布尔值。')
    return value
  }
  const items = typeof value === 'string'
    ? value.split(/[\n,]/).map(item => item.trim()).filter(Boolean)
    : value
  if (!Array.isArray(items) || !field.item_type) {
    throw new WizardConfigError(field.path, '数组值或 items 类型无效。')
  }
  return items.map((item, index) => convertArrayItem(field, item, index))
}

function parseNumber(path: string, value: unknown, integer: boolean): number {
  if (typeof value === 'number') {
    if (!Number.isFinite(value) || (integer && !Number.isSafeInteger(value))) {
      throw new WizardConfigError(path, integer ? '必须是安全整数。' : '必须是有限数字。')
    }
    return value
  }
  if (typeof value !== 'string' || value === '') {
    throw new WizardConfigError(path, integer ? '必须填写完整整数。' : '必须填写数字。')
  }
  const grammar = integer ? /^-?(?:0|[1-9]\d*)$/ : /^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/
  if (!grammar.test(value)) {
    throw new WizardConfigError(path, integer ? '整数格式无效，不能包含小数或尾随字符。' : '数字格式无效。')
  }
  const parsed = Number(value)
  if (!Number.isFinite(parsed) || (integer && !Number.isSafeInteger(parsed))) {
    throw new WizardConfigError(path, integer ? '整数超出安全范围。' : '数字超出可用范围。')
  }
  return parsed
}

function convertArrayItem(field: ConfigFormField, item: unknown, index: number): unknown {
  const path = `${field.path}[${index}]`
  let converted: unknown
  if (field.item_type === 'string') {
    if (typeof item !== 'string') throw new WizardConfigError(path, '必须是字符串。')
    converted = item
  } else if (field.item_type === 'number') {
    converted = parseNumber(path, item, false)
  } else if (field.item_type === 'integer') {
    converted = parseNumber(path, item, true)
  } else {
    if (typeof item === 'boolean') converted = item
    else if (typeof item === 'string' && /^(true|false)$/i.test(item)) converted = item.toLowerCase() === 'true'
    else throw new WizardConfigError(path, '必须是 true 或 false。')
  }
  if (field.item_enum && !field.item_enum.some(option => Object.is(option, converted))) {
    throw new WizardConfigError(path, '值不在 Schema 允许范围内。')
  }
  if (typeof converted === 'string') {
    const length = [...converted].length
    if (field.item_min_length !== undefined && length < field.item_min_length) throw new WizardConfigError(path, '字符串短于 Schema 限制。')
    if (field.item_max_length !== undefined && length > field.item_max_length) throw new WizardConfigError(path, '字符串超过 Schema 限制。')
    if (field.item_pattern && !matchesPattern(field.item_pattern, converted, path)) throw new WizardConfigError(path, '字符串不符合 Schema pattern。')
  }
  if (typeof converted === 'number') {
    if (field.item_minimum !== undefined && converted < field.item_minimum) throw new WizardConfigError(path, '数值低于 Schema minimum。')
    if (field.item_maximum !== undefined && converted > field.item_maximum) throw new WizardConfigError(path, '数值高于 Schema maximum。')
  }
  return converted
}

function validateValue(field: ConfigFormField, value: unknown): unknown {
  if (value === null) return value
  if (field.enum && !field.enum.some(option => Object.is(option, value))) throw new WizardConfigError(field.path, '值不在 Schema 允许范围内。')
  if (typeof value === 'string') {
    const length = [...value].length
    if (field.min_length !== undefined && length < field.min_length) throw new WizardConfigError(field.path, '字符串短于 Schema 限制。')
    if (field.max_length !== undefined && length > field.max_length) throw new WizardConfigError(field.path, '字符串超过 Schema 限制。')
    if (field.pattern && !matchesPattern(field.pattern, value, field.path)) throw new WizardConfigError(field.path, '字符串不符合 Schema pattern。')
    if (field.format === 'uri') {
      try { new URL(value) } catch { throw new WizardConfigError(field.path, '必须是有效的绝对 URI。') }
    }
  }
  if (typeof value === 'number') {
    if (field.minimum !== undefined && value < field.minimum) throw new WizardConfigError(field.path, '数值低于 Schema minimum。')
    if (field.maximum !== undefined && value > field.maximum) throw new WizardConfigError(field.path, '数值高于 Schema maximum。')
  }
  if (Array.isArray(value)) {
    if (field.min_items !== undefined && value.length < field.min_items) throw new WizardConfigError(field.path, '数组项目少于 Schema 限制。')
    if (field.max_items !== undefined && value.length > field.max_items) throw new WizardConfigError(field.path, '数组项目超过 Schema 限制。')
  }
  return value
}

function matchesPattern(pattern: string, value: string, path: string): boolean {
  try { return new RegExp(pattern, 'u').test(value) }
  catch { throw new WizardConfigError(path, 'Schema pattern 无法由浏览器安全解释。') }
}
