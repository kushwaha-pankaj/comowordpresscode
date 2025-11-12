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

  // Note: post_id can be 0 for unsaved posts - slots will be stored in transient until post is saved
  var POST_ID = typeof CT_TS_ADMIN !== 'undefined' ? parseInt(CT_TS_ADMIN.postId || '0', 10) : 0;

  function dateToISO(dateObj){
    if (!(dateObj instanceof Date)) return '';
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

  function currentSpecificDate(){
    return ($('#ct_specific_date').val() || '').trim();
  }

  function currentRange(){
    return {
      from: ($('#ct_date_from').val() || '').trim(),
      to: ($('#ct_date_to').val() || '').trim()
    };
  }

  function updateAddState(options){
    var opts = options || {};

    var specific = currentSpecificDate();
    if (specific){
      var validation = validateSpecificDate(specific);
      if(!validation.ok){
        setAddDisabled(true);
        setDateLabel('');
        if (opts.updateTable !== false) {
          $('#ct_slots_table tbody').html('<tr><td colspan="9">'+validation.msg+'</td></tr>');
        }
        return false;
      }
      setAddDisabled(false);
      return true;
    }

    var range = currentRange();
    if (range.from && range.to){
      setAddDisabled(false);
      var label = range.from + ' → ' + range.to;
      setDateLabel(label);
      if (opts.updateTable !== false) {
        $('#ct_slots_table tbody').html('<tr><td colspan="9">Range selected: '+label+'. Add a slot to duplicate across these dates, or pick a specific date to view/edit individual slots.</td></tr>');
      }
      return true;
    }

    setAddDisabled(true);
    setDateLabel('');
    if (opts.updateTable !== false) {
      $('#ct_slots_table tbody').html('<tr><td colspan="9">Pick a specific date or provide a From/To range to view slots…</td></tr>');
    }
    return false;
  }

  /* ---------- pickers init ---------- */
  function initPickers(){
    $('.ct-date').each(function(){
      if (this._fp) return;
      $(this).flatpickr({
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd/m/Y',
        allowInput: true,
        minDate: 'today' // Prevent past dates
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
    // Update table headers when mode changes
    updateTableHeaders();
  }
  $('#ct_mode').on('change', toggleMode);
  toggleMode();

  function updateTableHeaders(){
    var mode = currentMode();
    var isShared = mode === 'shared';
    var headers = [
      '<th style="width:30px;"><input type="checkbox" id="ct_select_all_checkbox" title="Select all"></th>',
      '<th>Date</th>',
      '<th>Type</th>',
      '<th>Start</th>',
      '<th>End</th>',
      '<th>Duration</th>'
    ];
    
    if (isShared) {
      headers.push('<th>Seats Available</th>');
      headers.push('<th>Max Bookings</th>');
    } else {
      headers.push('<th>Capacity</th>');
      headers.push('<th>Max Bookings</th>');
    }
    
    headers.push('<th>Price (€)</th>');
    headers.push('<th>Booked</th>');
    headers.push('<th>Actions</th>');
    
    $('#ct_table_header').html(headers.join(''));
  }

  function ucfirst(s){ return s.charAt(0).toUpperCase() + s.slice(1); }

  var currentPage = 1;
  var totalPages = 1;
  var currentFilterDate = '';

  function renderRows(slots, append){
    var $tb = $('#ct_slots_table tbody');
    if (!append) $tb.empty();
    
    if (!slots || !slots.length){
      if (!append) {
        var mode = currentMode();
        var colCount = mode === 'shared' ? 11 : 11;
        $tb.append('<tr><td colspan="'+colCount+'" style="text-align:center;padding:20px;color:#666;">No time slots found.</td></tr>');
      }
      updateBulkActions();
      return;
    }
    
    var mode = currentMode();
    var isShared = mode === 'shared';
    
    slots.forEach(function(s){
      var row = $('<tr data-slot="'+s.id+'">');
      
      // Checkbox for bulk selection
      var checkbox = $('<input type="checkbox" class="ct-slot-checkbox" data-slot-id="'+s.id+'">');
      row.append($('<td>').append(checkbox));
      
      row.append('<td>'+s.date+'</td>');
      row.append('<td>'+ucfirst(s.mode||'private')+'</td>');
      row.append('<td>'+s.time+'</td>');
      row.append('<td>'+s.end+'</td>');
      row.append('<td>'+s.duration+'m</td>');
      
      if (isShared) {
        // For shared: show seats available (available / total)
        var available = Math.max(0, s.capacity - (s.booked||0));
        row.append('<td><strong>'+available+'</strong> / '+s.capacity+'</td>');
      } else {
        // For private: show capacity
        row.append('<td>'+s.capacity+'</td>');
      }
      
      // Max Bookings column (read-only)
      var maxBookingsValue = s.max_bookings || s.capacity || 1;
      row.append('<td style="text-align:center;font-weight:600;color:#23282d;">'+maxBookingsValue+'</td>');
      
      row.append('<td>'+Number(s.price).toFixed(2)+'</td>');
      row.append('<td>'+(s.booked||0)+'</td>');
      row.append('<td><button class="button button-link-delete ct-del" data-id="'+s.id+'">Delete</button></td>');
      $tb.append(row);
    });
    
    updateBulkActions();
  }

  function updateBulkActions(){
    var checked = $('.ct-slot-checkbox:checked').length;
    var $bulkActions = $('#ct_bulk_actions');
    var $selectedCount = $('#ct_selected_count');
    
    if (checked > 0) {
      $bulkActions.show();
      $selectedCount.text(checked + ' slot' + (checked === 1 ? '' : 's') + ' selected');
    } else {
      $bulkActions.hide();
      $selectedCount.text('');
    }
  }

  function renderPagination(page, totalPages){
    var $pagination = $('#ct_slots_pagination');
    if (totalPages <= 1) {
      $pagination.hide();
      return;
    }
    
    $pagination.show();
    var html = '<div style="display:flex;justify-content:center;align-items:center;gap:12px;flex-wrap:wrap;">';
    
    // Previous button
    if (page > 1) {
      html += '<button class="button" id="ct-prev-page" style="padding:10px 20px;font-weight:600;border-radius:6px;transition:all 0.2s;">← Previous</button>';
    } else {
      html += '<button class="button" disabled style="padding:10px 20px;opacity:0.5;cursor:not-allowed;border-radius:6px;">← Previous</button>';
    }
    
    // Page info
    html += '<div style="display:flex;align-items:center;gap:8px;padding:8px 16px;background:#f6f7f7;border-radius:6px;font-weight:600;color:#23282d;">';
    html += '<span>Page</span>';
    html += '<span style="background:#0073aa;color:#fff;padding:4px 12px;border-radius:4px;min-width:30px;text-align:center;">' + page + '</span>';
    html += '<span>of</span>';
    html += '<span style="color:#646970;">' + totalPages + '</span>';
    html += '</div>';
    
    // Next button
    if (page < totalPages) {
      html += '<button class="button" id="ct-next-page" style="padding:10px 20px;font-weight:600;border-radius:6px;transition:all 0.2s;">Next →</button>';
    } else {
      html += '<button class="button" disabled style="padding:10px 20px;opacity:0.5;cursor:not-allowed;border-radius:6px;">Next →</button>';
    }
    
    // Load More button (if not on last page)
    if (page < totalPages) {
      html += '<button class="button button-primary" id="ct-load-more" style="padding:10px 24px;font-weight:600;border-radius:6px;margin-left:12px;transition:all 0.2s;">Load More</button>';
    }
    
    html += '</div>';
    $pagination.html(html);
  }

  function loadAllSlots(page, append, loadAll){
    var filterDate = $('#ct_table_date_filter').val().trim();
    var dateObj = filterDate ? parseDateInput(filterDate) : null;
    var isoFilterDate = dateObj ? dateToISO(dateObj) : '';
    
    // If loadAll is true, set per_page to a very large number
    var perPage = loadAll ? 9999 : 10;
    
    $.post(CT_TS_ADMIN.ajax, {
      action:'ct_admin_get_all_slots',
      nonce: CT_TS_ADMIN.nonce,
      post_id: POST_ID,
      filter_date: isoFilterDate,
      page: loadAll ? 1 : page,
      per_page: perPage
    }, function(res){
      if(!res || !res.success){ 
        if (!append) {
          $('#ct_slots_table tbody').html('<tr><td colspan="10">Error loading slots.</td></tr>');
        }
        return; 
      }
      
      currentPage = res.data.page;
      totalPages = res.data.total_pages;
      currentFilterDate = isoFilterDate;
      
      // Update table headers before rendering (in case mode changed)
      if (!append) {
        updateTableHeaders();
      }
      renderRows(res.data.slots || [], append);
      // Hide pagination if loading all
      if (loadAll) {
        $('#ct_slots_pagination').hide();
      } else {
        renderPagination(currentPage, totalPages);
      }
    }, 'json').fail(function(xhr, status, err){
      console.error('AJAX loadAllSlots failed', status, err, xhr.responseText);
      if (!append) {
        $('#ct_slots_table tbody').html('<tr><td colspan="10">Error loading slots. See console (F12) for details.</td></tr>');
      }
    });
  }

  function fetchSlotsFor(date){
    setDateLabel(date);
    if(!date){
      $('#ct_slots_table tbody').html('<tr><td colspan="9">Pick a date to load time slots…</td></tr>');
      setAddDisabled(true);
      return;
    }

    // run validation to ensure date is acceptable before fetching
    var v = validateSpecificDate(date);
    if(!v.ok){
      $('#ct_slots_table tbody').html('<tr><td colspan="9">'+v.msg+'</td></tr>');
      setAddDisabled(true);
      return;
    }

    // Convert date to ISO format (Y-m-d) for the AJAX call
    var dateObj = parseDateInput(date);
    if (!dateObj) {
      $('#ct_slots_table tbody').html('<tr><td colspan="9">Invalid date format.</td></tr>');
      setAddDisabled(true);
      return;
    }
    var isoDate = dateToISO(dateObj);
    if (!isoDate) {
      $('#ct_slots_table tbody').html('<tr><td colspan="9">Could not convert date to ISO format.</td></tr>');
      setAddDisabled(true);
      return;
    }

    setAddDisabled(false);

    $.post(CT_TS_ADMIN.ajax, {
      action:'ct_admin_get_slots_by_date',
      nonce: CT_TS_ADMIN.nonce,
      post_id: POST_ID,
      date: isoDate
    }, function(res){
      console.log('ct_admin_get_slots_by_date response:', res);
      if(!res || !res.success){ 
        var errorMsg = (res && res.data && res.data.msg) ? res.data.msg : 'Error fetching slots.';
        $('#ct_slots_table tbody').html('<tr><td colspan="9">'+errorMsg+'</td></tr>');
        renderRows([]); 
        return; 
      }
      renderRows(res.data.slots||[]);
    }, 'json').fail(function(xhr, status, err){
      console.error('AJAX fetchSlotsFor failed', status, err, xhr.responseText);
      var errorMsg = 'AJAX error fetching slots.';
      if (xhr.responseText) {
        try {
          var errorData = JSON.parse(xhr.responseText);
          if (errorData.data && errorData.data.msg) {
            errorMsg = errorData.data.msg;
          }
        } catch(e) {
          // Use default error message
        }
      }
      $('#ct_slots_table tbody').html('<tr><td colspan="9">'+errorMsg+'</td></tr>');
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

  // Add private slot
  function addPrivateSlot(){
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

    // Get capacity and max_bookings from input fields for private tours
    var capacity = parseInt($('#ct_p_capacity').val()||'0',10);
    var maxBookings = parseInt($('#ct_p_max_bookings').val()||'0',10);
    if (isNaN(capacity) || capacity < 1) {
      toast('Please enter a capacity (minimum 1 person).');
      return;
    }
    if (isNaN(maxBookings) || maxBookings < 1) {
      toast('Please enter max bookings (minimum 1 booking).');
      return;
    }

    if(!start || !end){ toast('Please fill Start and End (HH:MM).'); return; }
    if(price<=0 && promo<=0){ toast('Please enter a Price or Promo.'); return; }

    $.post(CT_TS_ADMIN.ajax, {
      action: 'ct_admin_add_slot',
      nonce:  CT_TS_ADMIN.nonce,
      post_id:POST_ID,
      date:   targetDate,
      date_list: JSON.stringify(dateList),
      mode:   mode,
      start:  start,
      end:    end,
      price:  price,
      promo:  promo,
      disc:   disc,
      capacity: capacity,
      max_bookings: maxBookings
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
      if (serverMsg) {
        toast(serverMsg);
      } else if (dateList.length > 1) {
        toast('Created ' + dateList.length + ' slots across the selected range.');
      }
      $('#ct_p_start,#ct_p_end,#ct_p_capacity,#ct_p_max_bookings,#ct_p_price,#ct_p_promo,#ct_p_disc').val('');
      // Reload all slots after adding
      currentPage = 1;
      loadAllSlots(1, false, false);
    }, 'json').fail(function(xhr){
      console.error('AJAX addPrivateSlot failed', xhr.responseText);
      toast('AJAX error adding slot. See console (F12) for details.');
    });
  }

  function addSharedSlot(){
    var date = $('#ct_specific_date').val();
    var validation = validateSpecificDate(date);
    if(!validation.ok){ toast(validation.msg); return; }

    var start = normTime($('#ct_s_start').val());
    var end   = normTime($('#ct_s_end').val());
    var cap   = parseInt($('#ct_s_capacity').val()||'0',10);
    var maxBookings = parseInt($('#ct_s_max_bookings').val()||'0',10);
    var price = parseFloat($('#ct_s_price').val()||'0');
    var mode  = $('#ct_mode').val() || 'shared';

    if(!start || !end){ toast('Please fill Start and End (HH:MM).'); return; }
    if(cap<1){ toast('Capacity must be at least 1.'); return; }
    if (isNaN(maxBookings) || maxBookings < 1) {
      toast('Please enter max bookings (minimum 1 booking).');
      return;
    }
    if(price<=0){ toast('Please enter a Price.'); return; }

    $.post(CT_TS_ADMIN.ajax, {
      action: 'ct_admin_add_slot',
      nonce:  CT_TS_ADMIN.nonce,
      post_id:POST_ID,
      date:   date,
      mode:   mode,
      start:  start,
      end:    end,
      capacity: cap,
      max_bookings: maxBookings,
      price:  price
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
      $('#ct_s_start,#ct_s_end,#ct_s_capacity,#ct_s_max_bookings,#ct_s_price').val('');
      // Reload all slots after adding
      currentPage = 1;
      loadAllSlots(1, false, false);
    }, 'json').fail(function(xhr){
      console.error('AJAX addSharedSlot failed', xhr.responseText);
      toast('AJAX error adding slot. See console (F12) for details.');
    });
  }

  function deleteSlot(id){
    $.post(CT_TS_ADMIN.ajax, {
      action:'ct_admin_delete_slot',
      nonce: CT_TS_ADMIN.nonce,
      post_id: POST_ID,
      slot_id: id
    }, function(res){
      console.log('ct_admin_delete_slot response:', res);
      if(!res || !res.success){
        var msg = (res && res.data && res.data.msg) ? res.data.msg : 'Error deleting slot.';
        if (res && res.data && res.data.db_error) msg += '\nDB error: ' + res.data.db_error;
        toast(msg);
        return;
      }
      // Reload all slots after deleting
      loadAllSlots(currentPage, false);
    }, 'json').fail(function(xhr){
      console.error('AJAX delete failed', xhr.responseText);
      toast('AJAX error deleting slot. See console (F12) for details.');
    });
  }

  // When the specific date (or range inputs) change we validate
  $('#ct_specific_date, #ct_date_from, #ct_date_to').on('change', function(){
    var specific = currentSpecificDate();
    if (specific){
      var validation = validateSpecificDate(specific);
      if(!validation.ok){
        setAddDisabled(true);
        setDateLabel('');
        return;
      }
      var specDate = parseDateInput(specific);
      var iso = specDate ? dateToISO(specDate) : specific;
      $('#ct_specific_date').val(iso);
      setAddDisabled(false);
      return;
    }

    updateAddState();
  });

  $('#ct_clear_specific_date').on('click', function(e){
    e.preventDefault();
    $('#ct_specific_date').val('');
    // Clear the flatpickr instance if it exists
    var fp = $('#ct_specific_date')[0]._flatpickr;
    if (fp) {
      fp.clear();
    }
    setDateLabel('');
    setAddDisabled(true);
    updateAddState();
  });

  // Table date filter
  $('#ct_table_date_filter').on('change', function(){
    currentPage = 1;
    loadAllSlots(1, false, false);
  });

  $('#ct_clear_table_filter').on('click', function(e){
    e.preventDefault();
    var $filterInput = $('#ct_table_date_filter');
    var inputEl = $filterInput[0];
    
    // Clear Flatpickr instance if it exists
    if (inputEl) {
      // Flatpickr stores instance on _flatpickr property
      var fp = inputEl._flatpickr;
      
      if (fp && typeof fp.clear === 'function') {
        // Clear using Flatpickr's clear method (this also clears altInput)
        fp.clear();
      } else {
        // Fallback: manually clear the input
        $filterInput.val('');
        // Also clear altInput if it exists (Flatpickr creates this when altInput: true)
        var altInput = $filterInput.siblings('input.flatpickr-input');
        if (altInput.length) {
          altInput.val('');
        }
      }
    }
    
    // Reset pagination and reload all slots
    currentPage = 1;
    currentFilterDate = '';
    loadAllSlots(1, false, false);
  });

  // Pagination handlers
  $(document).on('click', '#ct-prev-page', function(e){
    e.preventDefault();
    if (currentPage > 1) {
      loadAllSlots(currentPage - 1, false, false);
    }
  });

  $(document).on('click', '#ct-next-page', function(e){
    e.preventDefault();
    if (currentPage < totalPages) {
      loadAllSlots(currentPage + 1, false, false);
    }
  });

  $(document).on('click', '#ct-load-more', function(e){
    e.preventDefault();
    if (currentPage < totalPages) {
      loadAllSlots(currentPage + 1, true, false); // Append mode
    }
  });
  
  // Load All button handler
  $(document).on('click', '#ct_load_all_slots', function(e){
    e.preventDefault();
    loadAllSlots(1, false, true);
  });

  // Removed editable max bookings functionality - now read-only

  // Bulk selection handlers
  $(document).on('change', '#ct_select_all_checkbox', function(){
    var checked = $(this).is(':checked');
    $('.ct-slot-checkbox').prop('checked', checked);
    updateBulkActions();
  });

  $(document).on('change', '.ct-slot-checkbox', function(){
    updateBulkActions();
    // Update select all checkbox state
    var total = $('.ct-slot-checkbox').length;
    var checked = $('.ct-slot-checkbox:checked').length;
    $('#ct_select_all_checkbox').prop('checked', total > 0 && checked === total);
  });

  $('#ct_select_all_slots').on('click', function(e){
    e.preventDefault();
    $('.ct-slot-checkbox').prop('checked', true);
    $('#ct_select_all_checkbox').prop('checked', true);
    updateBulkActions();
  });

  $('#ct_deselect_all_slots').on('click', function(e){
    e.preventDefault();
    $('.ct-slot-checkbox').prop('checked', false);
    $('#ct_select_all_checkbox').prop('checked', false);
    updateBulkActions();
  });

  $('#ct_bulk_delete_slots').on('click', function(e){
    e.preventDefault();
    var selected = [];
    $('.ct-slot-checkbox:checked').each(function(){
      var slotId = parseInt($(this).data('slot-id'), 10);
      if (slotId) {
        selected.push(slotId);
      }
    });
    
    if (selected.length === 0) {
      toast('Please select at least one slot to delete.');
      return;
    }
    
    if (!window.confirm('Are you sure you want to delete ' + selected.length + ' selected slot' + (selected.length === 1 ? '' : 's') + '?')) {
      return;
    }
    
    $(this).prop('disabled', true).text('Deleting...');
    
    $.post(CT_TS_ADMIN.ajax, {
      action: 'ct_admin_bulk_delete_slots',
      nonce: CT_TS_ADMIN.nonce,
      post_id: POST_ID,
      slot_ids: selected
    }, function(res){
      $('#ct_bulk_delete_slots').prop('disabled', false).text('Delete Selected');
      if (res && res.success) {
        toast(res.data.message || 'Slots deleted successfully.');
        loadAllSlots(currentPage, false);
      } else {
        var msg = (res && res.data && res.data.msg) ? res.data.msg : 'Error deleting slots.';
        toast(msg);
      }
    }, 'json').fail(function(){
      $('#ct_bulk_delete_slots').prop('disabled', false).text('Delete Selected');
      toast('Error deleting slots. Please try again.');
    });
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

  // Initial load
  loadAllSlots(1, false, false);
  
  // Also initialize the table date filter picker
  setTimeout(function(){
    if ($('#ct_table_date_filter').length && !$('#ct_table_date_filter')[0]._fp) {
      $('#ct_table_date_filter').flatpickr({
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd/m/Y',
        allowInput: true,
        minDate: 'today'
      });
      $('#ct_table_date_filter')[0]._fp = true;
    }
  }, 100);
});
