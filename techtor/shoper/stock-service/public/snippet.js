(function () {
  'use strict';

  var API_URL = 'https://stock.techtor.pl/api/stock-data.json';
  var VAT_API = 'https://vat.techtor.pl/api/gus';
  if (window._techtorAttached) return;
  window._techtorAttached = true;
  var attached = false;

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
          // NIP + autofill
          '<div style="' + rowStyle + '">' +
            '<div style="flex:1;">' +
              '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">NIP</label>' +
              '<input name="nip" placeholder="Wpisz NIP — dane uzupełnią się automatycznie" maxlength="13" style="' + inputStyle + '">' +
            '</div>' +
          '</div>' +
          '<div id="techtor-nip-status" style="display:none;padding:6px 12px;border-radius:6px;font-size:12px;margin-bottom:10px;"></div>' +
          // Firma
          '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Firma</label>' +
          '<input name="company" placeholder="Nazwa firmy" style="' + inputStyle + '">' +
          // Imię + Telefon
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
          // Email
          '<label style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block;">Email *</label>' +
          '<input name="email" type="email" placeholder="Adres e-mail" required style="' + inputStyle + '">' +
          // Adres
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
          // Wiadomość
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

    // NIP autofill
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
            .catch(function () {
              nipStatus.style.display = 'none';
            });
        }, 300);
      } else {
        nipStatus.style.display = 'none';
      }
    });

    // Submit
    document.getElementById('techtor-ask-form').onsubmit = function (e) {
      e.preventDefault();
      var form = e.target;
      var btn = form.querySelector('button[type="submit"]');
      btn.textContent = 'Wysyłanie...';
      btn.disabled = true;
      btn.style.background = '#9ca3af';

      fetch('https://stock.techtor.pl/api/ask', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: form.name.value,
          email: form.email.value,
          _hp: form._hp ? form._hp.value : '',
          phone: form.phone.value,
          nip: form.nip.value,
          company: form.company.value,
          street: form.street.value,
          zip: form.zip.value,
          city: form.city.value,
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
            btn.disabled = false;
            btn.style.background = '#dc2626';
          }
        })
        .catch(function () {
          btn.textContent = 'Błąd — spróbuj ponownie';
          btn.disabled = false;
          btn.style.background = '#dc2626';
        });
    };
  }

  fetch(API_URL)
    .then(function (r) { return r.json(); })
    .then(function (stockData) {

      function tryAttach() {
        if (attached) return;

        var sku = getSku();
        if (!sku) return;

        var stockTechtor = stockData[sku] || 0;
        var totalStock = stockData[sku + '__total'] || 0;

        var qi = document.querySelector('h-input-stepper.product-quantity__input, .product-quantity__input');
        var de = document.querySelector('[data-shipping-time]');
        var buyBtns = document.querySelectorAll('buy-button');
        var productName = getProductName();

        if (qi && totalStock > 0) {
          attached = true;

          var banner = document.getElementById('techtor-stock-warning');
          if (!banner) {
            banner = document.createElement('div');
            banner.id = 'techtor-stock-warning';
            banner.style.cssText = 'display:none;padding:10px 16px;margin:8px 0;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:13px;font-weight:500;';
            banner.textContent = 'Maksymalna dostępna ilość: ' + totalStock + ' szt.';
            var actionsEl = document.querySelector('.product-actions, [data-module-name="product_actions"]');
            if (actionsEl) actionsEl.parentNode.insertBefore(banner, actionsEl);
          }

          function getQty() {
            return parseInt(qi.getAttribute('value') || qi.value, 10) || 1;
          }

          function upd() {
            var q = getQty();
            var overLimit = q > totalStock;

            banner.style.display = overLimit ? 'block' : 'none';

            // Przycisk "Zapytaj o dostępność" gdy przekroczono max
            var askOverLimit = document.getElementById('techtor-ask-overlimit');
            if (overLimit && !askOverLimit) {
              askOverLimit = document.createElement('button');
              askOverLimit.id = 'techtor-ask-overlimit';
              askOverLimit.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;padding:14px 28px;border-radius:30px;border:none;cursor:pointer;font-weight:700;font-size:15px;background:#dc2626;color:#fff;margin-top:8px;transition:background 0.2s;box-shadow:0 4px 12px rgba(220,38,38,0.3);width:100%;';
              askOverLimit.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Potrzebujesz więcej? Zapytaj o dostępność';
              askOverLimit.onmouseover = function () { askOverLimit.style.background = '#b91c1c'; };
              askOverLimit.onmouseout = function () { askOverLimit.style.background = '#dc2626'; };
              askOverLimit.onclick = function () { showAskModal(sku, productName); };
              // Wstaw po bannerze lub po product-actions
              var insertParent = banner.parentNode || document.querySelector('.product-actions, [data-module-name="product_actions"]');
              if (insertParent) {
                if (banner.parentNode) {
                  banner.parentNode.insertBefore(askOverLimit, banner.nextSibling);
                } else {
                  insertParent.appendChild(askOverLimit);
                }
              }
            }
            if (askOverLimit) askOverLimit.style.display = overLimit ? 'flex' : 'none';

            buyBtns.forEach(function (bb) {
              var btn = bb.querySelector('.btn_primary');
              if (!btn) return;
              if (overLimit) {
                bb.setAttribute('is-buyable', '0');
                btn.disabled = true;
                btn.style.opacity = '0.4';
                btn.style.pointerEvents = 'none';
                if (!btn.dataset.origText) btn.dataset.origText = btn.textContent;
                btn.textContent = 'Brak wystarczającej ilości';
              } else {
                bb.setAttribute('is-buyable', '1');
                btn.disabled = false;
                btn.style.opacity = '';
                btn.style.pointerEvents = '';
                if (btn.dataset.origText) btn.textContent = btn.dataset.origText;
              }
            });

            if (de && stockTechtor > 0 && !overLimit) {
              if (q <= stockTechtor) {
                de.textContent = '24 godziny';
                de.style.color = '';
              } else {
                de.textContent = '48 godzin';
                de.style.color = '#b45309';
              }
            }
          }

          new MutationObserver(function () { setTimeout(upd, 10); })
            .observe(qi, { attributes: true, attributeFilter: ['value'] });
          var qc = qi.closest('product-quantity, [class*="quantity"]');
          if (qc) qc.addEventListener('click', function () { setTimeout(upd, 50); setTimeout(upd, 150); });
          qi.querySelectorAll('h-button-stepper, button').forEach(function (btn) {
            btn.addEventListener('click', function () { setTimeout(upd, 50); setTimeout(upd, 150); });
          });
          var innerInput = qi.querySelector('input');
          if (innerInput) {
            innerInput.addEventListener('change', function () { setTimeout(upd, 10); });
            innerInput.addEventListener('blur', function () { setTimeout(upd, 10); });
          }
          upd();
        }

        if (totalStock <= 0 && !qi) {
          attached = true;
          var buyArea = document.querySelector('buy-button, .product-actions, [data-module-name="product_actions"]');
          if (buyArea && !buyArea.querySelector('.techtor-ask-btn')) {
            var productName = getProductName();
            var askBtn = document.createElement('button');
            askBtn.className = 'techtor-ask-btn';
            askBtn.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;padding:14px 28px;border-radius:30px;border:none;cursor:pointer;font-weight:700;font-size:15px;background:#dc2626;color:#fff;margin-top:8px;transition:background 0.2s;box-shadow:0 4px 12px rgba(220,38,38,0.3);';
            askBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Zapytaj o dostępność';
            askBtn.onmouseover = function () { askBtn.style.background = '#b91c1c'; };
            askBtn.onmouseout = function () { askBtn.style.background = '#dc2626'; };
            askBtn.onclick = function () { showAskModal(sku, productName); };
            buyArea.appendChild(askBtn);
          }
        }
      }

      tryAttach();
      var obs = new MutationObserver(function () { if (!attached) tryAttach(); else obs.disconnect(); });
      obs.observe(document.documentElement, { childList: true, subtree: true });
    })
    .catch(function () {});
})();
