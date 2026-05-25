(function () {
  'use strict';

  var API_URL = 'https://stock.techtor.pl/api/stock-data.json';
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
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';

    overlay.innerHTML =
      '<div style="background:#fff;border-radius:16px;padding:32px;max-width:480px;width:90%;position:relative;box-shadow:0 20px 60px rgba(0,0,0,0.3);">' +
        '<button id="techtor-ask-close" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#666;">&times;</button>' +
        '<h3 style="margin:0 0 8px;font-size:18px;color:#1f2937;">Zapytaj o dostępność</h3>' +
        '<p style="margin:0 0 20px;font-size:13px;color:#6b7280;">Produkt: <strong>' + productName + '</strong> (' + sku + ')</p>' +
        '<form id="techtor-ask-form">' +
          '<input name="name" placeholder="Imię i nazwisko" required style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:12px;font-size:14px;box-sizing:border-box;">' +
          '<input name="email" type="email" placeholder="Adres e-mail" required style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:12px;font-size:14px;box-sizing:border-box;">' +
          '<input name="phone" type="tel" placeholder="Telefon (opcjonalnie)" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:12px;font-size:14px;box-sizing:border-box;">' +
          '<textarea name="message" rows="3" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:16px;font-size:14px;resize:vertical;box-sizing:border-box;">' +
            'Dzień dobry,\nchciałbym zapytać o dostępność produktu ' + productName + ' (' + sku + ').\nProszę o kontakt.' +
          '</textarea>' +
          '<input name="_hp" type="text" style="position:absolute;left:-9999px;opacity:0;height:0;" tabindex="-1" autocomplete="off">' +
          '<button type="submit" style="width:100%;padding:12px;border:none;border-radius:8px;background:#b45309;color:#fff;font-size:15px;font-weight:600;cursor:pointer;">Wyślij zapytanie</button>' +
        '</form>' +
        '<div id="techtor-ask-success" style="display:none;text-align:center;padding:20px 0;">' +
          '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" style="margin:0 auto 12px;display:block;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
          '<p style="font-size:16px;font-weight:600;color:#1f2937;margin:0 0 4px;">Zapytanie wysłane!</p>' +
          '<p style="font-size:13px;color:#6b7280;margin:0;">Odpowiemy najszybciej jak to możliwe.</p>' +
        '</div>' +
      '</div>';

    document.body.appendChild(overlay);

    document.getElementById('techtor-ask-close').onclick = function () { overlay.remove(); };
    overlay.onclick = function (e) { if (e.target === overlay) overlay.remove(); };

    document.getElementById('techtor-ask-form').onsubmit = function (e) {
      e.preventDefault();
      var form = e.target;
      var btn = form.querySelector('button[type="submit"]');
      btn.textContent = 'Wysyłanie...';
      btn.disabled = true;

      var data = {
        name: form.name.value,
        email: form.email.value,
        _hp: form._hp ? form._hp.value : '',
        phone: form.phone.value,
        message: form.message.value,
        sku: sku,
        product: productName,
        url: window.location.href,
      };

      fetch('https://stock.techtor.pl/api/ask', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.ok) {
            form.style.display = 'none';
            document.getElementById('techtor-ask-success').style.display = 'block';
            setTimeout(function () { overlay.remove(); }, 3000);
          } else {
            btn.textContent = 'Błąd — spróbuj ponownie';
            btn.disabled = false;
          }
        })
        .catch(function () {
          btn.textContent = 'Błąd — spróbuj ponownie';
          btn.disabled = false;
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

        // Dynamiczny czas wysyłki
        var qi = document.querySelector('h-input-stepper.product-quantity__input, .product-quantity__input');
        var de = document.querySelector('[data-shipping-time]');

        if (qi && de && stockTechtor > 0) {
          attached = true;

          function upd() {
            var q = parseInt(qi.getAttribute('value') || qi.value, 10) || 1;
            if (q <= stockTechtor) {
              de.textContent = '24 godziny';
              de.style.color = '';
            } else {
              de.textContent = '48 godzin';
              de.style.color = '#b45309';
            }
          }

          new MutationObserver(function () { setTimeout(upd, 10); })
            .observe(qi, { attributes: true, attributeFilter: ['value'] });
          var qc = qi.closest('product-quantity, [class*="quantity"]');
          if (qc) qc.addEventListener('click', function () { setTimeout(upd, 50); });
          upd();
        }

        // "Zapytaj o dostępność" gdy produkt niedostępny (oba magazyny = 0)
        if (stockTechtor <= 0 && !qi) {
          attached = true;
          var buyArea = document.querySelector('buy-button, .product-actions, [data-module-name="product_actions"]');
          if (buyArea && !buyArea.querySelector('.techtor-ask-btn')) {
            var productName = getProductName();
            var askBtn = document.createElement('button');
            askBtn.className = 'techtor-ask-btn';
            askBtn.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;padding:12px 24px;border-radius:30px;border:none;cursor:pointer;font-weight:600;font-size:14px;background-color:#b45309;color:#fff;margin-top:8px;';
            askBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Zapytaj o dostępność';
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
