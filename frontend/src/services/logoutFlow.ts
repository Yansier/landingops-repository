export function logoutDestination(completedOnServer: boolean) {
  return {
    name: 'login',
    query: completedOnServer ? {} : { reason: 'logout_local' },
  } as const
}
