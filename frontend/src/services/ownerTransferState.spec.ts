import { describe, expect, it } from 'vitest'
import { resetAfterOwnerTransferConflict } from './ownerTransferState'
import type { Instance } from '@/types/api'

describe('负责人转交冲突状态', () => {
  it('刷新后丢弃旧 selected，要求基于新版本重新确认', () => {
    const latest = [{ id: 'instance', version: 8 }] as Instance[]
    const state = resetAfterOwnerTransferConflict(latest, 'instance')
    expect(state.selected).toBeNull()
    expect(state.latestVersion).toBe(8)
    expect(state.message).toContain('重新打开转交窗口')
  })
})
