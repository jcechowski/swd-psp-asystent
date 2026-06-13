import type { WidgetModule, WidgetState, StockInfo } from '../types';
import { dbg } from '../utils/debug';

/** Auto-select pierwszego wariantu (zamiast "Wybierz") */
export class VariantSelector implements WidgetModule {
  private attempted = false;
  private maxAttempts = 30;
  private interval: ReturnType<typeof setInterval> | null = null;

  apply(_state: WidgetState, _info: StockInfo, _qty: number): void {
    if (this.attempted) return;
    this.autoSelect();
  }

  private autoSelect(): void {
    let attempts = 0;

    this.interval = setInterval(() => {
      if (this.attempted || attempts++ > this.maxAttempts) {
        if (this.interval) clearInterval(this.interval);
        return;
      }

      const selects = document.querySelectorAll<HTMLSelectElement>('select');
      for (const sel of selects) {
        const firstOpt = sel.options[0];
        if (!firstOpt) continue;
        const text = firstOpt.text.toLowerCase();
        if (text.includes('wybierz') || text.includes('select')) {
          if (sel.options.length > 1) {
            sel.value = sel.options[1].value;
            this.triggerChange(sel);
            this.attempted = true;
            dbg('Auto-select wariantu:', sel.options[1].text);
            if (this.interval) clearInterval(this.interval);
            return;
          }
        }
      }
    }, 500);
  }

  private triggerChange(sel: HTMLSelectElement): void {
    // Metoda 1: natywny setter (obchodzi React/Vue)
    try {
      const desc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
      if (desc?.set) {
        desc.set.call(sel, sel.value);
        sel.dispatchEvent(new Event('input', { bubbles: true }));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        return;
      }
    } catch { /* fallback */ }

    // Metoda 2: standard events
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    sel.dispatchEvent(new Event('input', { bubbles: true }));
  }

  destroy(): void {
    if (this.interval) clearInterval(this.interval);
    this.attempted = false;
  }
}
