(function(){
  'use strict';

  function money(n){
    try {
      var cur = (window.CT_BOOKING && CT_BOOKING.currency) || 'EUR';
      return new Intl.NumberFormat(undefined, { style:'currency', currency: cur }).format(n||0);
    } catch(e) { 
      return '$' + (n||0).toFixed(2); 
    }
  }
  
  function dayISO(d){
    if (!d) return '';
    if (d instanceof Date) return d.toISOString().slice(0,10);
    if (typeof d.toJSDate === 'function') return d.toJSDate().toISOString().slice(0,10);
    if (typeof d.toDate === 'function') return d.toDate().toISOString().slice(0,10);
    return (''+d).slice(0,10);
  }

  var card = document.getElementById('ct-booking-card');
  if (!card) return;

  var ctx = {
    postId: card.getAttribute('data-post-id'),
    productId: card.getAttribute('data-product-id'),
    mode: card.getAttribute('data-mode') || 'private',
    regular: parseFloat(card.getAttribute('data-regular-price')||'0')||0,
    sale: parseFloat(card.getAttribute('data-sale-price')||'0')||0,
    maxPeople: parseInt(card.getAttribute('data-max-people')||'10',10)||10,
    selectedDate: null,
    selectedSlot: null,
    daysWithSlots: {}
  };

  var topPrice = document.getElementById('ct_top_price');
  var totalPrice = document.getElementById('ct_total_price');
  var slotsList = document.getElementById('ct_slots_list');
  var selectedIso = document.getElementById('ct_selected_iso');
  var clearSel = document.getElementById('ct_clear_selected');
  var peopleInput = document.getElementById('ct_people');
  var peopleMinus = document.getElementById('ct_people_minus');
  var peoplePlus = document.getElementById('ct_people_plus');
  var maxDisplay = document.getElementById('ct_max_display');
  var extrasWrap = document.getElementById('ct_extras');
  var stickySummary = document.querySelector('.ct-booking-summary');
  var stickyDefaultSummary = stickySummary ? stickySummary.textContent : '';

  if (maxDisplay) maxDisplay.textContent = ctx.maxPeople;

  function updateHeader(){
    if (!topPrice) return;
    var base = (ctx.selectedSlot && ctx.selectedSlot.price) ? ctx.selectedSlot.price : ctx.regular;
    if (ctx.mode === 'shared' && ctx.selectedSlot) {
      topPrice.innerHTML = money(base) + ' <small style="font-weight:600; font-size:12px">/person</small>';
    } else {
      topPrice.innerHTML = money(base);
    }
  }

  function calcTotal(){
    if (!totalPrice || !peopleInput) return;
    var people = parseInt(peopleInput.value||'1',10) || 1;
    var extras = 0;
    if (extrasWrap) {
      extrasWrap.querySelectorAll('input.ct-extra-checkbox:checked').forEach(function(chk){
        extras += parseFloat(chk.getAttribute('data-price')||0) || 0;
      });
    }
    var base = (ctx.selectedSlot && ctx.selectedSlot.price) ? ctx.selectedSlot.price : ctx.regular;
    var total = ctx.mode === 'shared' ? (base * people) + extras : base + extras;
    totalPrice.textContent = money(total);
  }

  function updateStickySummary() {
    if (!stickySummary) return;
    if (!ctx.selectedDate) {
      stickySummary.textContent = stickyDefaultSummary || 'Select your date & time to book this experience';
      return;
    }
    var summaryParts = [];
    summaryParts.push(ctx.selectedDate);
    if (ctx.selectedSlot) {
      var endLabel = ctx.selectedSlot.end || '';
      var timeLabel = ctx.selectedSlot && ctx.selectedSlot.price !== undefined
        ? ctx.selectedSlot.time + (ctx.selectedSlot.end ? ' – ' + ctx.selectedSlot.end : '')
        : ctx.selectedSlot.time;
      summaryParts.push(timeLabel);
    }
    stickySummary.textContent = summaryParts.join(' • ');
  }

  function restBase(){
    return (window.CT_BOOKING && CT_BOOKING.restBase) ? CT_BOOKING.restBase.replace(/\/$/, '') : '/wp-json/ct-timeslots/v1';
  }

  // FIX: Instant decoration, no animation frame delays
  function decorateDays(daysMap){
    var nodes = document.querySelectorAll('.litepicker .day-item');
    nodes.forEach(function(node){
      var ts = node.getAttribute('data-time');
      if (ts) {
        var d = new Date(parseInt(ts,10));
        var iso = dayISO(d);
        
        // Check if this date has slots
        var hasSlots = daysMap && daysMap[iso];
        
        // Add or remove class instantly
        if (hasSlots) {
          if (!node.classList.contains('ct-day-has-slots')) {
            node.classList.add('ct-day-has-slots');
          }
        } else {
          node.classList.remove('ct-day-has-slots');
        }
      }
    });
  }

  function preloadDays(){
    var from = new Date();
    var to = new Date();
    to.setFullYear(to.getFullYear() + 1);
    var url = restBase() + '/days?post_id=' + ctx.postId + '&from=' + dayISO(from) + '&to=' + dayISO(to) + '&mode=' + ctx.mode;
    
    fetch(url, { credentials: 'same-origin' })
      .then(function(r){ 
        if (!r.ok) throw new Error('Network response was not ok (status: ' + r.status + ')');
        return r.json(); 
      })
      .then(function(j){
        if (j && j.ok) {
          ctx.daysWithSlots = j.days || {};
          decorateDays(ctx.daysWithSlots);
          updateHeader();
          calcTotal();
        } else {
          console.warn('API returned ok=false:', j);
        }
      })
      .catch(function(error){
        console.error('Error preloading days:', error);
      });
  }

  function renderSlots(slots){
    if (!slotsList) return;
    slotsList.innerHTML = '';
    if (!slots || !slots.length){
      slotsList.innerHTML = '<div class="ct-slot-hint">No time slots for this date</div>';
      ctx.selectedSlot = null;
      updateHeader();
      calcTotal();
      updateStickySummary();
      return;
    }
    slots.forEach(function(s, idx){
      var label = document.createElement('label');
      label.className = 'ct-slot-label';
      var input = document.createElement('input');
      input.type = 'radio';
      input.name = 'ct_slot';
      input.className = 'ct-slot-radio';
      input.value = s.id;
      input.dataset.slotId = s.id;
      
      var pill = document.createElement('div');
      pill.className = 'ct-slot-pill';
      var left = document.createElement('div');
      left.className = 'ct-slot-left';
      
      var timeDisplay = s.time + ' – ' + s.end;
      var available = s.capacity - s.booked;
      var capacityText = available + ' available • Up to ' + s.capacity + ' people';
      
      left.innerHTML = '<div class="slot-time">' + timeDisplay + '</div><div class="slot-meta"><div class="slot-meta-item">⏱ ' + s.duration + 'm</div><div class="slot-meta-item">👥 ' + capacityText + '</div></div>';
      
      var price = document.createElement('div');
      price.className = 'slot-price';
      price.textContent = money(s.price) + (ctx.mode === 'shared' ? '/person' : '');
      pill.appendChild(left);
      pill.appendChild(price);
      label.appendChild(input);
      label.appendChild(pill);
      slotsList.appendChild(label);
      
      input.addEventListener('change', function(){
        if (input.checked) {
          var remaining = s.capacity - s.booked;
          ctx.selectedSlot = {
            id: s.id, 
            price: s.price, 
            capacity: s.capacity,
            booked: s.booked,
            remaining: remaining,
            duration: s.duration,
            end: s.end,
            time: s.time
          };
          if (selectedIso) selectedIso.textContent = ctx.selectedDate;
          
          if (ctx.mode === 'shared') {
            var maxForSlot = Math.min(ctx.maxPeople, remaining);
            if (maxDisplay) maxDisplay.textContent = maxForSlot;
            peopleInput.value = 1;
          } else {
            if (maxDisplay) maxDisplay.textContent = s.capacity;
            peopleInput.value = 1;
          }
          
          updateHeader();
          calcTotal();
          updateStickySummary();
        }
      });
    });
  }

  function loadSlots(date){
    ctx.selectedDate = date;
    if (selectedIso) selectedIso.textContent = date;
    updateStickySummary();
    var url = restBase() + '/slots?post_id=' + ctx.postId + '&date=' + date + '&mode=' + ctx.mode;
    
    fetch(url, { credentials: 'same-origin' })
      .then(function(r){ 
        if (!r.ok) throw new Error('Network response was not ok (status: ' + r.status + ')');
        return r.json(); 
      })
      .then(function(j){
        if (j && j.ok) {
          renderSlots(j.slots || []);
        } else {
          console.error('Failed to load slots:', j);
          renderSlots([]);
        }
      })
      .catch(function(error){
        console.error('Error loading slots:', error);
        if (slotsList) {
          slotsList.innerHTML = '<div class="ct-slot-hint">Error loading time slots. Please refresh the page.</div>';
        }
      });
  }

  var picker;
  function initCalendar(){
    var host = document.getElementById('ct_date_inline');
    if (!host || typeof Litepicker === 'undefined') {
      setTimeout(initCalendar, 500);
      return;
    }
    picker = new Litepicker({
      element: host,
      inlineMode: true,
      singleMode: true,
      minDate: new Date(),
      dropdowns: false,
      setup: function(p){ 
        p.on('selected', function(date){ 
          loadSlots(dayISO(date.dateInstance)); 
        }); 
        
        // FIX: Instant render, no delays
        p.on('render', function(){ 
          // Apply decoration immediately
          decorateDays(ctx.daysWithSlots); 
        });
        
        // Also decorate on month change instantly
        p.on('change:month', function(date, idx){ 
          // Immediate decoration
          decorateDays(ctx.daysWithSlots); 
        });
        
        // FIX: Pre-render hook - decorate before display
        p.on('before:render', function(){
          // Even faster - decorate before render completes
          setTimeout(function(){
            decorateDays(ctx.daysWithSlots);
          }, 0);
        });
      }
    });
    
    // Initial decoration - load data first, then render
    setTimeout(function(){
      preloadDays();
    }, 100);
  }

  if (extrasWrap) {
    extrasWrap.querySelectorAll('.ct-extra-checkbox').forEach(function(chk){
      chk.addEventListener('change', calcTotal);
    });
  }

  function setPeople(v){
    var max = ctx.maxPeople;
    if (ctx.mode === 'shared' && ctx.selectedSlot) {
      max = Math.min(max, ctx.selectedSlot.remaining);
    }
    var newVal = Math.max(1, Math.min(max, parseInt(v||1,10)));
    peopleInput.value = newVal;
    calcTotal();
  }

  if (peopleMinus) peopleMinus.addEventListener('click', function(){ setPeople(parseInt(peopleInput.value)-1); });
  if (peoplePlus) peoplePlus.addEventListener('click', function(){ setPeople(parseInt(peopleInput.value)+1); });
  
  if (clearSel) clearSel.addEventListener('click', function(e){ 
    e.preventDefault(); 
    
    var radios = document.querySelectorAll('input[name="ct_slot"]');
    radios.forEach(function(radio) {
      radio.checked = false;
    });
    
    ctx.selectedDate = null; 
    ctx.selectedSlot = null; 
    selectedIso.textContent = '—'; 
    
    if (slotsList) {
      var slots = slotsList.querySelectorAll('.ct-slot-pill');
      slots.forEach(function(slot) {
        slot.classList.remove('selected');
      });
    }
    
    renderSlots([]); 
    if (maxDisplay) maxDisplay.textContent = ctx.maxPeople;
    peopleInput.value = 1;
    updateHeader(); 
    calcTotal();
    updateStickySummary();
  });

  // Allow deselecting time slot by clicking again
  document.addEventListener('change', function(e) {
    if (e.target.classList.contains('ct-slot-radio')) {
      var allRadios = document.querySelectorAll('input[name="ct_slot"]');
      var wasChecked = e.target.dataset.wasChecked === 'true';
      
      allRadios.forEach(function(radio) {
        radio.dataset.wasChecked = radio.checked ? 'true' : 'false';
      });
      
      if (wasChecked) {
        e.target.checked = false;
        ctx.selectedSlot = null;
        selectedIso.textContent = '—';
        renderSlots([]);
        if (maxDisplay) maxDisplay.textContent = ctx.maxPeople;
        peopleInput.value = 1;
        updateHeader();
        calcTotal();
      }
    }
  }, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ 
      updateHeader(); 
      calcTotal();
      updateStickySummary();
      initCalendar();
    });
  } else {
    setTimeout(function(){ 
      updateHeader(); 
      calcTotal();
      updateStickySummary();
      initCalendar();
    }, 100);
  }
})();


