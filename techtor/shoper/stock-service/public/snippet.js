(function () {
  'use strict';

  var API_URL = 'https://stock.techtor.pl/api/stock-data.json';
  var attached = false;

  function getSku() {
    // 1. data-product-code="sku"
    var el = document.querySelector('[data-product-code="sku"]');
    if (el) return el.textContent.trim();
    // 2. microdata/JSON w stronie
    var m = document.body.innerHTML.match(/"sku"\s*:\s*"([^"]+)"/);
    if (m) return m[1];
    return null;
  }

  fetch(API_URL)
    .then(function (r) { return r.json(); })
    .then(function (stockData) {

      function tryAttach() {
        if (attached) return;

        var sku = getSku();
        if (!sku || !stockData[sku]) return;

        var qi = document.querySelector('h-input-stepper.product-quantity__input, .product-quantity__input');
        if (!qi) return;

        var de = document.querySelector('[data-shipping-time]');
        if (!de) return;

        attached = true;
        var stockTechtor = stockData[sku];

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

      // Próbuj od razu i obserwuj DOM
      tryAttach();
      var obs = new MutationObserver(function () { if (!attached) tryAttach(); else obs.disconnect(); });
      obs.observe(document.documentElement, { childList: true, subtree: true });
    })
    .catch(function () {});
})();
