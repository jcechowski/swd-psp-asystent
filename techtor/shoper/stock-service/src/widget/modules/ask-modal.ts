import { escapeHtml } from '../utils/escape-html';
import { dbg } from '../utils/debug';

const ASK_API = 'https://stock.techtor.pl/api/ask';
const VAT_API = 'https://vat.techtor.pl/api/gus';

/** Modal "Zapytaj o dostępność/cenę" z formularzem kontaktowym + NIP/GUS */
export function showAskModal(sku: string, productName: string, quantity?: number, priceInquiry?: boolean): void {
  // Usuń istniejący modal
  document.getElementById('techtor-ask-overlay')?.remove();

  const overlay = document.createElement('div');
  overlay.id = 'techtor-ask-overlay';
  overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;';

  const qtyPrefill = quantity ? ` ${quantity} szt.` : '';
  const msgPrefill = priceInquiry
    ? `Proszę o wycenę produktu ${escapeHtml(productName)} (${escapeHtml(sku)}).`
    : `Jestem zainteresowany produktem ${escapeHtml(productName)} (${escapeHtml(sku)})${qtyPrefill}. Proszę o informację o dostępności i terminie realizacji.`;

  overlay.innerHTML = `
    <div style="background:#fff;max-width:780px;width:100%;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.15);max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
      <button id="techtor-ask-close" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#6b7280;padding:8px;" aria-label="Zamknij">&times;</button>
      <h3 style="margin:0 0 4px;font-size:20px;font-weight:700;color:#1f2937;">${priceInquiry ? 'Zapytaj o cenę i dostępność' : 'Zapytaj o dostępność'}</h3>
      <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">Produkt: <strong>${escapeHtml(productName)}</strong> (${escapeHtml(sku)})</p>
      <form id="techtor-ask-form">
        <input type="text" name="_hp" style="display:none" tabindex="-1" autocomplete="off">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label style="font-size:12px;font-weight:600;color:#374151;">Ilość (szt.) *</label>
            <input name="quantity" type="number" min="1" value="${quantity || 1}" required style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;">
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;color:#374151;">NIP (opcjonalnie)</label>
            <input name="nip" type="text" maxlength="10" placeholder="0000000000" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;">
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;color:#374151;">Firma</label>
            <input name="company" type="text" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;">
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;color:#374151;">Email *</label>
            <input name="email" type="email" required style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;">
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;color:#374151;">Telefon</label>
            <input name="phone" type="tel" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;">
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;color:#374151;">Imię i nazwisko *</label>
            <input name="name" type="text" required style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;">
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;color:#374151;">Ulica</label>
            <input name="street" type="text" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;">
          </div>
          <div style="display:grid;grid-template-columns:100px 1fr;gap:8px;">
            <div>
              <label style="font-size:12px;font-weight:600;color:#374151;">Kod</label>
              <input name="zip" type="text" placeholder="00-000" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;">
            </div>
            <div>
              <label style="font-size:12px;font-weight:600;color:#374151;">Miasto</label>
              <input name="city" type="text" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;">
            </div>
          </div>
        </div>
        <div style="margin-top:12px;">
          <label style="font-size:12px;font-weight:600;color:#374151;">Wiadomość</label>
          <textarea name="message" rows="3" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;resize:vertical;">${msgPrefill}</textarea>
        </div>
        <button type="submit" style="width:100%;margin-top:16px;padding:14px;border:none;border-radius:30px;background:#d97706;color:#fff;font-weight:700;font-size:15px;cursor:pointer;">Wyślij zapytanie</button>
      </form>
      <div id="techtor-ask-success" style="display:none;text-align:center;padding:20px;">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <p style="margin:12px 0 0;font-size:18px;font-weight:700;color:#1f2937;">Zapytanie wysłane!</p>
        <p style="margin:4px 0 0;font-size:13px;color:#6b7280;">Odpowiemy najszybciej jak to możliwe.</p>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);

  // Close button
  document.getElementById('techtor-ask-close')!.onclick = () => overlay.remove();

  // NIP auto-fill (GUS API)
  const nipInput = overlay.querySelector<HTMLInputElement>('input[name="nip"]');
  if (nipInput) {
    nipInput.addEventListener('input', async () => {
      const nip = nipInput.value.replace(/\D/g, '');
      if (nip.length !== 10) return;
      try {
        const res = await fetch(`${VAT_API}?nip=${nip}`);
        const data = await res.json();
        if (data?.result?.subject) {
          const s = data.result.subject;
          const form = overlay.querySelector('form')!;
          const set = (name: string, val: string) => {
            const el = form.querySelector<HTMLInputElement>(`[name="${name}"]`);
            if (el && !el.value) el.value = val;
          };
          set('company', s.name || '');
          set('street', s.workingAddress?.split(',')[0] || '');
          const parts = (s.workingAddress || '').split(',');
          if (parts.length > 1) {
            const zipCity = parts[parts.length - 1].trim();
            const zm = zipCity.match(/^(\d{2}-\d{3})\s+(.+)/);
            if (zm) { set('zip', zm[1]); set('city', zm[2]); }
          }
          dbg('NIP auto-fill:', s.name);
        }
      } catch { /* ignore */ }
    });
  }

  // Form submit
  const form = document.getElementById('techtor-ask-form') as HTMLFormElement;
  form.onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const name = (fd.get('name') as string || '').trim();
    const email = (fd.get('email') as string || '').trim();
    if (!name || !email || !email.includes('@')) {
      alert('Wypełnij wymagane pola (imię, email)');
      return;
    }

    try {
      const res = await fetch(ASK_API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name, email,
          _hp: fd.get('_hp') || '',
          phone: fd.get('phone') || '',
          nip: fd.get('nip') || '',
          company: fd.get('company') || '',
          street: fd.get('street') || '',
          zip: fd.get('zip') || '',
          city: fd.get('city') || '',
          message: fd.get('message') || '',
          quantity: fd.get('quantity') || '1',
          sku, product: productName, url: location.href,
        }),
      });
      if (res.ok) {
        form.style.display = 'none';
        document.getElementById('techtor-ask-success')!.style.display = 'block';
        setTimeout(() => overlay.remove(), 3000);
      } else {
        alert('Wystąpił błąd. Spróbuj ponownie.');
      }
    } catch {
      alert('Błąd połączenia. Spróbuj ponownie.');
    }
  };
}
