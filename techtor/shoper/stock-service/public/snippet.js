(function () {
  'use strict';

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
    var el = document.querySelector('[data-product-code="sku"]');
    if (el) return el.textContent.trim();
    var m = document.body.innerHTML.match(/"sku"\s*:\s*"([^"]+)"/);
    if (m) return m[1];
    return null;
  }

  function getProductName() {
    var el = document.querySelector('h1, [data-product-name]');
    return el ? el.textContent.trim() : '';
  }

  function showAskModal(sku, productName) {
    if (document.getElementById('techtor-ask-modal')) return;

    var overlay = document.createElement('div');
    overlay.id = 'techtor-ask-modal';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;';

    var inputStyle = 'width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:10px;font-size:14px;box-sizing:border-box;';
    var rowStyle = 'display:flex;gap:10px;';

    overlay.innerHTML =
      '<div style="background:#fff;border-radius:16px;padding:28px;max-width:520px;width:100%;position:relative;box-shadow:0 20px 60px rgba(0,0,0,0.3);max-height:90vh;overflow-y:auto;">' +
        '<button id="techtor-ask-close" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#666;">&times;</button>' +
        '<h3 style="margin:0 0 4px;font-size:18px;color:#1f2937;">Zapytaj o dostępność</h3>' +
        '<p style="margin:0 0 16px;font-size:13px;color:#6b7280;">Produkt: <strong>' + productName + '</strong> (' + sku + ')</p>' +
        '<form id="techtor-ask-form">' +
          '<div style="' + rowStyle + '">' +
            '<div style="flex:1;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">NIP</label>' +
              '<input name="nip" placeholder="Wpisz NIP — dane uzupełnią się automatycznie" maxlength="13" style="' + inputStyle + '">' +
            '</div>' +
          '</div>' +
          '<div id="techtor-nip-status" style="display:none;padding:6px 12px;border-radius:6px;font-size:12px;margin-bottom:10px;"></div>' +
          '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Firma</label>' +
          '<input name="company" placeholder="Nazwa firmy" style="' + inputStyle + '">' +
          '<div style="' + rowStyle + '">' +
            '<div style="flex:1;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Imię i nazwisko *</label>' +
              '<input name="name" placeholder="Imię i nazwisko" required style="' + inputStyle + '">' +
            '</div>' +
            '<div style="flex:1;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Telefon</label>' +
              '<input name="phone" type="tel" placeholder="np. 600 100 200" style="' + inputStyle + '">' +
            '</div>' +
          '</div>' +
          '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Email *</label>' +
          '<input name="email" type="email" placeholder="Adres e-mail" required style="' + inputStyle + '">' +
          '<div style="' + rowStyle + '">' +
            '<div style="flex:2;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Ulica</label>' +
              '<input name="street" placeholder="Ulica i numer" style="' + inputStyle + '">' +
            '</div>' +
            '<div style="flex:1;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Kod pocztowy</label>' +
              '<input name="zip" placeholder="00-000" style="' + inputStyle + '">' +
            '</div>' +
          '</div>' +
          '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Miasto</label>' +
          '<input name="city" placeholder="Miasto" style="' + inputStyle + '">' +
          '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Wiadomość</label>' +
          '<textarea name="message" rows="3" style="' + inputStyle + 'resize:vertical;">' +
            'Dzień dobry,\nchciałbym zapytać o dostępność produktu ' + productName + ' (' + sku + ').\nProszę o kontakt.' +
          '</textarea>' +
          '<input name="_hp" type="text" style="position:absolute;left:-9999px;opacity:0;height:0;" tabindex="-1" autocomplete="off">' +
          '<button type="submit" style="width:100%;padding:14px;border:none;border-radius:8px;background:#dc2626;color:#fff;font-size:16px;font-weight:700;cursor:pointer;margin-top:6px;transition:background 0.2s;">Wyślij zapytanie</button>' +
        '</form>' +
        '<div id="techtor-ask-success" style="display:none;text-align:center;padding:24px 0;">' +
          '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" style="margin:0 auto 12px;display:block;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
          '<p style="font-size:16px;font-weight:600;color:#1f2937;margin:0 0 4px;">Zapytanie wysłane!</p>' +
          '<p style="font-size:13px;color:#6b7280;margin:0;">Odpowiemy najszybciej jak to możliwe.</p>' +
        '</div>' +
      '</div>';

    document.body.appendChild(overlay);
    document.getElementById('techtor-ask-close').onclick = function () { overlay.remove(); };
    overlay.onclick = function (e) { if (e.target === overlay) overlay.remove(); };

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
                if (d.name) form.company.value = d.name;
                if (d.street) form.street.value = d.street;
                if (d.postalCode) form.zip.value = d.postalCode;
                if (d.city) form.city.value = d.city;
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

    document.getElementById('techtor-ask-form').onsubmit = function (e) {
      e.preventDefault();
      var form = e.target;
      var btn = form.querySelector('button[type="submit"]');
      btn.textContent = 'Wysyłanie...'; btn.disabled = true; btn.style.background = '#9ca3af';
      fetch('https://stock.techtor.pl/api/ask', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: form.name.value, email: form.email.value,
          _hp: form._hp ? form._hp.value : '',
          phone: form.phone.value, nip: form.nip.value,
          company: form.company.value, street: form.street.value,
          zip: form.zip.value, city: form.city.value,
          message: form.message.value,
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
    var productName = '';

    function applyState() {
      // Sprawdź czy nowszy run nie zastąpił tego
      if (window._tRunId !== runId) {
        clearInterval(window._tInterval);
        return;
      }

      // Znajdź SKU (raz)
      if (!sku) {
        sku = getSku();
        if (!sku) return; // czekaj
        stockTechtor = stockData[sku] || 0;
        totalStock = stockData[sku + '__total'] || 0;
        productName = getProductName();
        dbg('SKU: ' + sku + ' techtor=' + stockTechtor + ' total=' + totalStock);
      }

      // Znajdź elementy DOM (mogą pojawić się w różnym czasie)
      var qi = document.querySelector('h-input-stepper.product-quantity__input, .product-quantity__input, h-input-stepper, [class*="quantity__input"], input[name="quantity"], input[type="number"][min]');
      var de = document.querySelector('[data-shipping-time]');
      var buyBtns = document.querySelectorAll('buy-button, [class*="buy-button"], .product-buy__button, button[type="submit"][class*="btn_primary"]');
      if (buyBtns.length === 0) buyBtns = document.querySelectorAll('.btn_primary');
      var buyArea = document.querySelector('buy-button, .product-actions, [data-module-name="product_actions"], .product-buy, .product__actions, [class*="product-action"], form[action*="cart"], .product-detail__actions');

      if (!de && !qi && !buyArea) return; // DOM jeszcze nie gotowy

      // ── DOSTĘPNY ──
      if (totalStock > 0) {
        if (de) {
          if (stockTechtor > 0) {
            de.textContent = '24 godziny'; de.style.color = '';
          } else {
            de.textContent = '48 godzin'; de.style.color = '#b45309';
          }
        }

        if (!qi) return; // stepper jeszcze nie wyrenderowany

        var q = parseInt(qi.getAttribute('value') || qi.value, 10) || 1;
        var overLimit = q > totalStock;

        // Banner "Przekroczono ilość"
        var banner = document.getElementById('techtor-stock-warning');
        if (!banner) {
          banner = document.createElement('div');
          banner.id = 'techtor-stock-warning';
          banner.style.cssText = 'display:none;padding:14px 20px;margin:12px 0 16px;border-radius:12px;background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);border:1px solid #fde68a;color:#92400e;font-size:14px;font-weight:600;line-height:1.5;';
          banner.innerHTML = '<div style="display:flex;align-items:center;gap:10px;">' +
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
            '<span>Maksymalna dostępna ilość: <strong>' + totalStock + ' szt.</strong></span>' +
            '</div>';
          var actionsEl = document.querySelector('.product-actions, [data-module-name="product_actions"], .product-buy, .product__actions, [class*="product-action"], form[action*="cart"]');
          if (actionsEl) actionsEl.parentNode.insertBefore(banner, actionsEl);
          else { var p = qi.closest('section, .product-info, .product-detail, [class*="product"]'); if (p) p.appendChild(banner); }
        }
        banner.style.display = overLimit ? 'block' : 'none';

        // Przycisk "Zapytaj" przy overlimit
        var askOL = document.getElementById('techtor-ask-overlimit');
        if (overLimit && !askOL) {
          askOL = document.createElement('button');
          askOL.id = 'techtor-ask-overlimit';
          askOL.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:8px;padding:14px 28px;border-radius:30px;border:none;cursor:pointer;font-weight:700;font-size:15px;background:#dc2626;color:#fff;margin:12px 0 16px;box-shadow:0 4px 14px rgba(220,38,38,0.25);width:100%;transition:all 0.2s ease;';
          askOL.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Potrzebujesz więcej? Zapytaj o dostępność';
          askOL.onmouseover = function () { askOL.style.background = '#b91c1c'; askOL.style.boxShadow = '0 6px 20px rgba(220,38,38,0.35)'; askOL.style.transform = 'translateY(-1px)'; };
          askOL.onmouseout = function () { askOL.style.background = '#dc2626'; askOL.style.boxShadow = '0 4px 14px rgba(220,38,38,0.25)'; askOL.style.transform = ''; };
          askOL.onclick = function () { showAskModal(sku, productName); };
          if (banner && banner.parentNode) banner.parentNode.insertBefore(askOL, banner.nextSibling);
        }
        if (askOL) askOL.style.display = overLimit ? 'flex' : 'none';

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
            if (deTarget.style.display !== 'none') {
              deTarget.style.display = 'none';
              deTarget.dataset.techtorHidden = '1';
            }
          } else {
            // W granicach stanu — pokaż i ustaw czas
            if (deTarget.dataset.techtorHidden) {
              deTarget.style.display = '';
              delete deTarget.dataset.techtorHidden;
            }
            if (stockTechtor > 0 && q <= stockTechtor) {
              de.textContent = '24 godziny'; de.style.color = '';
            } else {
              de.textContent = '48 godzin'; de.style.color = '#b45309';
            }
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

      // Ukryj czas dostawy (nie pokazujemy "niedostępny" — baner wystarczy)
      if (de) {
        var deWrapper = de.closest('[class*="shipping"], [class*="delivery"], [data-module-name*="shipping"]') || de.parentElement;
        if (deWrapper && deWrapper.style.display !== 'none') {
          deWrapper.style.display = 'none';
          deWrapper.dataset.techtorHidden = '1';
        } else if (de.style.display !== 'none') {
          de.style.display = 'none';
          de.dataset.techtorHidden = '1';
        }
      }

      // Ukryj stepper (Shoper może go wyrenderować po naszym pierwszym runie)
      if (qi) {
        var qiWrapper = qi.closest('product-quantity, [class*="quantity"], .product-quantity');
        var hideEl = qiWrapper || qi;
        if (hideEl.style.display !== 'none') {
          hideEl.style.display = 'none';
          hideEl.dataset.techtorHidden = '1';
        }
      }

      // Ukryj każdy buy button (mogą się pojawić w kolejnych renderach Shoper)
      buyBtns.forEach(function (bb) {
        var btn = bb.querySelector('.btn_primary') || (bb.classList.contains('btn_primary') ? bb : null);
        if (btn && btn.style.display !== 'none') {
          btn.disabled = true; btn.style.opacity = '0.4'; btn.style.pointerEvents = 'none';
          btn.style.display = 'none';
          btn.dataset.techtorHidden = '1';
        }
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

    // Uruchom natychmiast + co 500ms (łapie elementy dorenderowane przez Shoper)
    applyState();
    window._tInterval = setInterval(applyState, 500);
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

  // ── Init ──
  try { sessionStorage.removeItem('techtor_sd'); } catch(e) {}
  getStockData(function (stockData) {
    if (!stockData) return;
    if (window._tRunId !== runId) return;
    dbg('Stock data loaded, starting loop [id=' + runId + ']');
    startLoop(stockData);

    // Globalny rerun — SPA nawigacja
    window._tRerun = function () {
      dbg('=== RERUN (SPA) ===');
      runId = window._tRunId = (window._tRunId || 0) + 1;
      if (window._tInterval) { clearInterval(window._tInterval); window._tInterval = null; }
      // Cleanup
      ['techtor-stock-warning', 'techtor-ask-overlimit', 'techtor-ask-modal'].forEach(function (id) {
        var el = document.getElementById(id); if (el) el.remove();
      });
      document.querySelectorAll('.techtor-ask-btn, .techtor-unavailable-banner').forEach(function (el) { el.remove(); });
      document.querySelectorAll('[data-techtor-hidden]').forEach(function (el) {
        el.style.display = ''; delete el.dataset.techtorHidden;
      });
      document.querySelectorAll('[data-techtor-bound]').forEach(function (el) { delete el.dataset.techtorBound; });
      sessionStorage.removeItem('techtor_sd');
      getStockData(function (freshData) {
        if (window._tRunId !== runId) return;
        startLoop(freshData);
      });
    };
  });
})();
