const PREFIX = '[TECHTOR]';
let debugEnabled = false;

export function initDebug(): void {
  try {
    debugEnabled = localStorage.getItem('techtor_debug') === '1' || location.hash.includes('debug');
  } catch { /* private browsing */ }
}

export function dbg(...args: unknown[]): void {
  if (debugEnabled) console.log(PREFIX, ...args);
}

export function dbgWarn(...args: unknown[]): void {
  console.warn(PREFIX, ...args);
}

export function isDebug(): boolean {
  return debugEnabled;
}
