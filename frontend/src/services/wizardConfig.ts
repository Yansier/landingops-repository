import type { ConfigFormField } from '@/types/api'

export type WizardValues = Record<string, unknown>

export function initialWizardValues(fields: ConfigFormField[]): WizardValues {
  return Object.fromEntries(fields.map(field => [field.path, field.nullable ? null : field.type === 'boolean' ? false : field.type === 'array' ? [] : field.type === 'number' || field.type === 'integer' ? 0 : '']))
}

export function buildConfig(fields: ConfigFormField[], values: WizardValues): Record<string, unknown> {
  const result: Record<string, unknown> = {}
  for (const field of fields) {
    const parts = field.path.split('.')
    let cursor = result
    for (const part of parts.slice(0, -1)) {
      const current = cursor[part]
      if (!current || typeof current !== 'object' || Array.isArray(current)) cursor[part] = {}
      cursor = cursor[part] as Record<string, unknown>
    }
    let value = values[field.path]
    if (field.type === 'number') value = Number(value)
    if (field.type === 'integer') value = Number.parseInt(String(value), 10)
    if (field.type === 'array' && typeof value === 'string') value = value.split(/[\n,]/).map(item=>item.trim()).filter(Boolean)
    cursor[parts.at(-1)!] = value
  }
  return result
}
