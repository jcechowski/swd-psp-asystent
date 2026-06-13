/** Pobiera SKU produktu z DOM — 3 strategie fallback */
export function getSku(): string | null {
  // 1. Natywny atrybut Shoper
  const el = document.querySelector<HTMLElement>('[data-product-code="sku"]');
  if (el) {
    const text = el.textContent?.trim();
    if (text) return text;
  }

  // 2. Shadow DOM w <product-codes>
  const pc = document.querySelector('product-codes');
  if (pc?.shadowRoot) {
    const inner = pc.shadowRoot.querySelector('[data-product-code="sku"]');
    if (inner?.textContent?.trim()) return inner.textContent.trim();
  }

  // 3. Regex z JSON-LD lub inline script
  const body = document.body?.innerHTML || '';
  const m = body.match(/"sku"\s*:\s*"([^"]+)"/);
  if (m?.[1]) return m[1];

  return null;
}

/** Pobiera nazwę produktu z DOM */
export function getProductName(): string {
  const el = document.querySelector<HTMLElement>('h1, [data-product-name]');
  return el?.textContent?.trim() || '';
}

/** Oblicza SKU matki z wariantu: WT019001000 → WT019000000 (pozycje 5-7 → 000) */
export function getParentSku(sku: string): string | null {
  if (sku.length === 11 && sku[0] === 'W') {
    return sku.substring(0, 4) + '000' + sku.substring(7);
  }
  return null;
}
