"use strict";var TechtorWidget=(()=>{function h(){let r=document.querySelector('[data-product-code="sku"]');if(r){let o=r.textContent?.trim();if(o)return o}let t=document.querySelector("product-codes");if(t?.shadowRoot){let o=t.shadowRoot.querySelector('[data-product-code="sku"]');if(o?.textContent?.trim())return o.textContent.trim()}let n=(document.body?.innerHTML||"").match(/"sku"\s*:\s*"([^"]+)"/);return n?.[1]?n[1]:null}function L(r){return r.length===11&&r[0]==="W"?r.substring(0,4)+"000"+r.substring(7):null}var B="[TECHTOR]",j=!1;function O(){try{j=localStorage.getItem("techtor_debug")==="1"||location.hash.includes("debug")}catch{}}function a(...r){j&&console.log(B,...r)}function A(...r){console.warn(B,...r)}var E=class{constructor(t){this.api=t;this.listeners=[];this.navCleanup=null;a("EventBusAdapter: zainicjalizowany"),typeof localStorage<"u"&&localStorage.getItem("techtor_debug")==="1"&&this.logAllEvents()}onProductChange(t){let e=()=>{let o=h();o&&t(o)};for(let o of["product.stockChanged","product.priceChanged","product.variantChanged"])this.api.eventBus.on(o,e),this.listeners.push({event:o,cb:e});let n=document.querySelector('[data-product-code="sku"]');if(n){let o=new MutationObserver(()=>{let i=n.textContent?.trim();i&&t(i)});o.observe(n,{childList:!0,characterData:!0,subtree:!0}),this.listeners.push({event:"__mo_sku",cb:()=>o.disconnect()})}}onQuantityChange(t){let e=o=>{let i=o;if(typeof i?.quantity=="number")t(i.quantity);else{let s=document.querySelector('h-input-stepper, [class*="quantity__input"]');if(s){let d=parseInt(s.getAttribute("value")||"1",10);t(d||1)}}};this.api.eventBus.on("product.quantityChanged",e),this.listeners.push({event:"product.quantityChanged",cb:e});let n=document.querySelector("h-input-stepper");if(n){let o=new MutationObserver(()=>{let i=parseInt(n.getAttribute("value")||"1",10);t(i||1)});o.observe(n,{attributes:!0,attributeFilter:["value"]}),this.listeners.push({event:"__mo_qty",cb:()=>o.disconnect()}),n.querySelectorAll("h-button-stepper, button").forEach(i=>{let s=()=>setTimeout(()=>{let d=parseInt(n.getAttribute("value")||"1",10);t(d||1)},50);i.addEventListener("click",s)})}}onNavigation(t){for(let s of["page.changed","navigation.completed","route.changed","rendered"])this.api.eventBus.on(s,()=>{a("Navigation event:",s),setTimeout(t,300)}),this.listeners.push({event:s,cb:()=>{}});let e=()=>setTimeout(t,300);window.addEventListener("popstate",e);let n=history.pushState.bind(history);history.pushState=function(...s){n(...s),setTimeout(t,300)};let o=location.href,i=setInterval(()=>{location.href!==o&&(o=location.href,setTimeout(t,300))},500);this.navCleanup=()=>{window.removeEventListener("popstate",e),clearInterval(i),history.pushState=n}}destroy(){for(let{event:t,cb:e}of this.listeners)if(t.startsWith("__mo_"))e();else try{this.api.eventBus.off(t,e)}catch{}this.listeners=[],this.navCleanup?.(),a("EventBusAdapter: zniszczony")}logAllEvents(){let t=["product.stockChanged","product.priceChanged","product.quantityChanged","product.variantChanged","basket.itemAddedToBasket","basket.basketUpdated","page.changed","navigation.completed","route.changed","rendered"];for(let e of t)this.api.eventBus.on(e,n=>{console.log(`[TECHTOR EVENT] ${e}`,n)})}};var y=class{constructor(){this.observers=[];this.intervals=[];this.cleanups=[];a("DomAdapter: fallback (brak Event Bus)")}onProductChange(t){let e="",n=()=>{let i=h();i&&i!==e&&(e=i,t(i))},o=document.querySelector('[data-product-code="sku"]');if(o){let i=new MutationObserver(n);i.observe(o,{childList:!0,characterData:!0,subtree:!0}),this.observers.push(i)}this.intervals.push(setInterval(n,1e3)),n()}onQuantityChange(t){let e=-1,n=()=>{let i=document.querySelector('h-input-stepper, [class*="quantity__input"]');if(!i)return;let s=parseInt(i.getAttribute("value")||"1",10)||1;s!==e&&(e=s,t(s))},o=document.querySelector("h-input-stepper");if(o){let i=new MutationObserver(n);i.observe(o,{attributes:!0,attributeFilter:["value"]}),this.observers.push(i),o.querySelectorAll("h-button-stepper, button").forEach(s=>{let d=()=>setTimeout(n,50);s.addEventListener("click",d),this.cleanups.push(()=>s.removeEventListener("click",d))})}this.intervals.push(setInterval(n,1e3)),n()}onNavigation(t){let e=location.href,n=setInterval(()=>{location.href!==e&&(e=location.href,setTimeout(t,300))},500);this.intervals.push(n);let o=()=>setTimeout(t,300);window.addEventListener("popstate",o),this.cleanups.push(()=>window.removeEventListener("popstate",o))}destroy(){for(let t of this.observers)t.disconnect();for(let t of this.intervals)clearInterval(t);for(let t of this.cleanups)t();this.observers=[],this.intervals=[],this.cleanups=[],a("DomAdapter: zniszczony")}};var X="https://stock.techtor.pl/api/stock-data.json",H="techtor_sd",tt=300*1e3,z=null;async function C(){try{let r=sessionStorage.getItem(H);if(r){let{data:t,ts:e}=JSON.parse(r);if(Date.now()-e<tt)return z=t,a("Stock data z cache",Object.keys(t).length,"kluczy"),t}}catch{}try{let t=await(await fetch(X)).json();z=t;try{sessionStorage.setItem(H,JSON.stringify({data:t,ts:Date.now()}))}catch{}return a("Stock data z API",Object.keys(t).length,"kluczy"),t}catch(r){return a("Stock data fetch error:",r),z||{}}}function P(r){let t=z||{},e=r;if(t[r]===void 0){let s=L(r);s&&t[s]!==void 0&&(e=s)}let n=t[e]||0,o=t[e+"__total"]||t[e]||0,i=!!t[e+"__price0"];return{sku:r,stockTechtor:n,totalStock:o,isPrice0:i}}function $(){try{sessionStorage.removeItem(H)}catch{}}function D(r,t){return r.isPrice0?"price-zero":r.totalStock<=0?"out-of-stock":t>r.totalStock?"overlimit":t>r.stockTechtor?"available-tarnawa":"available"}function N(r,t,e,n){r!==t&&a(`Stan: ${r} \u2192 ${t} (sku=${e.sku}, techtor=${e.stockTechtor}, total=${e.totalStock}, qty=${n}, price0=${e.isPrice0})`)}var R=`
.techtor-hide { display: none !important; }
.techtor-banner {
  margin: 16px 0 12px;
  padding: 16px 20px;
  border-radius: 12px;
  text-align: center;
}
.techtor-banner--warning {
  background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
  border: 1px solid #fde68a;
}
.techtor-banner--error {
  background: linear-gradient(135deg, #fef2f2 0%, #fff1f2 100%);
  border: 1px solid #fecaca;
}
.techtor-banner--info {
  background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
  border: 1px solid #93c5fd;
}
.techtor-banner__row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}
.techtor-banner__text {
  font-size: 14px;
  font-weight: 600;
}
.techtor-banner__text--amber { color: #92400e; }
.techtor-banner__text--red { color: #991b1b; }
.techtor-banner__text--blue { color: #1e40af; }
.techtor-banner__detail {
  margin: 8px 0 0;
  font-size: 13px;
  color: #6b7280;
}
.techtor-ask-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 14px 32px;
  border-radius: 30px;
  border: none;
  cursor: pointer;
  font-weight: 700;
  font-size: 15px;
  color: #fff;
  transition: all 0.2s ease;
  margin-top: 12px;
}
.techtor-ask-btn--amber {
  background: #d97706;
  box-shadow: 0 4px 14px rgba(217,119,6,0.25);
}
.techtor-ask-btn--amber:hover {
  background: #b45309;
  box-shadow: 0 6px 20px rgba(217,119,6,0.35);
  transform: translateY(-1px);
}
.techtor-ask-btn--red {
  background: #dc2626;
  box-shadow: 0 4px 14px rgba(220,38,38,0.25);
}
.techtor-ask-btn--red:hover {
  background: #b91c1c;
  transform: translateY(-1px);
}
`,Q="inpost-izi-button, INPOST-IZI-BUTTON { display: none !important; }",Z='product-ask-questions, [data-module-name="product_ask_questions"] { display: none !important; }';var g=class{apply(t,e,n){let o=document.querySelectorAll('buy-button, [class*="buy-button"], .product-buy__button'),s=document.querySelector('h-input-stepper, [class*="quantity__input"]')?.closest('product-quantity, [class*="quantity"], .product-quantity'),d=t==="price-zero",p=t==="price-zero";for(let c of o){p?(c.classList.add("techtor-hide"),c.dataset.techtorHidden="1"):(c.classList.remove("techtor-hide"),delete c.dataset.techtorHidden);let l=c.querySelector('.btn_primary, button[type="submit"]')||(c.classList.contains("btn_primary")?c:null);l&&(d?(l.setAttribute("disabled","true"),l.style.opacity="0.5",l.style.pointerEvents="none"):(l.removeAttribute("disabled"),l.style.opacity="",l.style.pointerEvents="")),d?c.setAttribute("is-buyable","0"):c.removeAttribute("is-buyable")}s&&(p?(s.classList.add("techtor-hide"),s.dataset.techtorHidden="1"):(s.classList.remove("techtor-hide"),delete s.dataset.techtorHidden))}destroy(){document.querySelectorAll("[data-techtor-hidden]").forEach(t=>{t.classList.remove("techtor-hide"),delete t.dataset.techtorHidden}),document.querySelectorAll("buy-button").forEach(t=>{t.removeAttribute("is-buyable");let e=t.querySelector(".btn_primary, button");e&&(e.removeAttribute("disabled"),e.style.opacity="",e.style.pointerEvents="")})}};var F='<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',et='<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',ot='<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',U='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';function K(){return`<div class="techtor-banner techtor-banner--warning" id="techtor-ask-banner">
    <div class="techtor-banner__row">${F}<span class="techtor-banner__text techtor-banner__text--amber">Zapytaj o dost\u0119pno\u015B\u0107 \u2014 czas realizacji mo\u017Ce by\u0107 d\u0142u\u017Cszy</span></div>
  </div>`}function J(){return`<div class="techtor-banner techtor-banner--info" id="techtor-tarnawa-banner">
    <div class="techtor-banner__row">${ot}<span class="techtor-banner__text techtor-banner__text--blue">Towar dost\u0119pny u dostawcy \u2014 czas wysy\u0142ki 48 godzin</span></div>
  </div>`}function V(){return`<div class="techtor-banner techtor-banner--error" id="techtor-price0-banner">
    <div class="techtor-banner__row">${et}<span class="techtor-banner__text techtor-banner__text--red">Zapytaj o aktualn\u0105 cen\u0119 i dost\u0119pno\u015B\u0107</span></div>
    <button class="techtor-ask-btn techtor-ask-btn--red" id="techtor-price0-ask">${U} Zapytaj o produkt</button>
  </div>`}function Y(r){return`<div class="techtor-banner techtor-banner--warning" id="techtor-overlimit-banner">
    <div class="techtor-banner__row">${F}<span class="techtor-banner__text techtor-banner__text--amber">Zapytaj o dost\u0119pno\u015B\u0107 wi\u0119kszej ilo\u015Bci</span></div>
    <p class="techtor-banner__detail">W magazynie posiadamy <strong>${r} szt.</strong></p>
    <button class="techtor-ask-btn techtor-ask-btn--amber" id="techtor-overlimit-ask">${U} Zapytaj o dost\u0119pno\u015B\u0107</button>
  </div>`}var nt=["techtor-ask-banner","techtor-tarnawa-banner","techtor-price0-banner","techtor-overlimit-banner"],v=class{constructor(){this.onAskClick=null}setAskHandler(t){this.onAskClick=t}apply(t,e,n){this.removeAll();let o=document.querySelector('buy-button, .product-actions, [data-module-name="product_actions"], .product-buy')?.closest('.product-actions, [data-module-name="product_actions"]')||document.querySelector("buy-button")?.parentElement;if(!o)return;let i="";switch(t){case"available-tarnawa":i=J();break;case"out-of-stock":i=K();break;case"price-zero":i=V();break;case"overlimit":i=Y(e.totalStock);break;default:return}let s=document.createElement("div");s.innerHTML=i;let d=s.firstElementChild;o.insertBefore(d,o.firstChild);let p=d.querySelector('[id$="-ask"]');if(p&&this.onAskClick){let c=this.onAskClick,l=document.querySelector("h1")?.textContent?.trim()||"";p.addEventListener("click",()=>{c(e.sku,l,t==="overlimit"?n:void 0,t==="price-zero")})}}destroy(){this.removeAll()}removeAll(){for(let t of nt)document.getElementById(t)?.remove()}};var x=class{constructor(){this.styleEl=null;this.observer=null}apply(t,e,n){let o=document.querySelector(".product-availability dd, .product-availability span, .product-availability .property__value");if(!o)return;let i="",s="";switch(t){case"available":i="dost\u0119pny",s="#16a34a";break;case"available-tarnawa":i="dost\u0119pny",s="#16a34a";break;case"overlimit":i="zapytaj o dost\u0119pno\u015B\u0107",s="#b45309";break;case"out-of-stock":i="zapytaj o dost\u0119pno\u015B\u0107",s="#b45309";break;case"price-zero":i="zapytaj o cen\u0119 i dost\u0119pno\u015B\u0107",s="#b45309";break}if(!i)return;let d="techtor-avail-css";this.styleEl||(this.styleEl=document.createElement("style"),this.styleEl.id=d,document.head.appendChild(this.styleEl)),this.styleEl.textContent=`
      .product-availability dd,
      .product-availability span.property__value,
      .product-availability .property__value {
        font-size: 0 !important;
        color: transparent !important;
      }
      .product-availability dd::after,
      .product-availability span.property__value::after,
      .product-availability .property__value::after {
        content: "${i}";
        font-size: 14px !important;
        color: ${s} !important;
        font-weight: 600;
      }
    `,this.observer||(this.observer=new MutationObserver(()=>{}),this.observer.observe(o,{childList:!0,characterData:!0,subtree:!0}))}destroy(){this.styleEl?.remove(),this.styleEl=null,this.observer?.disconnect(),this.observer=null}};var k=class{constructor(){this.styleEl=null}apply(t,e,n){let o=t==="price-zero"||t==="out-of-stock"||t==="overlimit";o&&!this.styleEl?(this.styleEl=document.createElement("style"),this.styleEl.id="techtor-inpost-hide",this.styleEl.textContent=Q,document.head.appendChild(this.styleEl)):!o&&this.styleEl&&(this.styleEl.remove(),this.styleEl=null)}destroy(){this.styleEl?.remove(),this.styleEl=null}};var w=class{constructor(){this.attempted=!1;this.maxAttempts=30;this.interval=null}apply(t,e,n){this.attempted||this.autoSelect()}autoSelect(){let t=0;this.interval=setInterval(()=>{if(this.attempted||t++>this.maxAttempts){this.interval&&clearInterval(this.interval);return}let e=document.querySelectorAll("select");for(let n of e){let o=n.options[0];if(!o)continue;let i=o.text.toLowerCase();if((i.includes("wybierz")||i.includes("select"))&&n.options.length>1){n.value=n.options[1].value,this.triggerChange(n),this.attempted=!0,a("Auto-select wariantu:",n.options[1].text),this.interval&&clearInterval(this.interval);return}}},500)}triggerChange(t){try{let e=Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype,"value");if(e?.set){e.set.call(t,t.value),t.dispatchEvent(new Event("input",{bubbles:!0})),t.dispatchEvent(new Event("change",{bubbles:!0}));return}}catch{}t.dispatchEvent(new Event("change",{bubbles:!0})),t.dispatchEvent(new Event("input",{bubbles:!0}))}destroy(){this.interval&&clearInterval(this.interval),this.attempted=!1}};var rt={"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"};function m(r){return r.replace(/[&<>"']/g,t=>rt[t]||t)}var it="https://stock.techtor.pl/api/ask",st="https://vat.techtor.pl/api/gus";function W(r,t,e,n){document.getElementById("techtor-ask-overlay")?.remove();let o=document.createElement("div");o.id="techtor-ask-overlay",o.style.cssText="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;";let i=e?` ${e} szt.`:"",s=n?`Prosz\u0119 o wycen\u0119 produktu ${m(t)} (${m(r)}).`:`Jestem zainteresowany produktem ${m(t)} (${m(r)})${i}. Prosz\u0119 o informacj\u0119 o dost\u0119pno\u015Bci i terminie realizacji.`;o.innerHTML=`
    <div style="background:#fff;max-width:780px;width:100%;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.15);max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
      <button id="techtor-ask-close" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#6b7280;padding:8px;" aria-label="Zamknij">&times;</button>
      <h3 style="margin:0 0 4px;font-size:20px;font-weight:700;color:#1f2937;">${n?"Zapytaj o cen\u0119 i dost\u0119pno\u015B\u0107":"Zapytaj o dost\u0119pno\u015B\u0107"}</h3>
      <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">Produkt: <strong>${m(t)}</strong> (${m(r)})</p>
      <form id="techtor-ask-form">
        <input type="text" name="_hp" style="display:none" tabindex="-1" autocomplete="off">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label style="font-size:12px;font-weight:600;color:#374151;">Ilo\u015B\u0107 (szt.) *</label>
            <input name="quantity" type="number" min="1" value="${e||1}" required style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;">
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
            <label style="font-size:12px;font-weight:600;color:#374151;">Imi\u0119 i nazwisko *</label>
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
          <label style="font-size:12px;font-weight:600;color:#374151;">Wiadomo\u015B\u0107</label>
          <textarea name="message" rows="3" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-top:4px;resize:vertical;">${s}</textarea>
        </div>
        <button type="submit" style="width:100%;margin-top:16px;padding:14px;border:none;border-radius:30px;background:#d97706;color:#fff;font-weight:700;font-size:15px;cursor:pointer;">Wy\u015Blij zapytanie</button>
      </form>
      <div id="techtor-ask-success" style="display:none;text-align:center;padding:20px;">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <p style="margin:12px 0 0;font-size:18px;font-weight:700;color:#1f2937;">Zapytanie wys\u0142ane!</p>
        <p style="margin:4px 0 0;font-size:13px;color:#6b7280;">Odpowiemy najszybciej jak to mo\u017Cliwe.</p>
      </div>
    </div>
  `,document.body.appendChild(o),document.getElementById("techtor-ask-close").onclick=()=>o.remove();let d=o.querySelector('input[name="nip"]');d&&d.addEventListener("input",async()=>{let c=d.value.replace(/\D/g,"");if(c.length===10)try{let b=await(await fetch(`${st}?nip=${c}`)).json();if(b?.result?.subject){let u=b.result.subject,M=o.querySelector("form"),_=(q,f)=>{let T=M.querySelector(`[name="${q}"]`);T&&!T.value&&(T.value=f)};_("company",u.name||""),_("street",u.workingAddress?.split(",")[0]||"");let I=(u.workingAddress||"").split(",");if(I.length>1){let f=I[I.length-1].trim().match(/^(\d{2}-\d{3})\s+(.+)/);f&&(_("zip",f[1]),_("city",f[2]))}a("NIP auto-fill:",u.name)}}catch{}});let p=document.getElementById("techtor-ask-form");p.onsubmit=async c=>{c.preventDefault();let l=new FormData(p),b=(l.get("name")||"").trim(),u=(l.get("email")||"").trim();if(!b||!u||!u.includes("@")){alert("Wype\u0142nij wymagane pola (imi\u0119, email)");return}try{(await fetch(it,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({name:b,email:u,_hp:l.get("_hp")||"",phone:l.get("phone")||"",nip:l.get("nip")||"",company:l.get("company")||"",street:l.get("street")||"",zip:l.get("zip")||"",city:l.get("city")||"",message:l.get("message")||"",quantity:l.get("quantity")||"1",sku:r,product:t,url:location.href})})).ok?(p.style.display="none",document.getElementById("techtor-ask-success").style.display="block",setTimeout(()=>o.remove(),3e3)):alert("Wyst\u0105pi\u0142 b\u0142\u0105d. Spr\xF3buj ponownie.")}catch{alert("B\u0142\u0105d po\u0142\u0105czenia. Spr\xF3buj ponownie.")}}}var S=class{constructor(t){this.modules=[];this.currentState="loading";this.currentQty=1;this.currentSku="";this.adapter=t}start(){this.injectStyles();let t=new v;t.setAskHandler(W),this.modules=[new g,t,new x,new k,new w],this.adapter.onProductChange(n=>{a("SKU changed:",n),this.currentSku=n,this.update()}),this.adapter.onQuantityChange(n=>{a("Qty changed:",n),this.currentQty=n,this.update()}),this.adapter.onNavigation(()=>{a("Navigation detected \u2014 restart"),this.restart()});let e=h();if(e)this.currentSku=e,this.update();else{let n=setInterval(()=>{let o=h();o&&(clearInterval(n),this.currentSku=o,this.update())},200);setTimeout(()=>clearInterval(n),1e4)}window._tRerun=()=>this.restart(),a("Widget started, mode:",window.__techtorWidget?.mode)}update(){if(!this.currentSku)return;let t=P(this.currentSku),e=D(t,this.currentQty);N(this.currentState,e,t,this.currentQty),this.currentState=e,window.__techtorWidget&&(window.__techtorWidget.state=e);for(let n of this.modules)n.apply(e,t,this.currentQty)}restart(){a("Widget restart");for(let t of this.modules)t.destroy();$(),this.currentState="loading",this.currentQty=1,this.currentSku="",C().then(()=>{let t=new v;t.setAskHandler(W),this.modules=[new g,t,new x,new k,new w];let e=h();e&&(this.currentSku=e,this.update())})}injectStyles(){if(!document.getElementById("techtor-widget-css")){let t=document.createElement("style");t.id="techtor-widget-css",t.textContent=R+`
`+Z,document.head.appendChild(t)}}};async function G(){if(!window.__techtorWidget?.initialized)if(window.__techtorWidget={version:3,runId:0,initialized:!1,mode:"loading"},O(),a("Widget v3 bootstrap start"),await C(),typeof window.useStorefront=="function")window.useStorefront(r=>{if(window.__techtorWidget.initialized)return;window.__techtorWidget.initialized=!0,window.__techtorWidget.mode="eventbus",a("Tryb: Event Bus (useStorefront)");let t=new E(r);new S(t).start()}),setTimeout(()=>{if(!window.__techtorWidget.initialized){A("useStorefront timeout \u2014 fallback na DOM adapter"),window.__techtorWidget.initialized=!0,window.__techtorWidget.mode="dom-fallback";let r=new y;new S(r).start()}},5e3);else{A("Brak useStorefront \u2014 DOM fallback"),window.__techtorWidget.initialized=!0,window.__techtorWidget.mode="dom-fallback";let r=new y;new S(r).start()}}G().catch(r=>console.error("[TECHTOR] Widget bootstrap error:",r));})();
