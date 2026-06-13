import type { WidgetModule, WidgetState, StockInfo } from '../types';

/** CSS overlay na polu dostępności — nie textContent (Shoper Phoenix re-renderuje) */
export class AvailabilityOverlay implements WidgetModule {
  private styleEl: HTMLStyleElement | null = null;
  private observer: MutationObserver | null = null;

  apply(state: WidgetState, _info: StockInfo, _qty: number): void {
    const availEl = document.querySelector<HTMLElement>('.product-availability dd, .product-availability span, .product-availability .property__value');
    if (!availEl) return;

    // Overlay text i kolor na podstawie stanu
    let text = '';
    let color = '';

    switch (state) {
      case 'available':
        text = 'dostępny';
        color = '#16a34a'; // green
        break;
      case 'available-tarnawa':
        text = 'dostępny';
        color = '#16a34a'; // green (towar jest, tylko dłuższa wysyłka)
        break;
      case 'overlimit':
        text = 'zapytaj o dostępność';
        color = '#b45309'; // amber
        break;
      case 'out-of-stock':
        text = 'zapytaj o dostępność';
        color = '#b45309';
        break;
      case 'price-zero':
        text = 'zapytaj o cenę i dostępność';
        color = '#b45309';
        break;
    }

    if (!text) return;

    // CSS ::after overlay (nie textContent — Shoper re-renderuje)
    const id = 'techtor-avail-css';
    if (!this.styleEl) {
      this.styleEl = document.createElement('style');
      this.styleEl.id = id;
      document.head.appendChild(this.styleEl);
    }

    this.styleEl.textContent = `
      .product-availability dd,
      .product-availability span.property__value,
      .product-availability .property__value {
        font-size: 0 !important;
        color: transparent !important;
      }
      .product-availability dd::after,
      .product-availability span.property__value::after,
      .product-availability .property__value::after {
        content: "${text}";
        font-size: 14px !important;
        color: ${color} !important;
        font-weight: 600;
      }
    `;

    // MutationObserver — utrzymaj overlay przy re-renderach Phoenix
    if (!this.observer) {
      this.observer = new MutationObserver(() => {
        // Style jest w <head>, nie jest usuwany przez Phoenix re-render
      });
      this.observer.observe(availEl, { childList: true, characterData: true, subtree: true });
    }
  }

  destroy(): void {
    this.styleEl?.remove();
    this.styleEl = null;
    this.observer?.disconnect();
    this.observer = null;
  }
}