// --- BOOK NOW / ADD TO CART HANDLER -----------------------------------
document.addEventListener("DOMContentLoaded", function () {
  // detect button
  const bookBtn = document.querySelector(
    '.book_now_btn, #book-now, .theme-btn, .egns-booking-btn, #egns-booking, button[name="add-to-cart"], .button-fill-primary'
  );

  if (!bookBtn) {
    console.warn("⚠️ CT-Booking: No Book Now button found on page.");
    return;
  }

  console.log("✅ CT-Booking: Found Book Now button", bookBtn);

  // handle click
  bookBtn.addEventListener("click", function (e) {
    e.preventDefault(); // stop WooCommerce form default
    e.stopPropagation();

    // validate required fields
    if (!ctx.productId) {
      alert("No product selected for booking.");
      return;
    }
    if (!ctx.selectedDate) {
      alert("Please select a booking date.");
      return;
    }
    if (!ctx.selectedSlot || !ctx.selectedSlot.id) {
      alert("Please select a time slot.");
      return;
    }

    // collect people count
    const qty = parseInt(peopleInput?.value || "1", 10) || 1;

    // start building URL
    let url =
      window.location.origin +
      "/?add-to-cart=" +
      encodeURIComponent(ctx.productId) +
      "&quantity=" +
      encodeURIComponent(qty);

    // append booking params
    url += "&ct_date=" + encodeURIComponent(ctx.selectedDate);
    url += "&ct_slot_id=" + encodeURIComponent(ctx.selectedSlot.id);
    url += "&ct_mode=" + encodeURIComponent(ctx.mode);

    // collect extras
    const extras = [];
    extrasWrap?.querySelectorAll("input.ct-extra-checkbox:checked")?.forEach(
      function (chk) {
        const labelEl = chk.closest(".ct-extra-row")?.querySelector(
          ".ct-extra-title"
        );
        const label = labelEl ? labelEl.textContent.trim() : chk.dataset.id;
        const price = parseFloat(chk.getAttribute("data-price") || 0);
        extras.push({ label, price });
      }
    );

    // encode extras as query params
    extras.forEach(function (extra, i) {
      url +=
        "&ct_extra[" +
        i +
        "][label]=" +
        encodeURIComponent(extra.label) +
        "&ct_extra[" +
        i +
        "][price]=" +
        encodeURIComponent(extra.price);
    });

    console.log("🛒 Redirecting to:", url);
    window.location.href = url; // redirect to cart
  });
});

