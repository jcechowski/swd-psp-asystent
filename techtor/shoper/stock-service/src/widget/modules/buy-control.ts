import type { WidgetModule, WidgetState, StockInfo } from '../types';

/** Kontroluje blokadę/odblokadę koszyka (buy-button) */
export class BuyControl implements WidgetModule {
  apply(state: WidgetState, _info: StockInfo, _qty: number): void {
    const buyBtns = document.querySelectorAll<HTMLElement>('buy-button, [class*="buy-button"], .product-buy__button');
    const stepper = document.querySelector<HTMLElement>('h-input-stepper, [class*="quantity__input"]');
    const stepperWrapper = stepper?.closest('product-quantity, [class*="quantity"], .product-quantity') as HTMLElement | null;

    const shouldBlock = state === 'price-zero' || state === 'overlimit';
    const shouldHide = state === 'price-zero';

    for (const bb of buyBtns) {
      if (shouldHide) {
        bb.classList.add('techtor-hide');
        bb.dataset.techtorHidden = '1';
      } else {
        bb.classList.remove('techtor-hide');
        delete bb.dataset.techtorHidden;
      }

      // Disabled state na przyciskach wewnątrz
      const btn = bb.querySelector<HTMLButtonElement>('.btn_primary, button[type="submit"]')
        || (bb.classList.contains('btn_primary') ? bb as HTMLButtonElement : null);
      if (btn) {
        if (shouldBlock && !shouldHide) {
          btn.setAttribute('disabled', 'true');
          btn.style.opacity = '0.5';
          btn.style.pointerEvents = 'none';
        } else if (!shouldBlock) {
          btn.removeAttribute('disabled');
          btn.style.opacity = '';
          btn.style.pointerEvents = '';
        }
      }

      // is-buyable atrybut (Shoper Phoenix web component)
      if (shouldBlock) {
        bb.setAttribute('is-buyable', '0');
      } else {
        bb.removeAttribute('is-buyable');
      }
    }

    // Stepper: ukryj przy price-zero, pokaż w reszcie
    if (stepperWrapper) {
      if (shouldHide) {
        stepperWrapper.classList.add('techtor-hide');
        stepperWrapper.dataset.techtorHidden = '1';
      } else {
        stepperWrapper.classList.remove('techtor-hide');
        delete stepperWrapper.dataset.techtorHidden;
      }
    }
  }

  destroy(): void {
    // Przywróć oryginalne stany
    document.querySelectorAll<HTMLElement>('[data-techtor-hidden]').forEach(el => {
      el.classList.remove('techtor-hide');
      delete el.dataset.techtorHidden;
    });
    document.querySelectorAll<HTMLElement>('buy-button').forEach(bb => {
      bb.removeAttribute('is-buyable');
      const btn = bb.querySelector<HTMLButtonElement>('.btn_primary, button');
      if (btn) {
        btn.removeAttribute('disabled');
        btn.style.opacity = '';
        btn.style.pointerEvents = '';
      }
    });
  }
}
