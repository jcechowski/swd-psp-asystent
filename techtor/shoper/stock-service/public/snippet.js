(function () {
  'use strict';
  console.log('[TECHTOR] snippet.js START v5 — variant descriptions');
  function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  // Auto-debug: pokaż raport po 5s jeśli nic nie zadziałało
  setTimeout(function() {
    var hasUI = document.querySelector('.techtor-unavailable-banner, .techtor-ask-btn, #techtor-stock-warning');
    if (!hasUI) {
      var sku = document.querySelector('[data-product-code="sku"]');
      var qi = document.querySelector('h-input-stepper, [class*="quantity__input"], input[name="quantity"]');
      var de = document.querySelector('[data-shipping-time]');
      var buyBtns = document.querySelectorAll('buy-button, .btn_primary');
      console.warn('[TECHTOR DEBUG] Brak UI po 5s!', {
        _tLoad: window._tLoad, _tRunId: window._tRunId, _tSendAsk: typeof window._tSendAsk,
        sku: sku ? sku.textContent.trim() : null,
        qi: qi ? qi.tagName : null, de: de ? de.textContent : null,
        buyBtns: buyBtns.length,
        modal: !!document.getElementById('techtor-ask-modal'),
      });
    }
  }, 5000);

  var API_URL = 'https://stock.techtor.pl/api/stock-data.json';
  var VAT_API = 'https://vat.techtor.pl/api/gus';

  // Zapobiegaj podwójnemu ładowaniu (header loader ustawia _tLoad=0, img onload sprawdza)
  window._tLoad = 1;

  // Globalny numer runu — nowszy anuluje starsze
  var runId = window._tRunId = (window._tRunId || 0) + 1;

  // Wyczyść stary interval
  if (window._tInterval) { clearInterval(window._tInterval); window._tInterval = null; }

  // Ukryj oryginalny przycisk "Zapytaj o produkt" z Shoper
  if (!document.getElementById('techtor-hide-ask')) {
    var style = document.createElement('style');
    style.id = 'techtor-hide-ask';
    style.textContent = '[data-module-name="product_ask_questions"] { display: none !important; }';
    document.head.appendChild(style);
  }

  function getSku() {
    // 1. Standardowy selektor
    var el = document.querySelector('[data-product-code="sku"]');
    if (el) return el.textContent.trim();
    // 2. Szukaj wewnątrz web componentów (Shoper Phoenix)
    var wc = document.querySelector('product-codes');
    if (wc) {
      var root = wc.shadowRoot || wc;
      el = root.querySelector('[data-product-code="sku"]');
      if (el) return el.textContent.trim();
      // Fallback: szukaj w innerHTML
      var m2 = wc.innerHTML.match(/data-product-code="sku"[^>]*>([^<]+)/);
      if (m2) return m2[1].trim();
    }
    // 3. JSON w source
    var m = document.body.innerHTML.match(/"sku"\s*:\s*"([^"]+)"/);
    if (m) return m[1];
    return null;
  }

  function getProductName() {
    var el = document.querySelector('h1, [data-product-name]');
    return el ? el.textContent.trim() : '';
  }

  function showAskModal(sku, productName, quantity, priceInquiry) {
    if (document.getElementById('techtor-ask-modal')) return;
    var modalTitle = priceInquiry ? 'Zapytaj o cenę i dostępność' : 'Zapytaj o dostępność';

    var overlay = document.createElement('div');
    overlay.id = 'techtor-ask-modal';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;';

    var inputStyle = 'width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:10px;font-size:14px;box-sizing:border-box;';
    var rowStyle = 'display:flex;gap:10px;';

    overlay.innerHTML =
      '<div style="background:#fff;border-radius:16px;padding:28px;max-width:780px;width:100%;position:relative;box-shadow:0 20px 60px rgba(0,0,0,0.3);max-height:90vh;overflow-y:auto;">' +
        '<button id="techtor-ask-close" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#666;">&times;</button>' +
        '<h3 style="margin:0 0 4px;font-size:18px;color:#1f2937;">' + modalTitle + '</h3>' +
        '<form id="techtor-ask-form">' +
          '<div style="' + rowStyle + 'align-items:center;margin-bottom:16px;">' +
            '<p style="margin:0;font-size:13px;color:#6b7280;flex:1;">Produkt: <strong>' + escapeHtml(productName) + '</strong> (' + escapeHtml(sku) + ')</p>' +
            '<div style="display:flex;align-items:center;gap:12px;flex:0 0 auto;">' +
              '<div style="flex:0 0 100px;">' +
                '<label style="font-size:12px;font-weight:700;color:#1f2937;margin-bottom:4px;display:block;text-align:center;">Ilość (szt.)</label>' +
                '<input name="quantity" type="number" min="1" value="' + (quantity || 1) + '" style="width:100%;padding:14px;border:2px solid #dc2626;border-radius:12px;font-size:22px;font-weight:800;text-align:center;color:#dc2626;background:#fef2f2;box-sizing:border-box;outline:none;">' +
              '</div>' +
              '<p style="margin:0;font-size:14px;font-weight:700;color:#991b1b;line-height:1.4;max-width:120px;">Podaj ilość<br>sztuk</p>' +
            '</div>' +
          '</div>' +
          '<div style="' + rowStyle + '">' +
            '<div style="flex:0 0 160px;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">NIP</label>' +
              '<input name="nip" placeholder="NIP firmy" maxlength="13" style="' + inputStyle + '">' +
            '</div>' +
            '<div style="flex:1;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Firma</label>' +
              '<input name="company" placeholder="Nazwa firmy" style="' + inputStyle + '">' +
            '</div>' +
          '</div>' +
          '<div id="techtor-nip-status" style="display:none;padding:6px 12px;border-radius:6px;font-size:12px;margin-bottom:10px;"></div>' +
          '<div style="' + rowStyle + '">' +
            '<div style="flex:1;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Email *</label>' +
              '<input name="email" placeholder="Adres e-mail" style="' + inputStyle + '">' +
            '</div>' +
            '<div style="flex:1;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Telefon</label>' +
              '<input name="phone" type="tel" placeholder="np. 600 100 200" style="' + inputStyle + '">' +
            '</div>' +
          '</div>' +
          '<div style="' + rowStyle + '">' +
            '<div style="flex:1;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Imię i nazwisko *</label>' +
              '<input name="name" placeholder="Imię i nazwisko" style="' + inputStyle + '">' +
            '</div>' +
            '<div style="flex:2;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Ulica</label>' +
              '<input name="street" placeholder="Ulica i numer" style="' + inputStyle + '">' +
            '</div>' +
          '</div>' +
          '<div style="' + rowStyle + '">' +
            '<div style="flex:0 0 120px;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Kod pocztowy</label>' +
              '<input name="zip" placeholder="00-000" style="' + inputStyle + '">' +
            '</div>' +
            '<div style="flex:1;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Miasto</label>' +
              '<input name="city" placeholder="Miasto" style="' + inputStyle + '">' +
            '</div>' +
          '</div>' +
          '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Wiadomość</label>' +
          '<textarea name="message" rows="5" style="' + inputStyle + 'resize:vertical;">' +
            'Dzień dobry,\nchciałbym zapytać o ' + (priceInquiry ? 'aktualną cenę i ' : '') + 'dostępność produktu ' + productName + ' (' + sku + ')' + (quantity ? ' w ilości ' + quantity + ' szt.' : '') + '.' + (priceInquiry ? '\nProszę o przesłanie aktualnej oferty cenowej.' : '') + '\nProszę o kontakt.' +
          '</textarea>' +
          '<input name="_hp" type="text" style="position:absolute;left:-9999px;opacity:0;height:0;" tabindex="-1" autocomplete="off">' +
          '<button type="button" id="techtor-ask-send" onclick="window._tSendAsk&&window._tSendAsk()" style="width:100%;padding:14px;border:none;border-radius:8px;background:#dc2626;color:#fff;font-size:16px;font-weight:700;cursor:pointer;margin-top:6px;transition:background 0.2s;">Wyślij zapytanie</button>' +
        '</form>' +
        '<div id="techtor-ask-success" style="display:none;text-align:center;padding:24px 0;">' +
          '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" style="margin:0 auto 12px;display:block;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
          '<p style="font-size:16px;font-weight:600;color:#1f2937;margin:0 0 4px;">Zapytanie wysłane!</p>' +
          '<p style="font-size:13px;color:#6b7280;margin:0;">Odpowiemy najszybciej jak to możliwe.</p>' +
        '</div>' +
      '</div>';

    document.body.appendChild(overlay);
    document.getElementById('techtor-ask-close').onclick = function () { overlay.remove(); };
    // Kliknięcie w tło NIE zamyka — tylko przycisk X (żeby nie stracić wpisanych danych)

    var nipInput = overlay.querySelector('input[name="nip"]');
    var nipStatus = document.getElementById('techtor-nip-status');
    var nipTimeout = null;
    nipInput.addEventListener('input', function () {
      var nip = nipInput.value.replace(/[\s-]/g, '');
      clearTimeout(nipTimeout);
      if (nip.length === 10 && /^\d{10}$/.test(nip)) {
        nipStatus.style.display = 'block';
        nipStatus.style.background = '#eff6ff';
        nipStatus.style.color = '#1e40af';
        nipStatus.textContent = 'Szukam danych firmy...';
        nipTimeout = setTimeout(function () {
          fetch(VAT_API + '?nip=' + nip)
            .then(function (r) { return r.json(); })
            .then(function (res) {
              if (res.ok && res.data) {
                var d = res.data;
                var form = overlay.querySelector('form');
                function setF(n, v) { var el = form.querySelector('[name="' + n + '"]'); if (el) el.value = v; }
                function getF(n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value : ''; }
                if (d.name) setF('company', d.name);
                if (d.street) setF('street', d.street);
                if (d.postalCode) setF('zip', d.postalCode);
                if (d.city) setF('city', d.city);
                if (d.email && !getF('email')) setF('email', d.email);
                if (d.phone && !getF('phone')) setF('phone', d.phone);
                nipStatus.style.background = '#f0fdf4';
                nipStatus.style.color = '#166534';
                nipStatus.textContent = 'Dane uzupełnione — ' + d.name;
              } else {
                nipStatus.style.background = '#fef2f2';
                nipStatus.style.color = '#991b1b';
                nipStatus.textContent = 'Nie znaleziono firmy o podanym NIP';
              }
            })
            .catch(function () { nipStatus.style.display = 'none'; });
        }, 300);
      } else { nipStatus.style.display = 'none'; }
    });

    console.log('[TECHTOR] Modal created, setting up handlers');
    // Globalna funkcja wysyłania — dostępna z inline onclick, .onclick i onsubmit
    window._tSendAsk = function () {
      console.log('[TECHTOR] _tSendAsk called');
      var form = document.getElementById('techtor-ask-form');
      if (!form) return;
      function fv(n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value : ''; }

      if (!fv('name').trim()) { form.querySelector('[name="name"]').focus(); return; }
      if (!fv('email').trim() || fv('email').indexOf('@') < 0) { form.querySelector('[name="email"]').focus(); return; }

      var btn = document.getElementById('techtor-ask-send');
      if (!btn || btn.disabled) return;
      btn.textContent = 'Wysyłanie...'; btn.disabled = true; btn.style.background = '#9ca3af';
      fetch('https://stock.techtor.pl/api/ask', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: fv('name'), email: fv('email'),
          _hp: fv('_hp'),
          phone: fv('phone'), nip: fv('nip'),
          company: fv('company'), street: fv('street'),
          zip: fv('zip'), city: fv('city'),
          message: fv('message'), quantity: fv('quantity'),
          sku: sku, product: productName, url: window.location.href,
        }),
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.ok) {
            form.style.display = 'none';
            document.getElementById('techtor-ask-success').style.display = 'block';
            setTimeout(function () { overlay.remove(); }, 3000);
          } else {
            btn.textContent = res.error || 'Błąd — spróbuj ponownie';
            btn.disabled = false; btn.style.background = '#dc2626';
          }
        })
        .catch(function () {
          btn.textContent = 'Błąd — spróbuj ponownie';
          btn.disabled = false; btn.style.background = '#dc2626';
        });
    };
    // Potrójne zabezpieczenie — onclick na elemencie, .onclick property, i onsubmit formularza
    var sendBtn = document.getElementById('techtor-ask-send');
    if (sendBtn) sendBtn.onclick = window._tSendAsk;
    document.getElementById('techtor-ask-form').onsubmit = function(e) { e.preventDefault(); window._tSendAsk(); };
  }

  // ── Główna logika ──

  function getStockData(cb) {
    var CACHE_KEY = 'techtor_sd';
    var CACHE_TTL = 5 * 60 * 1000;
    try {
      var c = JSON.parse(sessionStorage.getItem(CACHE_KEY));
      if (c && c.ts && Date.now() - c.ts < CACHE_TTL) return cb(c.d);
    } catch (e) {}
    fetch(API_URL)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        try { sessionStorage.setItem(CACHE_KEY, JSON.stringify({ ts: Date.now(), d: d })); } catch (e) {}
        cb(d);
      })
      .catch(function () { cb(null); });
  }

  // ── Ciągły loop aplikujący stan — łapie elementy renderowane przez Shoper po starcie ──
  function startLoop(stockData) {
    if (!stockData) return;

    var sku = null;
    var stockTechtor = 0;
    var totalStock = 0;
    var isPrice0 = false;
    var productName = '';
    var lastVariantSku = null;

    // ── Warianty: podmiana opisu/specs przy zmianie wariantu ──
    function applyVariantData(currentSku) {
      var dataEl = document.getElementById('techtor-variant-data');
      if (!dataEl) return;
      var descEl = document.getElementById('techtor-variant-description');
      var specsEl = document.getElementById('techtor-variant-specs');
      if (!descEl && !specsEl) return;

      try {
        var variants = JSON.parse(dataEl.textContent || '{}');
        var vd = variants[currentSku];
        if (!vd) return;

        // Podmień opis
        if (descEl && vd.description) {
          descEl.innerHTML = vd.description;
        }

        // Podmień specyfikacje
        if (specsEl && vd.specs && Object.keys(vd.specs).length > 0) {
          var rows = '';
          for (var k in vd.specs) {
            rows += '<tr><td style="padding:6px 12px;border:1px solid #e5e7eb;color:#6b7280;font-size:13px;width:40%">' + k + '</td><td style="padding:6px 12px;border:1px solid #e5e7eb;font-weight:500;font-size:13px">' + vd.specs[k] + '</td></tr>';
          }
          specsEl.innerHTML = '<table style="width:100%;border-collapse:collapse;margin:12px 0"><thead><tr><th colspan="2" style="padding:8px 12px;background:#f3f4f6;border:1px solid #e5e7eb;text-align:left;font-size:13px;font-weight:600">Specyfikacja techniczna</th></tr></thead><tbody>' + rows + '</tbody></table>';
        } else if (specsEl) {
          specsEl.innerHTML = '';
        }

        // Wymuś przeliczenie wysokości accordion/view-more w Shoper Phoenix
        var accordion = (descEl || specsEl).closest('h-accordion-content, [class*="accordion"]');
        if (accordion) {
          accordion.style.maxHeight = 'none';
          accordion.style.height = 'auto';
          accordion.style.overflow = 'visible';
        }
        // Szukaj parent view-more-less
        var vml = (descEl || specsEl).closest('.view-more-less, [class*="view-more"]');
        if (vml) {
          vml.style.maxHeight = 'none';
          vml.style.height = 'auto';
          vml.style.overflow = 'visible';
          var ctrl = vml.querySelector('.view-more-less__controls, [class*="read-more"]');
          if (ctrl) ctrl.style.display = 'none';
        }

        dbg('Variant switch → ' + currentSku + ' (' + (vd.name || '').substring(0, 40) + ')');
      } catch (e) {
        dbg('Variant parse error: ' + e.message);
      }
    }

    function applyState() {
      // Sprawdź czy nowszy run nie zastąpił tego
      if (window._tRunId !== runId) {
        clearInterval(window._tInterval);
        return;
      }

      // Czytaj SKU — odświeżaj przy każdym cyklu (Shoper zmienia SKU przy wyborze wariantu)
      var currentSku = getSku();
      if (!currentSku) return; // czekaj na DOM

      // Wykryj zmianę wariantu
      if (currentSku !== sku) {
        sku = currentSku;
        // Fallback na parent SKU: wariant WT019001000 → parent WT019000000 (pozycje 5-7 = długość → 000)
        var lookupSku = sku;
        if (stockData[sku] === undefined && sku && sku.length === 11 && sku[0] === 'W') {
          var parentSku = sku.substring(0, 5) + '000' + sku.substring(8);
          if (stockData[parentSku] !== undefined) {
            lookupSku = parentSku;
            dbg('Variant ' + sku + ' → parent ' + parentSku);
          }
        }
        stockTechtor = stockData[lookupSku] || 0;
        // Fallback: jeśli brak __total, użyj stock (zapobiega fałszywej niedostępności)
        totalStock = stockData[lookupSku + '__total'] || stockData[lookupSku] || 0;
        isPrice0 = !!stockData[lookupSku + '__price0'];
        productName = getProductName();
        if (lastVariantSku !== null) {
          dbg('Variant changed: ' + lastVariantSku + ' → ' + sku);
        } else {
          dbg('SKU: ' + sku + ' techtor=' + stockTechtor + ' total=' + totalStock + ' price0=' + isPrice0);
        }
        lastVariantSku = sku;
        // Podmień opis/specs wariantu
        applyVariantData(sku);
      }

      // Znajdź elementy DOM (mogą pojawić się w różnym czasie)
      var qi = document.querySelector('h-input-stepper.product-quantity__input, .product-quantity__input, h-input-stepper, [class*="quantity__input"], input[name="quantity"], input[type="number"][min]');
      var de = document.querySelector('[data-shipping-time]');
      var buyBtns = document.querySelectorAll('buy-button, [class*="buy-button"], .product-buy__button, button[type="submit"][class*="btn_primary"]');
      if (buyBtns.length === 0) buyBtns = document.querySelectorAll('.btn_primary');
      var buyArea = document.querySelector('buy-button, .product-actions, [data-module-name="product_actions"], .product-buy, .product__actions, [class*="product-action"], form[action*="cart"], .product-detail__actions');
      if (!de && !qi && !buyArea && !isPrice0) return; // DOM jeszcze nie gotowy (price0 działa bez tych elementów)

      // Pole "Dostępność" — szukamy elementu zawierającego tekst "dostępny"
      // Znajdź pole dostępności — pełne nazwy z Shoper (z wariantami pisowni ś/s)
      var availEl = null;
      var availNames = [
        'dostępny', 'dostepny',
        'niedostępny', 'niedostepny',
        'zapytaj o dostępność', 'zapytaj o dostepność',
        'zapytaj o cenę i dostępność', 'zapytaj o cene i dostepność',
        'na zamówienie', 'na zamowienie',
        'spodziewana dostawa',
        'dostępny na zamówienie', 'dostepny na zamowienie',
        'brak informacji',
        'trwale niedostępny', 'trwale niedostepny',
        'wycofany z oferty',
      ];
      document.querySelectorAll('dd, span, div, p').forEach(function (el) {
        if (availEl) return;
        var t = el.textContent.trim().toLowerCase();
        if (el.children.length === 0 && availNames.indexOf(t) >= 0) {
          availEl = el;
        }
      });
      // Nakładka na dostępność — Shoper Phoenix re-renderuje textContent,
      // więc nadpisujemy CSS overlay zamiast walczyć o textContent
      if (availEl && !availEl.dataset.techtorOverlay) {
        availEl.style.position = 'relative';
        var overlay = document.createElement('span');
        overlay.className = 'techtor-avail-overlay';
        overlay.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;background:#fff;z-index:1;';
        availEl.appendChild(overlay);
        availEl.dataset.techtorOverlay = '1';
        // MutationObserver — reaguj natychmiast na re-rendery Shoper (z guard przeciw pętli)
        var _applyPending = false;
        new MutationObserver(function (mutations) {
          // Ignoruj zmiany w naszym overlay (zapobiega pętli)
          var dominated = mutations.every(function (m) { return m.target.classList && m.target.classList.contains('techtor-avail-overlay'); });
          if (dominated || _applyPending) return;
          _applyPending = true;
          setTimeout(function () { _applyPending = false; applyState(); }, 5);
        }).observe(availEl, { childList: true, characterData: true, subtree: true });
      }
      var availOverlay = availEl ? availEl.querySelector('.techtor-avail-overlay') : null;

      // CSS helper (potrzebny dla overlimit w dostępnych i dla niedostępnych)
      if (!document.getElementById('techtor-unavailable-css')) {
        var css = document.createElement('style');
        css.id = 'techtor-unavailable-css';
        css.textContent =
          '.techtor-hide { display: none !important; opacity: 0 !important; pointer-events: none !important; }';
        document.head.appendChild(css);
      }

      // Ukryj InPost Pay — CSS rule odporny na SPA re-render Shoper Phoenix
      // Ukrywa przy: niedostępny, cena 0, overlimit
      var shouldHideInpost = isPrice0 || totalStock <= 0;
      if (!shouldHideInpost && totalStock > 0) {
        var qi2 = document.querySelector('h-input-stepper.product-quantity__input, .product-quantity__input, h-input-stepper, input[name="quantity"]');
        var q2 = qi2 ? (parseInt(qi2.getAttribute('value') || qi2.value, 10) || 1) : 1;
        if (q2 > totalStock) shouldHideInpost = true;
      }
      if (shouldHideInpost) {
        if (!document.getElementById('techtor-inpost-hide')) {
          var inpCss = document.createElement('style');
          inpCss.id = 'techtor-inpost-hide';
          inpCss.textContent = 'inpost-izi-button, INPOST-IZI-BUTTON { display: none !important; }';
          document.head.appendChild(inpCss);
        }
      } else {
        var oldInpCss = document.getElementById('techtor-inpost-hide');
        if (oldInpCss) oldInpCss.remove();
      }

      // ── BRAK CENY (price0) — najwyższy priorytet ──
      if (isPrice0) {
        // Ukryj ceny
        document.querySelectorAll('[class*="price"]:not([class*="price-compare"]), .product-price, product-price').forEach(function (el) {
          if (el.closest('#techtor-stock-warning, .techtor-unavailable-banner, .techtor-ask-btn')) return;
          el.classList.add('techtor-hide');
          el.dataset.techtorHidden = '1';
        });
        // Ukryj czas wysyłki
        if (de) {
          var deWrapper = de.closest('[class*="shipping"], [class*="delivery"], [data-module-name*="shipping"]') || de.parentElement;
          (deWrapper || de).classList.add('techtor-hide');
        }
        // Ukryj stepper
        if (qi) {
          var qiWrapper = qi.closest('product-quantity, [class*="quantity"], .product-quantity');
          (qiWrapper || qi).classList.add('techtor-hide');
        }
        // Ukryj koszyk
        buyBtns.forEach(function (bb) {
          bb.classList.add('techtor-hide');
          var btn = bb.querySelector('.btn_primary') || (bb.classList.contains('btn_primary') ? bb : null);
          if (btn) btn.classList.add('techtor-hide');
        });
        // Zmiana pola "Dostępność"
        if (availOverlay) { availOverlay.textContent = 'zapytaj o dostępność'; availOverlay.style.color = '#b45309'; }
        // Baner "Zapytaj o cenę" — stały kontener (id) odporny na rerender Shoper Phoenix
        if (!document.getElementById('techtor-price0-banner')) {
          var b0 = document.createElement('div');
          b0.id = 'techtor-price0-banner';
          b0.style.cssText = 'margin:16px 0 20px;padding:20px 24px;border-radius:12px;background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);border:1px solid #fde68a;text-align:center;position:relative;z-index:100;';
          b0.innerHTML =
            '<div style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:50%;background:#fef3c7;margin-bottom:12px;">' +
              '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
            '</div>' +
            '<p style="margin:0 0 4px;font-size:16px;font-weight:700;color:#1f2937;">Produkt chwilowo niedostępny</p>' +
            '<p style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.5;">Zapytaj o aktualną cenę i dostępność tego produktu.</p>';
          var askP = document.createElement('button');
          askP.className = 'techtor-ask-btn';
          askP.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:14px 32px;border-radius:30px;border:none;cursor:pointer;font-weight:700;font-size:15px;background:#d97706;color:#fff;box-shadow:0 4px 14px rgba(217,119,6,0.25);transition:all 0.2s ease;';
          askP.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Zapytaj o produkt';
          askP.onmouseover = function () { askP.style.background = '#b45309'; };
          askP.onmouseout = function () { askP.style.background = '#d97706'; };
          askP.onclick = function () { showAskModal(sku, productName, null, true); };
          b0.appendChild(askP);
          // Wstaw do body jako overlay jeśli nie ma buyArea
          var target = buyArea || document.querySelector('.product-detail, .product-info, [class*="product-detail"], [class*="product__info"], main section');
          if (target) target.insertBefore(b0, target.firstChild);
          else document.body.appendChild(b0);
        }
        // Re-apply ukrycie cen (Shoper Phoenix może je przywrócić)
        document.querySelectorAll('[class*="price"]:not([class*="price-compare"]):not(#techtor-price0-banner)').forEach(function (el) {
          if (el.closest('#techtor-price0-banner, #techtor-ask-modal')) return;
          el.classList.add('techtor-hide');
        });
        return;
      }

      // ── DOSTĘPNY ──
      if (totalStock > 0) {
        // Czas wysyłki kontrolowany przez delivery_id z PIM — snippet nie nadpisuje

        if (!qi) return; // stepper jeszcze nie wyrenderowany

        var q = parseInt(qi.getAttribute('value') || qi.value, 10) || 1;
        var overLimit = q > totalStock;

        // Banner "Przekroczono ilość" — spójny styl z price0 banerem
        var banner = document.getElementById('techtor-stock-warning');
        if (!banner) {
          banner = document.createElement('div');
          banner.id = 'techtor-stock-warning';
          banner.style.cssText = 'display:none;margin:16px 0 20px;padding:20px 24px;border-radius:12px;background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);border:1px solid #fde68a;text-align:center;position:relative;z-index:100;';
          banner.innerHTML =
            '<div style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:50%;background:#fef3c7;margin-bottom:12px;">' +
              '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
            '</div>' +
            '<p style="margin:0 0 4px;font-size:16px;font-weight:700;color:#1f2937;">Brak wystarczającej ilości towaru</p>' +
            '<p id="techtor-stock-qty" style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.5;">W magazynie posiadamy <strong>' + totalStock + ' szt.</strong> Zapytaj o dostępność większej ilości.</p>';
          var askOLBtn = document.createElement('button');
          askOLBtn.className = 'techtor-ask-btn';
          askOLBtn.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:14px 32px;border-radius:30px;border:none;cursor:pointer;font-weight:700;font-size:15px;background:#d97706;color:#fff;box-shadow:0 4px 14px rgba(217,119,6,0.25);transition:all 0.2s ease;';
          askOLBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Zapytaj o dostępność';
          askOLBtn.onmouseover = function () { askOLBtn.style.background = '#b45309'; };
          askOLBtn.onmouseout = function () { askOLBtn.style.background = '#d97706'; };
          askOLBtn.onclick = function () { showAskModal(sku, productName, parseInt(qi.getAttribute('value') || qi.value, 10) || 1); };
          banner.appendChild(askOLBtn);
          var actionsEl = document.querySelector('.product-actions, [data-module-name="product_actions"], .product-buy, .product__actions, [class*="product-action"], form[action*="cart"]');
          if (actionsEl) actionsEl.parentNode.insertBefore(banner, actionsEl);
          else { var p = qi.closest('section, .product-info, .product-detail, [class*="product"]'); if (p) p.appendChild(banner); }
        }
        banner.style.display = overLimit ? 'block' : 'none';
        // Aktualizuj ilość w banerze przy zmianie wariantu
        var qtyEl = document.getElementById('techtor-stock-qty');
        if (qtyEl) qtyEl.innerHTML = 'W magazynie posiadamy <strong>' + totalStock + ' szt.</strong> Zapytaj o dostępność większej ilości.';

        // Zmiana natywnego pola "Dostępność"
        if (availEl) {
          if (availOverlay) {
            if (overLimit) {
              availOverlay.textContent = 'zapytaj o dostępność';
              availOverlay.style.color = '#b45309';
            } else {
              availOverlay.textContent = 'dostępny';
              availOverlay.style.color = '#16a34a';
            }
          }
        }

        // Blokada koszyka
        buyBtns.forEach(function (bb) {
          var btn = bb.querySelector('.btn_primary') || (bb.classList.contains('btn_primary') ? bb : null);
          if (!btn) return;
          if (overLimit) {
            bb.setAttribute('is-buyable', '0');
            btn.disabled = true; btn.style.opacity = '0.4'; btn.style.pointerEvents = 'none';
            if (!btn.dataset.origText) btn.dataset.origText = btn.textContent;
            btn.textContent = 'Brak wystarczającej ilości';
          } else {
            bb.setAttribute('is-buyable', '1');
            btn.disabled = false; btn.style.opacity = ''; btn.style.pointerEvents = '';
            if (btn.dataset.origText) btn.textContent = btn.dataset.origText;
          }
        });

        // Dynamiczny czas wysyłki
        if (de) {
          var deWrapper = de.closest('[class*="shipping"], [class*="delivery"], [data-module-name*="shipping"]') || de.parentElement;
          var deTarget = deWrapper || de;
          if (overLimit) {
            // Przekroczono stan — ukryj czas dostawy
            deTarget.classList.add('techtor-hide');
            deTarget.dataset.techtorHidden = '1';
          } else {
            // W granicach stanu — pokaż i ustaw czas
            deTarget.classList.remove('techtor-hide');
            delete deTarget.dataset.techtorHidden;
            // Czas wysyłki kontrolowany przez delivery_id z PIM — snippet nie nadpisuje
            de.style.color = '';
          }
        }

        // Event listeners na stepper (raz)
        if (!qi.dataset.techtorBound) {
          qi.dataset.techtorBound = '1';
          new MutationObserver(function () { setTimeout(applyState, 10); })
            .observe(qi, { attributes: true, attributeFilter: ['value'] });
          var qc = qi.closest('product-quantity, [class*="quantity"]');
          if (qc) qc.addEventListener('click', function () { setTimeout(applyState, 50); setTimeout(applyState, 150); });
          qi.querySelectorAll('h-button-stepper, button').forEach(function (btn) {
            btn.addEventListener('click', function () { setTimeout(applyState, 50); setTimeout(applyState, 150); });
          });
          var innerInput = qi.querySelector('input') || (qi.tagName === 'INPUT' ? qi : null);
          if (innerInput) {
            innerInput.addEventListener('change', function () { setTimeout(applyState, 10); });
            innerInput.addEventListener('input', function () { setTimeout(applyState, 10); });
            innerInput.addEventListener('blur', function () { setTimeout(applyState, 10); });
          }
        }
        return;
      }

      // ── NIEDOSTĘPNY (totalStock <= 0) ──

      // Zmiana natywnego pola "Dostępność"
      if (availOverlay) { availOverlay.textContent = 'zapytaj o dostępność'; availOverlay.style.color = '#b45309'; }

      // Ukryj czas dostawy
      if (de) {
        var deWrapper = de.closest('[class*="shipping"], [class*="delivery"], [data-module-name*="shipping"]') || de.parentElement;
        (deWrapper || de).classList.add('techtor-hide');
        (deWrapper || de).dataset.techtorHidden = '1';
      }

      // Ukryj stepper
      if (qi) {
        var qiWrapper = qi.closest('product-quantity, [class*="quantity"], .product-quantity');
        (qiWrapper || qi).classList.add('techtor-hide');
        (qiWrapper || qi).dataset.techtorHidden = '1';
      }

      // Ukryj buy buttons
      buyBtns.forEach(function (bb) {
        bb.classList.add('techtor-hide');
        bb.dataset.techtorHidden = '1';
        var btn = bb.querySelector('.btn_primary') || (bb.classList.contains('btn_primary') ? bb : null);
        if (btn) { btn.classList.add('techtor-hide'); btn.dataset.techtorHidden = '1'; }
      });

      // Baner "Produkt niedostępny" + przycisk "Zapytaj" (raz)
      if (buyArea && !buyArea.querySelector('.techtor-unavailable-banner')) {
        var banner = document.createElement('div');
        banner.className = 'techtor-unavailable-banner';
        banner.style.cssText = 'margin:16px 0 20px;padding:20px 24px;border-radius:12px;background:linear-gradient(135deg,#fef2f2 0%,#fff1f2 100%);border:1px solid #fecaca;text-align:center;';

        banner.innerHTML =
          '<div style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:50%;background:#fee2e2;margin-bottom:12px;">' +
            '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
          '</div>' +
          '<p style="margin:0 0 4px;font-size:16px;font-weight:700;color:#1f2937;">Produkt chwilowo niedostępny</p>' +
          '<p style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.5;">Zostaw dane — powiadomimy Cię, gdy pojawi się w magazynie.</p>';

        var askBtn = document.createElement('button');
        askBtn.className = 'techtor-ask-btn';
        askBtn.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:14px 32px;border-radius:30px;border:none;cursor:pointer;font-weight:700;font-size:15px;background:#dc2626;color:#fff;box-shadow:0 4px 14px rgba(220,38,38,0.25);transition:all 0.2s ease;';
        askBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Zapytaj o dostępność';
        askBtn.onmouseover = function () { askBtn.style.background = '#b91c1c'; askBtn.style.boxShadow = '0 6px 20px rgba(220,38,38,0.35)'; askBtn.style.transform = 'translateY(-1px)'; };
        askBtn.onmouseout = function () { askBtn.style.background = '#dc2626'; askBtn.style.boxShadow = '0 4px 14px rgba(220,38,38,0.25)'; askBtn.style.transform = ''; };
        askBtn.onclick = function () { showAskModal(sku, productName); };

        banner.appendChild(askBtn);
        buyArea.insertBefore(banner, buyArea.firstChild);
      }
    }

    // ── Auto-wybór pierwszego wariantu (np. "1 mb" zamiast "Wybierz") ──
    var variantAutoSelected = false;
    var variantAttempts = 0;
    var variantMaxAttempts = 30; // 30 × 500ms = 15s max

    function findSelectsWithWybierz() {
      var found = [];
      // Szukaj WSZYSTKICH selectów na stronie
      document.querySelectorAll('select').forEach(function (s) {
        if (s.options.length > 1) {
          var first = s.options[0];
          if (first && /wybierz/i.test(first.textContent.trim()) && s.selectedIndex === 0) {
            found.push(s);
          }
        }
      });
      return found;
    }

    function triggerSelectChange(sel) {
      // Metoda 1: natywny setter (obchodzi React/Vue/Angular)
      var nativeSetter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
      if (nativeSetter && nativeSetter.set) {
        nativeSetter.set.call(sel, sel.options[1].value);
      }
      sel.selectedIndex = 1;
      // Metoda 2: pełny zestaw eventów
      sel.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
      sel.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
      // Metoda 3: MouseEvent (niektóre frameworki słuchają tylko tych)
      try {
        sel.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        sel.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
        sel.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      } catch(e) {}
      // Metoda 4: focus/blur
      sel.focus();
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      sel.blur();
    }

    function autoSelectFirstVariant() {
      if (variantAutoSelected) return;
      variantAttempts++;
      if (variantAttempts > variantMaxAttempts) {
        dbg('Auto-select: brak selecta po ' + variantMaxAttempts + ' próbach');
        variantAutoSelected = true; // przestań szukać
        return;
      }

      var selects = findSelectsWithWybierz();
      if (selects.length === 0) {
        dbg('Auto-select: brak selecta z "Wybierz" (próba ' + variantAttempts + ')');
        return; // spróbuj ponownie w następnym cyklu
      }

      selects.forEach(function (sel) {
        var optText = sel.options[1].textContent.trim();
        triggerSelectChange(sel);
        dbg('Auto-select variant: "' + optText + '" (próba ' + variantAttempts + ')');
      });
      variantAutoSelected = true;
    }

    // Uruchom natychmiast + co 500ms (łapie elementy dorenderowane przez Shoper)
    applyState();
    // Auto-wybór wariantu — w głównym loopie co 500ms
    window._tInterval = setInterval(function () {
      applyState();
      if (!variantAutoSelected) autoSelectFirstVariant();
    }, 500);
  }

  // ── Debug ──
  var DEBUG = location.hash.includes('debug') || localStorage.getItem('techtor_debug') === '1';
  function dbg(msg) {
    if (!DEBUG) return;
    var panel = document.getElementById('techtor-debug-panel');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'techtor-debug-panel';
      panel.style.cssText = 'position:fixed;bottom:10px;right:10px;z-index:99999;background:#1f2937;color:#10b981;font-family:monospace;font-size:11px;padding:12px 16px;border-radius:12px;max-width:400px;max-height:300px;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.5);';
      panel.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><b style="color:#f59e0b;">TECHTOR Stock Debug</b><button onclick="this.parentNode.parentNode.remove();localStorage.removeItem(\'techtor_debug\')" style="background:#374151;border:none;color:#9ca3af;border-radius:4px;padding:2px 8px;cursor:pointer;font-size:10px;">Zamknij</button></div><div id="techtor-debug-log"></div>';
      document.body.appendChild(panel);
    }
    var log = document.getElementById('techtor-debug-log');
    var line = document.createElement('div');
    line.style.cssText = 'padding:2px 0;border-bottom:1px solid #374151;';
    line.textContent = msg;
    log.appendChild(line);
    console.log('[TECHTOR]', msg);
  }

  // ── Cleanup wszystkich elementów TECHTOR ──
  function cleanupAll() {
    if (window._tInterval) { clearInterval(window._tInterval); window._tInterval = null; }
    ['techtor-stock-warning', 'techtor-ask-overlimit', 'techtor-ask-modal', 'techtor-price0-banner'].forEach(function (id) {
      var el = document.getElementById(id); if (el) el.remove();
    });
    document.querySelectorAll('.techtor-ask-btn, .techtor-unavailable-banner, .techtor-price0-banner').forEach(function (el) { el.remove(); });
    document.querySelectorAll('[data-techtor-hidden]').forEach(function (el) {
      el.classList.remove('techtor-hide');
      el.style.display = ''; delete el.dataset.techtorHidden;
    });
    document.querySelectorAll('.techtor-hide').forEach(function (el) {
      el.classList.remove('techtor-hide');
    });
    var oldCss = document.getElementById('techtor-unavailable-css');
    if (oldCss) oldCss.remove();
    document.querySelectorAll('[data-techtor-bound]').forEach(function (el) { delete el.dataset.techtorBound; });
    // Przywróć oryginalne teksty
    document.querySelectorAll('[data-orig-text]').forEach(function (el) {
      el.textContent = el.dataset.origText;
      el.style.color = '';
      delete el.dataset.origText;
    });
  }

  // ── Pełny restart (nowy produkt) ──
  function fullRestart() {
    dbg('=== RESTART (nowy produkt) ===');
    runId = window._tRunId = (window._tRunId || 0) + 1;
    cleanupAll();
    getStockData(function (freshData) {
      if (window._tRunId !== runId) return;
      startLoop(freshData);
    });
  }

  // ── Init ──
  try { sessionStorage.removeItem('techtor_sd'); } catch(e) {}
  getStockData(function (stockData) {
    if (!stockData) return;
    if (window._tRunId !== runId) return;
    dbg('Stock data loaded, starting loop [id=' + runId + ']');
    startLoop(stockData);
  });

  // Globalny rerun — SPA nawigacja (wywoływany z img onload w opisie)
  window._tRerun = fullRestart;

  // Nasłuchuj zmianę URL — Shoper Phoenix SPA zmienia URL bez przeładowania
  var lastUrl = location.href;
  setInterval(function () {
    if (location.href !== lastUrl) {
      dbg('URL changed: ' + lastUrl + ' → ' + location.href);
      lastUrl = location.href;
      // Poczekaj aż Phoenix wyrenderuje nowy produkt
      setTimeout(fullRestart, 300);
    }
  }, 250);
})();
