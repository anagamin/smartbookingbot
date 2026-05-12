const STORAGE_KEY = 'sbb_cabinet_browser_push'

export function cabinetBrowserPushSupported(): boolean {
  return typeof globalThis !== 'undefined' && 'Notification' in globalThis
}

export function getCabinetBrowserPushOptIn(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

export function setCabinetBrowserPushOptIn(enabled: boolean): void {
  try {
    if (enabled) {
      localStorage.setItem(STORAGE_KEY, '1')
    } else {
      localStorage.removeItem(STORAGE_KEY)
    }
  } catch {
    /* ignore */
  }
}

export function cabinetBrowserPushActive(): boolean {
  if (!cabinetBrowserPushSupported()) {
    return false
  }
  return getCabinetBrowserPushOptIn() && Notification.permission === 'granted'
}
