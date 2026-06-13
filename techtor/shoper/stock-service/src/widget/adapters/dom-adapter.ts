import type { WidgetAdapter } from '../types';
import { getSku } from '../utils/sku-resolver';
import { dbg } from '../utils/debug';

/** Fallback adapter — czysty DOM polling + MutationObserver (bez Event Bus) */
export class DomAdapter implements WidgetAdapter {
  private observers: MutationObserver[] = [];
  private intervals: ReturnType<typeof setInterval>[] = [];
  private cleanups: Array<() => void> = [];

  constructor() {
    dbg('DomAdapter: fallback (brak Event Bus)');
  }

  onProductChange(cb: (sku: string) => void): void {
    let lastSku = '';

    const check = () => {
      const sku = getSku();
      if (sku && sku !== lastSku) {
        lastSku = sku;
        cb(sku);
      }
    };

    // MutationObserver na SKU element
    const skuEl = document.querySelector('[data-product-code="sku"]');
    if (skuEl) {
      const mo = new MutationObserver(check);
      mo.observe(skuEl, { childList: true, characterData: true, subtree: true });
      this.observers.push(mo);
    }

    // Polling co 1s jako fallback
    this.intervals.push(setInterval(check, 1000));

    // Initial
    check();
  }

  onQuantityChange(cb: (qty: number) => void): void {
    let lastQty = -1;

    const check = () => {
      const stepper = document.querySelector<HTMLElement>('h-input-stepper, [class*="quantity__input"]');
      if (!stepper) return;
      const val = parseInt(stepper.getAttribute('value') || '1', 10) || 1;
      if (val !== lastQty) {
        lastQty = val;
        cb(val);
      }
    };

    // MutationObserver na stepper
    const stepper = document.querySelector('h-input-stepper');
    if (stepper) {
      const mo = new MutationObserver(check);
      mo.observe(stepper, { attributes: true, attributeFilter: ['value'] });
      this.observers.push(mo);

      // Click listeners
      stepper.querySelectorAll('h-button-stepper, button').forEach(btn => {
        const handler = () => setTimeout(check, 50);
        btn.addEventListener('click', handler);
        this.cleanups.push(() => btn.removeEventListener('click', handler));
      });
    }

    this.intervals.push(setInterval(check, 1000));
    check();
  }

  onNavigation(cb: () => void): void {
    let lastUrl = location.href;

    const poll = setInterval(() => {
      if (location.href !== lastUrl) {
        lastUrl = location.href;
        setTimeout(cb, 300);
      }
    }, 500);
    this.intervals.push(poll);

    const handler = () => setTimeout(cb, 300);
    window.addEventListener('popstate', handler);
    this.cleanups.push(() => window.removeEventListener('popstate', handler));
  }

  destroy(): void {
    for (const mo of this.observers) mo.disconnect();
    for (const id of this.intervals) clearInterval(id);
    for (const fn of this.cleanups) fn();
    this.observers = [];
    this.intervals = [];
    this.cleanups = [];
    dbg('DomAdapter: zniszczony');
  }
}