// inside initAll() after bindBookNow();
var form = document.querySelector('form.cart');
if (form) {
  form.addEventListener('submit', function(e){
    function ensureHidden(name) {
      var el = form.querySelector('input[name="'+name+'"]');
      if (!el) { el = document.createElement('input'); el.type='hidden'; el.name=name; form.appendChild(el); }
      return el;
    }

    var dateVal   = (window.ctx && ctx.selectedDate) ? ctx.selectedDate : (document.getElementById('ct_selected_iso')?.textContent || '').trim();
    var slotIdVal = (window.ctx && ctx.selectedSlot) ? ctx.selectedSlot.id : (document.querySelector('input[name="ct_slot"]:checked')?.value || '');
    var modeVal   = (window.ctx && ctx.mode) ? ctx.mode : (document.querySelector('[name="ct_mode"]')?.value || 'private');

    var qtyVal = 1;
    if (document.getElementById('ct_people')) qtyVal = parseInt(document.getElementById('ct_people').value || '1', 10) || 1;

    var extrasArr = [];
    var extrasWrap = document.getElementById('ct_extras');
    if (extrasWrap) {
      extrasWrap.querySelectorAll('input.ct-extra-checkbox:checked').forEach(function (chk) {
        var labelEl = chk.closest('.ct-extra-row')?.querySelector('.ct-extra-title');
        var label = labelEl ? labelEl.textContent.trim() : (chk.dataset.id || 'Extra');
        var price = parseFloat(chk.getAttribute('data-price') || 0) || 0;
        extrasArr.push({ label: label, price: price });
      });
    }

    if (!dateVal)  { e.preventDefault(); alert('Please select a booking date.'); return; }
    if (!slotIdVal){ e.preventDefault(); alert('Please select a time slot.');   return; }

    ensureHidden('ct_date').value        = dateVal;
    ensureHidden('ct_slot_id').value     = slotIdVal;
    ensureHidden('ct_mode').value        = modeVal;
    ensureHidden('ct_people').value      = String(qtyVal);
    ensureHidden('ct_extras_json').value = JSON.stringify(extrasArr);

    var qtyInput = form.querySelector('input.qty');
    if (qtyInput && qtyVal > 0) qtyInput.value = String(qtyVal);
  }, true);
}

