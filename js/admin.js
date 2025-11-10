jQuery(function($){
  function toast(msg){ window.alert(msg); }
  function setDateLabel(date){ $('#ct_date_label').text(date ? date : '[pick a date]'); }
  function currentMode(){ return $('#ct_mode').val()==='shared' ? 'shared' : 'private'; }
  function normTime(t){
    if(!t) return '';
    var m = /^(\d{2}):(\d{2})$/.exec(t.trim());
    if(!m) return '';
    var h = parseInt(m[1],10), mm = parseInt(m[2],10);
    if(h<0||h>23||mm<0||mm>59) return '';
    return (h<10?'0':'')+h+':'+(mm<10?'0':'')+mm;
  }

  /* ---------- date helpers & validation ---------- */

  // parse common input date formats into a Date at midnight local time
  function parseDateInput(s){
    if(!s) return null;
    s = String(s).trim();
    // ISO YYYY-MM-DD
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
    if(m) return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    // alt format dd/mm/YYYY (flatpickr altFormat)
    m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(s);
    if(m) return new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]));
    // fallback: try Date parse (not ideal but last resort)
    var d = new Date(s);
    return isNaN(d.getTime()) ? null : new Date(d.getFullYear(), d.getMonth(), d.getDate());
  }

  function todayStart(){
    var d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
  }

  // returns {ok: true} or {ok:false, msg: '...'}
  function validateSpecificDate(dateStr){
    if(!dateStr) return { ok:false, msg:'Pick a Specific Date first.' };
    var spec = parseDateInput(dateStr);
    if(!spec) return { ok:false, msg:'Specific Date format is invalid.' };

    var now = todayStart();
    if(spec < now){
      return { ok:false, msg:'Specific date must be today or in the future.' };
    }

    // check against date range fields if present
    var fromRaw = $('#ct_date_from').val();
    var toRaw   = $('#ct_date_to').val();
    var fromD = parseDateInput(fromRaw);
    var toD   = parseDateInput(toRaw);

    if(fromD && spec < fromD) {
      return { ok:false, msg: 'Specific date must be on or after the "From" date.' };
    }
    if(toD && spec > toD) {
      return { ok:false, msg: 'Specific date must be on or before the "To" date.' };
    }

    return { ok:true };
  }

  var HAS_POST_ID = typeof CT_TS_ADMIN !== 'undefined' && parseInt(CT_TS_ADMIN.postId || '0', 10) > 0;

  function requirePostId(message){
    if (HAS_POST_ID) return true;
    toast(message || 'Please save the tour/package (Save Draft or Publish) before managing time slots.');
    return false;
  }

  function showNeedsPostMessage(){
    setAddDisabled(true);
    $('#ct_slots_table tbody').html('<tr><td colspan="9">Save the tour/package (Save Draft or Publish) before managing time slots.</td></tr>');
  }

  function dateToISO(dateObj){
    var y = dateObj.getFullYear();
    var m = String(dateObj.getMonth() + 1).padStart(2, '0');
    var d = String(dateObj.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  function buildDateRange(fromRaw, toRaw){
    if(!fromRaw || !toRaw){
      return { ok:false, msg:'Enter both From and To dates to create slots for a range.' };
    }
    var from = parseDateInput(fromRaw);
    var to = parseDateInput(toRaw);
    if(!from || !to){
      return { ok:false, msg:'Date range must be valid YYYY-MM-DD values.' };
    }
    if(to < from){
      return { ok:false, msg:'"To" date must be on or after the "From" date.' };
    }
    var list = [];
    var current = new Date(from.getTime());
    var maxDays = 366;
    while (current <= to){
      list.push(dateToISO(current));
      if (list.length >= maxDays){
        return { ok:false, msg:'Date range too large. Please use 366 days or fewer at a time.' };
      }
      current.setDate(current.getDate() + 1);
    }
    return { ok:true, dates:list };
  }

  /* ---------- pickers init ---------- */
  function initPickers(){
    $('.ct-date').each(function(){
      if (this._fp) return;
      $(this).flatpickr({
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd/m/Y',
        allowInput: true
      });
    });
    $('.ct-time').each(function(){
      var $t = $(this);
      if ($t.data('fp')) return;
      $t.flatpickr({
        enableTime: true,
        noCalendar: true,
        dateFormat: 'H:i',
        time_24hr: true
      });
      $t.data('fp', true);
    });
  }
  initPickers();

  function toggleMode(){
    var isShared = currentMode()==='shared';
    $('#ct_private_box').toggleClass('ct-hide', isShared);
    $('#ct_shared_box').toggleClass('ct-hide', !isShared);
  }
  $('#ct_mode').on('change', toggleMode);
  toggleMode();

  function ucfirst(s){ return s.charAt(0).toUpperCase() + s.slice(1); }

  function renderRows(slots){
    var $tb = $('#ct_slots_table tbody').empty();
    if (!slots || !slots.length){
      $tb.append('<tr><td colspan="9">No time slots for this date.</td></tr>');
      return;
    }
    slots.forEach(function(s){
      var row = [
        '<tr data-slot="'+s.id+'">',
          '<td>'+s.date+'</td>',
          '<td>'+ucfirst(s.mode||'private')+'</td>',
          '<td>'+s.time+'</td>',
          '<td>'+s.end+'</td>',
          '<td>'+s.duration+'m</td>',
          '<td>'+s.capacity+'</td>',
          '<td>'+Number(s.price).toFixed(2)+'</td>',
          '<td>'+(s.booked||0)+'</td>',
          '<td><button class="button button-link-delete ct-del" data-id="'+s.id+'">Delete</button></td>',
        '</tr>'
      ].join('');
      $tb.append(row);
    });
  }

  function fetchSlotsFor(date){
    setDateLabel(date);
    if(!date){
      $('#ct_slots_table tbody').html('<tr><td colspan="9">Pick a date to load time slots…</td></tr>');
      setAddDisabled(true);
      return;
    }

    if (!HAS_POST_ID) {
      showNeedsPostMessage();
      return;
    }

    // run validation to ensure date is acceptable before fetching
    var v = validateSpecificDate(date);
    if(!v.ok){
      $('#ct_slots_table tbody').html('<tr><td colspan="9">'+v.msg+'</td></tr>');
      setAddDisabled(true);
      return;
    }

    setAddDisabled(false);

    $.post(CT_TS_ADMIN.ajax, {
      action:'ct_admin_get_slots_by_date',
      nonce: CT_TS_ADMIN.nonce,
      post_id: CT_TS_ADMIN.postId,
      date: date
    }, function(res){
      console.log('ct_admin_get_slots_by_date response:', res);
      if(!res || !res.success){ renderRows([]); return; }
      renderRows(res.data.slots||[]);
    }, 'json').fail(function(xhr, status, err){
      console.error('AJAX fetchSlotsFor failed', status, err, xhr.responseText);
      toast('AJAX error fetching slots. See console (F12) for details.');
    });
  }

  // enable/disable add buttons
  function setAddDisabled(flag){
    $('#ct-add-p-slot, #ct-add-s-slot').prop('disabled', !!flag);
    if(flag){
      $('#ct-add-p-slot').addClass('disabled');
      $('#ct-add-s-slot').addClass('disabled');
    } else {
      $('#ct-add-p-slot').removeClass('disabled');
      $('#ct-add-s-slot').removeClass('disabled');
    }
  }

  // helper: current max people (prefers localized value, else input value)
  function getCurrentMaxPeople(){
    var maxFromLoc = (typeof CT_TS_ADMIN !== 'undefined' && CT_TS_ADMIN.maxPeople) ? parseInt(CT_TS_ADMIN.maxPeople,10) : 0;
    var inputMax = parseInt($('#ct_max_people').val()||'0',10);
    if (inputMax && inputMax > 0) return inputMax;
    if (maxFromLoc && maxFromLoc > 0) return maxFromLoc;
    return 0;
  }

  // Add private slot: send capacity = maxPeople (from localized var or input)
  function addPrivateSlot(){
    if (!HAS_POST_ID) {
      requirePostId('Please save the tour/package before adding time slots.');
      return;
    }
    var specificRaw = $('#ct_specific_date').val().trim();
    var dateList = [];
    var targetDate = '';

    if (specificRaw) {
      var validation = validateSpecificDate(specificRaw);
      if(!validation.ok){ toast(validation.msg); return; }
      var specObj = parseDateInput(specificRaw);
      targetDate = dateToISO(specObj);
      dateList = [targetDate];
    } else {
      var fromRaw = $('#ct_date_from').val().trim();
      var toRaw = $('#ct_date_to').val().trim();
      var range = buildDateRange(fromRaw, toRaw);
      if(!range.ok){ toast(range.msg); return; }
      dateList = range.dates;
      targetDate = range.dates[0];
    }

    if (!dateList.length){
      toast('Provide either a specific date or a valid date range.');
      return;
    }

    var start = normTime($('#ct_p_start').val());
    var end   = normTime($('#ct_p_end').val());
    var price = parseFloat($('#ct_p_price').val()||'0');
    var promo = parseFloat($('#ct_p_promo').val()||'0');
    var disc  = parseFloat($('#ct_p_disc').val()||'0');
    var mode  = $('#ct_mode').val() || 'private';

    // decide capacity: prefer input value (unsaved), else localized value
    var capacityInventory = getCurrentMaxPeople();
    if (isNaN(capacityInventory) || capacityInventory < 0) capacityInventory = 0;

    if(!start || !end){ toast('Please fill Start and End (HH:MM).'); return; }
    if(price<=0 && promo<=0){ toast('Please enter a Price or Promo.'); return; }

    $.post(CT_TS_ADMIN.ajax, {
      action: 'ct_admin_add_slot',
      nonce:  CT_TS_ADMIN.nonce,
      post_id:CT_TS_ADMIN.postId,
      date:   targetDate,
      date_list: JSON.stringify(dateList),
      mode:   mode,
      start:  start,
      end:    end,
      price:  price,
      promo:  promo,
      disc:   disc,
      capacity: capacityInventory,
      post_max_people: getCurrentMaxPeople()
    }, function(res){
      console.log('ct_admin_add_slot response:', res);
      if(!res || !res.success){
        var msg = (res && res.data && res.data.msg) ? res.data.msg : 'Error adding slot.';
        if (res && res.data && res.data.db_error) msg += '\nDB error: ' + res.data.db_error;
        toast(msg);
        return;
      }
      if (res.data && typeof res.data.capacity_used !== 'undefined') {
        console.log('Server resolved capacity_used =', res.data.capacity_used);
      }
      var serverMsg = (res.data && res.data.message) ? res.data.message : '';
      if (serverMsg && dateList.length === 1) {
        toast(serverMsg);
      }
      $('#ct_p_start,#ct_p_end,#ct_p_price,#ct_p_promo,#ct_p_disc').val('');
      if (dateList.length === 1) {
        fetchSlotsFor(targetDate);
      } else {
        var rangeMsg = serverMsg || ('Created '+dateList.length+' slots across the selected range. Pick a specific date to review them.');
        toast(rangeMsg);
        $('#ct_slots_table tbody').html('<tr><td colspan="9">'+rangeMsg+'</td></tr>');
        setDateLabel('');
      }
    }, 'json').fail(function(xhr){
      console.error('AJAX addPrivateSlot failed', xhr.responseText);
      toast('AJAX error adding slot. See console (F12) for details.');
    });
  }

  function addSharedSlot(){
    if (!HAS_POST_ID) {
      requirePostId('Please save the tour/package before adding time slots.');
      return;
    }
    var date = $('#ct_specific_date').val();
    var validation = validateSpecificDate(date);
    if(!validation.ok){ toast(validation.msg); return; }

    var start = normTime($('#ct_s_start').val());
    var end   = normTime($('#ct_s_end').val());
    var cap   = parseInt($('#ct_s_capacity').val()||'0',10);
    var price = parseFloat($('#ct_s_price').val()||'0');
    var mode  = $('#ct_mode').val() || 'shared';
    var maxPeople = getCurrentMaxPeople();

    if(!start || !end){ toast('Please fill Start and End (HH:MM).'); return; }
    if(cap<1){ toast('Capacity must be at least 1.'); return; }
    if(maxPeople > 0 && cap > maxPeople){ toast('Capacity cannot exceed Max number of people ('+maxPeople+').'); return; }
    if(price<=0){ toast('Please enter a Price.'); return; }

    $.post(CT_TS_ADMIN.ajax, {
      action: 'ct_admin_add_slot',
      nonce:  CT_TS_ADMIN.nonce,
      post_id:CT_TS_ADMIN.postId,
      date:   date,
      mode:   mode,
      start:  start,
      end:    end,
      capacity: cap,
      price:  price,
      post_max_people: getCurrentMaxPeople()
    }, function(res){
      console.log('ct_admin_add_slot response:', res);
      if(!res || !res.success){
        var msg = (res && res.data && res.data.msg) ? res.data.msg : 'Error adding slot.';
        if (res && res.data && res.data.db_error) msg += '\nDB error: ' + res.data.db_error;
        toast(msg);
        return;
      }
      if (res.data && typeof res.data.capacity_used !== 'undefined') {
        console.log('Server resolved capacity_used =', res.data.capacity_used);
      }
      $('#ct_s_start,#ct_s_end,#ct_s_capacity,#ct_s_price').val('');
      fetchSlotsFor(date);
    }, 'json').fail(function(xhr){
      console.error('AJAX addSharedSlot failed', xhr.responseText);
      toast('AJAX error adding slot. See console (F12) for details.');
    });
  }

  function deleteSlot(id){
    if (!HAS_POST_ID) {
      requirePostId('Please save the tour/package before deleting time slots.');
      return;
    }
    var date = $('#ct_specific_date').val();
    $.post(CT_TS_ADMIN.ajax, {
      action:'ct_admin_delete_slot',
      nonce: CT_TS_ADMIN.nonce,
      post_id: CT_TS_ADMIN.postId,
      slot_id: id
    }, function(res){
      console.log('ct_admin_delete_slot response:', res);
      if(!res || !res.success){
        var msg = (res && res.data && res.data.msg) ? res.data.msg : 'Error deleting slot.';
        if (res && res.data && res.data.db_error) msg += '\nDB error: ' + res.data.db_error;
        toast(msg);
        return;
      }
      fetchSlotsFor(date);
    }, 'json').fail(function(xhr){
      console.error('AJAX delete failed', xhr.responseText);
      toast('AJAX error deleting slot. See console (F12) for details.');
    });
  }

  // When the specific date (or range inputs) change we validate and fetch
  $('#ct_specific_date, #ct_date_from, #ct_date_to').on('change', function(){
    var date = $('#ct_specific_date').val();
    if (!HAS_POST_ID) {
      showNeedsPostMessage();
      return;
    }
    var v = validateSpecificDate(date);
    if(!v.ok){
      setDateLabel('');
      // show inline message in table
      $('#ct_slots_table tbody').html('<tr><td colspan="9">'+v.msg+'</td></tr>');
      setAddDisabled(true);
      return;
    }
    // ok
    fetchSlotsFor(date);
  });

  $('#ct-add-p-slot').on('click', function(e){
    e.preventDefault();
    addPrivateSlot();
  });

  $('#ct-add-s-slot').on('click', function(e){
    e.preventDefault();
    addSharedSlot();
  });

  $('#ct_slots_table').on('click', '.ct-del', function(e){
    e.preventDefault();
    var id = parseInt($(this).data('id')||'0',10);
    if (!id) return;
    if (window.confirm('Delete this time slot?')){
      deleteSlot(id);
    }
  });

  if (!HAS_POST_ID) {
    showNeedsPostMessage();
    return;
  }

  // initial load
  var pre = $('#ct_specific_date').val();
  if (pre) {
    var v = validateSpecificDate(pre);
    if(v.ok) fetchSlotsFor(pre);
    else {
      $('#ct_slots_table tbody').html('<tr><td colspan="9">'+v.msg+'</td></tr>');
      setAddDisabled(true);
    }
  } else {
    setAddDisabled(true);
  }
});
