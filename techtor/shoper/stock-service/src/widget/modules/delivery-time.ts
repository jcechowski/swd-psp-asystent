import type { WidgetModule, WidgetState, StockInfo } from '../types';
import { dbg } from '../utils/debug';

/** Selektory elementu czasu dostawy w Shoper Phoenix */
const DELIVERY_SELECTORS = [
  '.product__delivery-time .value',
  '.product__delivery .delivery-value',
  '.delivery-time__value',
  '[data-delivery-time]',
  '.product-delivery-time',
];

/** Tekst fallback do wyszukiwania elementu */
const DELIVERY_TEXT_PATTERNS = ['24 godziny', '48 godzin', '3-4 dni'];
const DELIVERY_PARENT_KEYWORDS = /wysyłk|dostaw|delivery/i;

/** Dynamiczny czas dostawy na podstawie stanu magazynowego */
export class DeliveryTime implements WidgetModule {
  private styleEl: HTMLStyleElement | null = null;

  apply(state: WidgetState, info: StockInfo, qty: number): void {
    const el = this.findDeliveryElement();
    if (!el) return;

    // Ukryj czas dostawy przy brak towaru / cena 0
    if (state === 'out-of-stock' || state === 'price-zero') {
      this.hideDelivery(el);
      return;
    }

    this.showDelivery(el);

    // qty ≤ stockTechtor → 24h (mamy na miejscu)
    // qty > stockTechtor → 48h (od dostawcy)
    if (state === 'available-tarnawa' || qty > info.stockTechtor) {
      this.setDeliveryText(el, '48 godzin', '#b45309');
      dbg('Delivery: 48h (od dostawcy)');
    } else {
      this.setDeliveryText(el, '24 godziny', '');
      dbg('Delivery: 24h (magazyn Techtor)');
    }
  }

  destroy(): void {
    this.styleEl?.remove();
    this.styleEl = null;
  }

  private findDeliveryElement(): HTMLElement | null {
    // Szukaj po selektorach
    for (const sel of DELIVERY_SELECTORS) {
      const el = document.querySelector<HTMLElement>(sel);
      if (el) return el;
    }

    // Fallback: szukaj po tekście
    const candidates = document.querySelectorAll<HTMLElement>('span, div, p, td');
    for (const el of candidates) {
      const text = el.textContent?.trim() || '';
      if (DELIVERY_TEXT_PATTERNS.some(p => text.includes(p))) {
        const parent = el.closest('.product-delivery, .product__delivery, [class*="delivery"]');
        if (parent || DELIVERY_PARENT_KEYWORDS.test(el.parentElement?.textContent || '')) {
          return el;
        }
      }
    }

    return null;
  }

  private setDeliveryText(el: HTMLElement, text: string, color: string): void {
    // CSS ::after overlay (jak availability — Phoenix re-renderuje textContent)
    const id = 'techtor-delivery-css';
    if (!this.styleEl) {
      this.styleEl = document.createElement('style');
      this.styleEl.id = id;
      document.head.appendChild(this.styleEl);
    }

    // Ukryj oryginalny tekst, pokaż nasz przez ::after
    const selectors = DELIVERY_SELECTORS.join(',\n      ');
    this.styleEl.textContent = `
      ${selectors} {
        font-size: 0 !important;
        color: transparent !important;
      }
      ${selectors.split(',').map(s => s.trim() + '::after').join(',\n      ')} {
        content: "${text}";
        font-size: 14px !important;
        color: ${color || 'inherit'} !important;
        font-weight: 600;
      }
    `;
  }

  private hideDelivery(el: HTMLElement): void {
    const wrapper = el.closest('.product-delivery, .product__delivery, [class*="delivery-time"]') as HTMLElement;
    if (wrapper) {
      wrapper.style.display = 'none';
    }
  }

  private showDelivery(el: HTMLElement): void {
    const wrapper = el.closest('.product-delivery, .product__delivery, [class*="delivery-time"]') as HTMLElement;
    if (wrapper) {
      wrapper.style.display = '';
    }
  }
}
