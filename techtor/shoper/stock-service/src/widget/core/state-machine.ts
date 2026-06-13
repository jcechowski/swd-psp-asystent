import type { WidgetState, StockInfo } from '../types';
import { dbg } from '../utils/debug';

/** Oblicza stan widgetu na podstawie danych stocku i ilości
 *
 * Progi magazynowe:
 * - qty ≤ stockTechtor       → AVAILABLE (24h)
 * - qty > stockTechtor, ≤ total → AVAILABLE-TARNAWA (48h)
 * - qty > total               → OVERLIMIT (zapytaj)
 * - total = 0                 → OUT_OF_STOCK (zapytaj, koszyk odblokowany)
 * - price0                    → PRICE_ZERO (koszyk zablokowany)
 */
export function computeState(info: StockInfo, qty: number): WidgetState {
  // Priorytet 1: cena = 0
  if (info.isPrice0) return 'price-zero';

  // Priorytet 2: brak stocku w obu magazynach
  if (info.totalStock <= 0) return 'out-of-stock';

  // Priorytet 3: ilość > suma obu magazynów
  if (qty > info.totalStock) return 'overlimit';

  // Priorytet 4: Techtor nie starczy, ale Tarnawa ma
  if (qty > info.stockTechtor) return 'available-tarnawa';

  // Dostępny z magazynu Techtor (24h)
  return 'available';
}

/** Loguje zmianę stanu */
export function logTransition(prev: WidgetState, next: WidgetState, info: StockInfo, qty: number): void {
  if (prev !== next) {
    dbg(`Stan: ${prev} → ${next} (sku=${info.sku}, techtor=${info.stockTechtor}, total=${info.totalStock}, qty=${qty}, price0=${info.isPrice0})`);
  }
}
