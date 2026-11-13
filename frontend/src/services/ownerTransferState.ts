import type { Instance } from '@/types/api'

export interface OwnerTransferConflictState {
  selected: null
  message: string
  latestVersion: number | null
}

export function resetAfterOwnerTransferConflict(instances: Instance[], instanceId: string): OwnerTransferConflictState {
  const latest = instances.find(item => item.id === instanceId)
  return {
    selected: null,
    latestVersion: latest?.version ?? null,
    message: latest
      ? `实例数据已更新到 v${latest.version}，请重新打开转交窗口并确认负责人。`
      : '实例数据已变化，请重新选择实例后操作。',
  }
}
