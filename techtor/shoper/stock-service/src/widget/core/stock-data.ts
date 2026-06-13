import type { StockInfo } from '../types';
import { getParentSku } from '../utils/sku-resolver';
import { dbg } from '../utils/debug';

const API_URL = 'https://stock.techtor.pl/api/stock-data.json';
const CACHE_KEY = 'techtor_sd';
const CACHE_TTL = 5 * 60 * 1000; // 5 minut

let stockData: Record<string, number> | null = null;

/** Pobiera stock-data.json z cache lub API */
export async function loadStockData(): Promise<Record<string, number>> {
  // Cache w sessionStorage
  try {
    const cached = sessionStorage.getItem(CACHE_KEY);
    if (cached) {
      const { data, ts } = JSON.parse(cached);
      if (Date.now() - ts < CACHE_TTL) {
        stockData = data;
        dbg('Stock data z cache', Object.keys(data).length, 'kluczy');
        return data;
      }
    }
  } catch { /* ignore */ }

  // Fetch z API
  try {
    const res = await fetch(API_URL);
    const data = await res.json();
    stockData = data;
    try {
      sessionStorage.setItem(CACHE_KEY, JSON.stringify({ data, ts: Date.now() }));
    } catch { /* quota */ }
    dbg('Stock data z API', Object.keys(data).length, 'kluczy');
    return data;
  } catch (err) {
    dbg('Stock data fetch error:', err);
    return stockData || {};
  }
}

/** Pobiera informacje o stocku dla SKU */
export function getStockInfo(sku: string): StockInfo {
  const data = stockData || {};

  // Szukaj SKU lub fallback na parent (WT019001000 → WT019000000)
  let lookupSku = sku;
  if (data[sku] === undefined) {
    const parent = getParentSku(sku);
    if (parent && data[parent] !== undefined) lookupSku = parent;
  }

  const stockTechtor = data[lookupSku] || 0;
  const totalStock = data[lookupSku + '__total'] || data[lookupSku] || 0;
  const isPrice0 = !!data[lookupSku + '__price0'];

  return { sku, stockTechtor, totalStock, isPrice0 };
}

/** Invaliduje cache (przy zmianie produktu) */
export function invalidateCache(): void {
  try { sessionStorage.removeItem(CACHE_KEY); } catch { /* ignore */ }
}
