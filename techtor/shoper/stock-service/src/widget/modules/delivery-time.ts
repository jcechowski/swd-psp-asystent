import type { WidgetModule, WidgetState, StockInfo } from '../types';
import { dbg } from '../utils/debug';

/** Selektor elementu czasu dostawy w Shoper Phoenix */
const DELIVERY_SELECTOR = '[data-shipping-time]';

/** Dynamiczny czas dostawy na podstawie stanu magazynowego */
export class DeliveryTime implements WidgetModule {
  private styleEl: HTMLStyleElement | null = null;

  apply(state: WidgetState, info: StockInfo, qty: number): void {
    const el = document.querySelector<HTMLElement>(DELIVERY_SELECTOR);
    if (!el) {
      dbg('Delivery: element nie znaleziony');
      return;
    }

    // Ukryj czas dostawy przy brak towaru / cena 0 / overlimit
    if (state === 'out-of-stock' || state === 'price-zero' || state === 'overlimit') {
      const wrapper = el.closest('[class*="shipping"], [class*="delivery"], [data-module-name*="shipping"]') as HTMLElement || el.parentElement;
      if (wrapper) wrapper.classList.add('techtor-hide');
      this.clearOverlay();
      return;
    }

    // Pokaż jeśli ukryty
    const wrapper = el.closest('[class*="shipping"], [class*="delivery"], [data-module-name*="shipping"]') as HTMLElement || el.parentElement;
    if (wrapper) {
      wrapper.classList.remove('techtor-hide');
      delete (wrapper as any).dataset?.techtorHidden;
    }

    // qty > stockTechtor → 48h (od dostawcy)
    if (state === 'available-tarnawa' || qty > info.stockTechtor) {
      this.setOverlay('48 godzin', '#b45309');
      dbg('Delivery: 48h (od dostawcy)');
    } else {
      this.clearOverlay();
      dbg('Delivery: 24h (natywny z Shoper)');
    }
  }

  destroy(): void {
    this.clearOverlay();
  }

  private setOverlay(text: string, color: string): void {
    if (!this.styleEl) {
      this.styleEl = document.createElement('style');
      this.styleEl.id = 'techtor-delivery-css';
      document.head.appendChild(this.styleEl);
    }

    // CSS ::after overlay — nie textContent (Phoenix re-renderuje)
    this.styleEl.textContent = `
      ${DELIVERY_SELECTOR} {
        font-size: 0 !important;
        color: transparent !important;
      }
      ${DELIVERY_SELECTOR}::after {
        content: "${text}";
        font-size: 14px !important;
        color: ${color} !important;
        font-weight: 600;
      }
    `;
  }

  private clearOverlay(): void {
    if (this.styleEl) {
      this.styleEl.remove();
      this.styleEl = null;
    }
  }
}