// --- Sticky booking bar offsets -------------------------------------------
function initStickyBar() {
  if (!document.body.classList.contains('ct-ts-single')) return;
  var bar = document.getElementById('ct-booking-sticky');
  if (!bar) {
    // Element not ready yet, try again after a short delay
    setTimeout(initStickyBar, 100);
    return;
  }

  document.body.classList.add('ct-booking-bar-active');

  function updateOffsets() {
    var topOffset = 0;
    var adminBar = document.getElementById('wpadminbar');
    if (adminBar && adminBar.offsetHeight > 0) {
      topOffset += adminBar.offsetHeight;
    }

    var topbar = document.querySelector('.topbar-area');
    if (topbar && topbar.offsetHeight > 0) {
      topOffset += topbar.offsetHeight;
    }

    var searchbar = document.querySelector('.main-searchbar-wrapper');
    if (searchbar && searchbar.offsetHeight > 0) {
      topOffset += searchbar.offsetHeight;
    }

    var header = document.querySelector('.sticky-header') || document.querySelector('header.header');
    if (header && header.offsetHeight > 0) {
      topOffset += header.offsetHeight;
    }

    var barHeight = bar.offsetHeight || 60;

    document.documentElement.style.setProperty('--ct-sticky-offset', topOffset + 'px', 'important');
    document.documentElement.style.setProperty('--ct-booking-bar-height', barHeight + 'px', 'important');
    document.body.style.setProperty('--ct-sticky-offset', topOffset + 'px', 'important');
    document.body.style.setProperty('--ct-booking-bar-height', barHeight + 'px', 'important');
    bar.style.setProperty('top', topOffset + 'px', 'important');
    console.log('[CT Booking] offsets', { topOffset: topOffset, barHeight: barHeight });
  }

  updateOffsets();
  setTimeout(updateOffsets, 200);
  setTimeout(updateOffsets, 600);
  window.addEventListener('resize', updateOffsets);

  var stickyButton = bar.querySelector('.ct-booking-button');
  if (stickyButton) {
    stickyButton.addEventListener('click', function (e) {
      var target = document.getElementById('ct-booking-card');
      if (target) {
        e.preventDefault();
        var targetTop = target.getBoundingClientRect().top + window.pageYOffset - 20;
        window.scrollTo({ top: targetTop, behavior: 'smooth' });
      }
    });
  }
}

// Try multiple times to catch the element
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initStickyBar);
} else {
  initStickyBar();
}
// Also try after page load in case wp_footer runs late
window.addEventListener('load', function() {
  setTimeout(initStickyBar, 100);
});

