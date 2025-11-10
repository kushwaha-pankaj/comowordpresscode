/**
 * ComoTour booking front-end
 * - Uses new Private/Shared definitions (start/end + pricing)
 * - 20% deposit; balance due on arrival
 * - Pickup/Contact/Notes/Terms
 */
jQuery(function ($) {
  const $wrap = $('.ct-timeslots'); if (!$wrap.length) return;

  const mode = ($wrap.data('mode') || 'private').toString();
  const currency = CT_TS.currency || '€';
  const depositPct = parseFloat(CT_TS.deposit_pct || 0.2);

  // Turio date input
  const $date = $('input#customDateDatepicker, input[name="custom_date"], input[name="ct_date"], input.datepicker').first();
  if (!$date.length) return;

  // People / Seats
  const $people = $('#ct_people');
  const $adults = $('#ct_adults');
  const $children = $('#ct_children');

  // Extras/Contact
  const $pickup = $('#ct_pickup');
  const $pickupOther = $('#ct_pickup_other');
  const $name = $('#ct_name'); const $email = $('#ct_email'); const $phone = $('#ct_phone'); const $notes = $('#ct_notes'); const $terms = $('#ct_terms');

  // Hidden fields
  const $hMode = $('#ct_mode'); const $hDate = $('#ct_date'); const $hTime = $('#ct_time'); const $hDur = $('#ct_duration');
  const $hTotal = $('#ct_price_total'); const $hDep = $('#ct_deposit_due'); const $hBal = $('#ct_balance_due');
  const $hPickup = $('#ct_pickup_val'); const $hName = $('#ct_name_val'); const $hEmail = $('#ct_email_val'); const $hPhone = $('#ct_phone_val'); const $hNotes = $('#ct_notes_val');

  // optional totals display in theme
  const $totalEl = $('#total-price');

  function syncHidden(){
    $hMode.val(mode);
    $hDate.val($date.val());
    const pick = $pickup.val()==='Other' ? ($pickupOther.val()||'Other') : $pickup.val();
    $hPickup.val(pick); $hName.val($name.val()); $hEmail.val($email.val()); $hPhone.val($phone.val()); $hNotes.val($notes.val());
  }

  function fetchSlots(){
    const date = $date.val(); syncHidden(); if (!date) return;
    $.post(CT_TS.ajax, {action:'ct_get_slots', nonce:CT_TS.nonce, tour:$wrap.data('tour'), date, mode}, function(res){
      const $list = $wrap.find('.ct-slot-list').empty();
      if (!res || !res.success || !res.data.slots.length){
        $list.html('<div class="ct-empty">No time slots for this date.</div>'); return;
      }
      res.data.slots.forEach(s=>{
        const labelPrivate = `${s.time}–${s.end} (${s.duration}m) — ${currency}${parseFloat(s.price||0).toFixed(2)}`;
        const labelShared  = `${s.time}–${s.end} (${s.duration}m) — ${s.remaining||0} left — ${currency}${parseFloat(s.price||0).toFixed(2)}/seat`;
        const available = (mode==='shared') ? (parseInt(s.remaining||0,10) > 0) : !!s.available;
        const text = mode==='shared' ? labelShared : labelPrivate;
        const $btn = $(`<button type="button" class="ct-slot ${available?'available':'unavailable'}" ${available?'':'disabled'}>${text}</button>`).data(s);
        $list.append($btn);
      });
    });
  }

  function calcTotals(){
    const s = $('.ct-slot.active').data() || {};
    let total = 0;

    if (mode==='private'){
      // Price is final for the slot (discount/ promo already resolved in PHP)
      const price = parseFloat(s.price || 0);
      total = price;
      // people affects manifest only; optionally enforce capacity:
      const max = parseInt($people.attr('max') || 999, 10);
      const ppl = parseInt($people.val() || 1, 10);
      if (ppl > max) { alert(`Maximum ${max} people for this experience.`); $people.val(max); }
      $hDur.val(parseInt(s.duration||0,10));
    } else {
      const ad = parseInt($adults.val()||0,10);
      const ch = parseInt($children.val()||0,10);
      const seatPrice = parseFloat(s.price || 0);
      const remaining = parseInt(s.remaining || 0,10);
      if (ad+ch > remaining){ alert(`Only ${remaining} seats left at ${s.time}. Reduce seats or choose another time.`); }
      total = seatPrice * (ad + ch); // simple single price (adult/child same). Expand if you add child price later.
      $hDur.val(parseInt(s.duration||0,10));
    }

    const dep = Math.round(total * depositPct * 100)/100;
    const bal = Math.max(0, Math.round((total - dep) * 100)/100);

    $hTotal.val(total.toFixed(2));
    $hDep.val(dep.toFixed(2));
    $hBal.val(bal.toFixed(2));

    if ($totalEl.length){
      $totalEl.text(`${currency}${total.toFixed(2)} — Deposit ${currency}${dep.toFixed(2)} (Balance on arrival ${currency}${bal.toFixed(2)})`);
    }
  }

  $(document).on('click','.ct-slot.available', function(){
    $('.ct-slot').removeClass('active');
    $(this).addClass('active');
    const s = $(this).data();
    $hTime.val(s.time);
    calcTotals();
  });

  $date.on('change input blur', fetchSlots);
  $people.on('input', calcTotals);
  $adults.on('input', calcTotals);
  $children.on('input', calcTotals);

  $pickup.on('change', function(){
    if ($pickup.val()==='Other') $pickupOther.removeClass('ct-hide'); else $pickupOther.addClass('ct-hide');
    syncHidden();
  });
  $pickupOther.on('input', syncHidden);
  $name.on('input', syncHidden);
  $email.on('input', syncHidden);
  $phone.on('input', syncHidden);
  $notes.on('input', syncHidden);

  $(document).on('submit','form.cart', function(e){
    syncHidden();
    if (!$terms.is(':checked')){ e.preventDefault(); alert('Please accept the cancellation & weather policy.'); return false; }
    if (!$hDate.val() || !$hTime.val()){ e.preventDefault(); alert('Please select a date and a time slot.'); return false; }
    calcTotals();
  });

  setTimeout(function(){ fetchSlots(); }, 150);
});

