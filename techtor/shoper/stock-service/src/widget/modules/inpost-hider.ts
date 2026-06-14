import type { WidgetModule, WidgetState, StockInfo } from '../types';
import { INPOST_HIDE_CSS } from '../ui/styles';

/** Ukrywanie InPost Pay gdy niedostępny lub cena 0 */
export class InpostHider implements WidgetModule {
  private styleEl: HTMLStyleElement | null = null;

  apply(state: WidgetState, _info: StockInfo, qty: number): void {
    const shouldHide = state === 'price-zero' || state === 'out-of-stock';

    if (shouldHide && !this.styleEl) {
      this.styleEl = document.createElement('style');
      this.styleEl.id = 'techtor-inpost-hide';
      this.styleEl.textContent = INPOST_HIDE_CSS;
      document.head.appendChild(this.styleEl);
    } else if (!shouldHide && this.styleEl) {
      this.styleEl.remove();
      this.styleEl = null;
    }
  }

  destroy(): void {
    this.styleEl?.remove();
    this.styleEl = null;
  }
}