jQuery(document).ready(function($) {
    // On date change, update available time slots
    $('#ct_specific_date').on('change', function() {
        var selectedDate = $(this).val();
        var tourId = $('.ct-timeslots').data('tour');
        var mode = $('.ct-timeslots').data('mode');

        // Make sure we have a valid date
        if (selectedDate) {
            $.ajax({
                url: CT_TS.ajax,
                type: 'POST',
                data: {
                    action: 'ct_get_slots',
                    tour: tourId,
                    date: selectedDate,
                    specific_date: selectedDate,
                    mode: mode,
                    nonce: CT_TS.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var slots = response.data.slots;
                        updateSlotsTable(slots);
                    }
                }
            });
        }
    });

    function updateSlotsTable(slots) {
        var tableBody = $('#ct-p-list tbody');
        tableBody.empty(); // Clear previous rows

        // Populate table with new slots
        slots.forEach(function(slot) {
            var row = `
                <tr>
                    <td>${slot.time}</td>
                    <td>${slot.end}</td>
                    <td>${slot.price}</td>
                    <td>${slot.promo}</td>
                    <td>${slot.discount}</td>
                    <td>${slot.final}</td>
                    <td><button class="button ct-remove">Remove</button></td>
                </tr>
            `;
            tableBody.append(row);
        });
    }
});

jQuery(document).ready(function($) {
    // On date change, update available time slots
    $('#ct_specific_date').on('change', function() {
        var selectedDate = $(this).val();
        var tourId = $('.ct-timeslots').data('tour');
        var mode = $('.ct-timeslots').data('mode');

        // Make sure we have a valid date
        if (selectedDate) {
            $.ajax({
                url: CT_TS.ajax,
                type: 'POST',
                data: {
                    action: 'ct_get_slots',
                    tour: tourId,
                    date: selectedDate,
                    specific_date: selectedDate,
                    mode: mode,
                    nonce: CT_TS.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var slots = response.data.slots;
                        updateSlotsTable(slots);
                    }
                }
            });
        }
    });

    function updateSlotsTable(slots) {
        var tableBody = $('#ct-p-list tbody');
        tableBody.empty(); // Clear previous rows

        // Populate table with new slots
        slots.forEach(function(slot) {
            var row = `
                <tr>
                    <td>${slot.time}</td>
                    <td>${slot.end}</td>
                    <td>${slot.price}</td>
                    <td>${slot.promo}</td>
                    <td>${slot.discount}</td>
                    <td><strong>${slot.final}</strong></td>
                    <td class="ct-actions">
                        <button class="button ct-remove">Remove</button>
                    </td>
                </tr>
            `;
            tableBody.append(row);
        });
    }

    // Function for removing time slots
    $(document).on('click', '.ct-remove', function() {
        $(this).closest('tr').remove();
    });

    // Add a time slot for the selected date
    $('#ct-add-p-slot').on('click', function() {
        var start = $('#ct_p_start').val();
        var end = $('#ct_p_end').val();
        var price = $('#ct_p_price').val();
        var promo = $('#ct_p_promo').val();
        var disc = $('#ct_p_disc').val();

        if (start && end && price) {
            var row = `
                <tr>
                    <td>${start}</td>
                    <td>${end}</td>
                    <td>${price}</td>
                    <td>${promo ? promo : '—'}</td>
                    <td>${disc ? disc : '—'}</td>
                    <td><strong>${calculateFinalPrice(price, promo, disc)}</strong></td>
                    <td class="ct-actions"><button class="button ct-remove" type="button">Remove</button></td>
                    <input type="hidden" name="ct_p_slots[][start]" value="${start}">
                    <input type="hidden" name="ct_p_slots[][end]" value="${end}">
                    <input type="hidden" name="ct_p_slots[][price]" value="${price}">
                    <input type="hidden" name="ct_p_slots[][promo]" value="${promo}">
                    <input type="hidden" name="ct_p_slots[][disc]" value="${disc}">
                    <input type="hidden" name="ct_p_slots[][final]" value="${calculateFinalPrice(price, promo, disc)}">
                </tr>
            `;
            $('#ct-p-list tbody').append(row);
        }
    });

    // Final price calculation function
    function calculateFinalPrice(price, promo, disc) {
        if (promo > 0) {
            return promo;
        } else if (disc > 0) {
            return (price * (1 - (disc / 100))).toFixed(2);
        } else {
            return price;
        }
    }
});
