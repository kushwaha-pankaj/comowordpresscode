/**
 * ComoTour Frontend Booking Calendar
 * Uses Litepicker for date selection
 */
(function() {
  'use strict';

  // Wait for DOM and dependencies
  if (typeof jQuery === 'undefined' || typeof Litepicker === 'undefined') {
    console.error('ComoTour Booking: jQuery or Litepicker not loaded');
    return;
  }

  jQuery(document).ready(function($) {
    const $card = $('#ct-booking-card');
    if (!$card.length) {
      console.log('ComoTour Booking: Booking card not found');
      return;
    }

    const postId = parseInt($card.data('post-id') || (typeof CT_BOOKING !== 'undefined' ? CT_BOOKING.postId : 0) || 0);
    const mode = $card.data('mode') || 'private';
    const restBase = (typeof CT_BOOKING !== 'undefined' ? CT_BOOKING.restBase : '') || '/wp-json/ct-timeslots/v1';
    const currency = (typeof CT_BOOKING !== 'undefined' ? CT_BOOKING.currency : '') || 'EUR';

    if (!postId) {
      console.error('ComoTour Booking: No post ID found');
      return;
    }

    console.log('ComoTour Booking: Initializing for post', postId, 'mode', mode);

    // Elements
    const $dateContainer = $('#ct_date_inline');
    const $selectedText = $('#ct_selected_text');
    const $selectedIso = $('#ct_selected_iso');
    const $clearBtn = $('#ct_clear_selected');
    const $slotsList = $('#ct_slots_list');
    const $peopleInput = $('#ct_people');
    const $peopleMinus = $('#ct_people_minus');
    const $peoplePlus = $('#ct_people_plus');
    const $maxDisplay = $('#ct_max_display');
    const $totalPrice = $('#ct_total_price');
    const $extras = $('.ct-extra-checkbox');

    // State
    let selectedDate = null;
    let selectedSlot = null;
    let availableDays = {};
    let availableSlots = [];
    let picker = null;
    let people = 1;

    // Initialize Litepicker
    function initCalendar() {
      if (!$dateContainer.length) {
        console.error('ComoTour Booking: Date container not found');
        return;
      }

      // Create a hidden input for Litepicker
      const $hiddenInput = $('<input type="text" id="ct_date_picker_input" style="display:none;">');
      $dateContainer.append($hiddenInput);

      // Calculate date range (next 12 months)
      const today = new Date();
      const nextYear = new Date();
      nextYear.setFullYear(today.getFullYear() + 1);

      const inputEl = document.getElementById('ct_date_picker_input');
      if (!inputEl) {
        console.error('ComoTour Booking: Date picker input not found');
        return;
      }

      try {
        picker = new Litepicker({
          element: inputEl,
          inlineMode: true,
          singleMode: true,
          minDate: today,
          maxDate: nextYear,
          format: 'YYYY-MM-DD',
          setup: function(picker) {
            picker.on('selected', (date) => {
              if (date) {
                const iso = date.format('YYYY-MM-DD');
                handleDateSelect(iso);
              }
            });
          },
          plugins: []
        });

        // Move the calendar container to the date container
        setTimeout(function() {
          const calendarEl = document.querySelector('.litepicker');
          if (calendarEl && $dateContainer.length) {
            $dateContainer.append(calendarEl);
          }
        }, 100);

        console.log('ComoTour Booking: Calendar initialized');
      } catch (e) {
        console.error('ComoTour Booking: Error initializing calendar', e);
      }
    }

    // Load available days from REST API
    function loadAvailableDays() {
      const today = new Date();
      const nextYear = new Date();
      nextYear.setFullYear(today.getFullYear() + 1);

      const from = today.toISOString().split('T')[0];
      const to = nextYear.toISOString().split('T')[0];

      $.ajax({
        url: restBase + '/days',
        method: 'GET',
        data: {
          post_id: postId,
          from: from,
          to: to,
          mode: mode
        },
        success: function(response) {
          if (response && response.ok && response.days) {
            availableDays = response.days;
            markAvailableDays();
          }
        },
        error: function(xhr, status, error) {
          console.error('ComoTour Booking: Error loading days', error, xhr);
        }
      });
    }

    // Mark days with available slots in calendar
    function markAvailableDays() {
      if (!picker || !picker.calendars || !picker.calendars[0]) return;

      // Wait a bit for calendar to render
      setTimeout(function() {
        Object.keys(availableDays).forEach(function(dateStr) {
          // Try multiple selectors for Litepicker
          const dayEl = picker.calendars[0].querySelector(`[data-time="${dateStr}"]`) ||
                       picker.calendars[0].querySelector(`[data-day="${dateStr}"]`) ||
                       picker.calendars[0].querySelector(`[data-date="${dateStr}"]`);
          
          if (dayEl) {
            dayEl.classList.add('ct-day-has-slots');
          }
        });
      }, 200);
    }

    // Handle date selection
    function handleDateSelect(iso) {
      selectedDate = iso;
      $selectedIso.text(iso);
      $selectedText.show();
      loadSlotsForDate(iso);
    }

    // Load slots for selected date
    function loadSlotsForDate(date) {
      $slotsList.html('<div class="ct-slot-hint">Loading times...</div>');

      $.ajax({
        url: restBase + '/slots',
        method: 'GET',
        data: {
          post_id: postId,
          date: date,
          mode: mode
        },
        success: function(response) {
          if (response && response.ok && response.slots && response.slots.length > 0) {
            availableSlots = response.slots;
            renderSlots();
          } else {
            $slotsList.html('<div class="ct-slot-hint">No time slots available for this date.</div>');
            availableSlots = [];
            selectedSlot = null;
            updateTotal();
          }
        },
        error: function(xhr, status, error) {
          console.error('ComoTour Booking: Error loading slots', error, xhr);
          $slotsList.html('<div class="ct-slot-hint">Error loading time slots. Please try again.</div>');
        }
      });
    }

    // Render available time slots
    function renderSlots() {
      if (!availableSlots.length) {
        $slotsList.html('<div class="ct-slot-hint">No time slots available.</div>');
        return;
      }

      let html = '<div class="ct-slots-grid">';
      availableSlots.forEach(function(slot) {
        const available = mode === 'shared' 
          ? Math.max(0, slot.capacity - slot.booked)
          : (slot.max_bookings || slot.capacity) - slot.booked;
        
        const isAvailable = available > 0;
        const timeLabel = slot.time + ' – ' + slot.end;
        
        html += '<button type="button" class="ct-slot-btn' + 
                (selectedSlot && selectedSlot.id === slot.id ? ' ct-slot-selected' : '') +
                (!isAvailable ? ' ct-slot-unavailable' : '') +
                '" data-slot-id="' + slot.id + 
                '" data-price="' + slot.price + 
                '" ' + (!isAvailable ? 'disabled' : '') + '>';
        html += '<span class="ct-slot-time">' + timeLabel + '</span>';
        html += '<span class="ct-slot-price">' + formatPrice(slot.price) + '</span>';
        if (mode === 'shared' && isAvailable) {
          html += '<span class="ct-slot-available">' + available + ' seats left</span>';
        }
        html += '</button>';
      });
      html += '</div>';
      
      $slotsList.html(html);

      // Attach click handlers
      $slotsList.find('.ct-slot-btn:not(.ct-slot-unavailable)').on('click', function() {
        const slotId = parseInt($(this).data('slot-id'));
        selectSlot(slotId);
      });
    }

    // Select a time slot
    function selectSlot(slotId) {
      selectedSlot = availableSlots.find(s => s.id === slotId);
      if (!selectedSlot) return;

      // Update UI
      $slotsList.find('.ct-slot-btn').removeClass('ct-slot-selected');
      $slotsList.find('.ct-slot-btn[data-slot-id="' + slotId + '"]').addClass('ct-slot-selected');

      // Update hidden fields for form submission
      updateHiddenFields();
      updateTotal();
    }

    // Update hidden form fields
    function updateHiddenFields() {
      $('#ct_date_hidden').val(selectedDate || '');
      $('#ct_slot_id_hidden').val(selectedSlot ? selectedSlot.id : '');
      $('#ct_mode_hidden').val(mode);
      $('#ct_people_hidden').val(people);
    }

    // Update total price
    function updateTotal() {
      if (!selectedSlot) {
        $totalPrice.text(formatPrice(0));
        return;
      }

      let total = selectedSlot.price;
      
      // Add extras
      $extras.filter(':checked').each(function() {
        total += parseFloat($(this).data('price') || 0);
      });

      // Multiply by people for private tours
      if (mode === 'private') {
        total = total * people;
      }

      $totalPrice.text(formatPrice(total));
    }

    // Format price
    function formatPrice(amount) {
      if (currency === 'EUR' || currency === '€') {
        return '€' + parseFloat(amount).toFixed(2);
      } else if (currency === 'USD' || currency === '$') {
        return '$' + parseFloat(amount).toFixed(2);
      } else {
        return parseFloat(amount).toFixed(2) + ' ' + currency;
      }
    }

    // People controls
    $peopleMinus.on('click', function() {
      if (people > 1) {
        people--;
        $peopleInput.val(people);
        updateHiddenFields();
        updateTotal();
      }
    });

    $peoplePlus.on('click', function() {
      const maxPeople = parseInt($maxDisplay.text()) || 999;
      if (people < maxPeople) {
        people++;
        $peopleInput.val(people);
        updateHiddenFields();
        updateTotal();
      }
    });

    // Clear selection
    $clearBtn.on('click', function(e) {
      e.preventDefault();
      selectedDate = null;
      selectedSlot = null;
      $selectedText.hide();
      $slotsList.html('<div class="ct-slot-hint">Select a date to view available times</div>');
      if (picker) {
        picker.clearSelection();
      }
      updateHiddenFields();
      updateTotal();
    });

    // Extras change
    $extras.on('change', function() {
      updateTotal();
    });

    // Initialize
    initCalendar();
    loadAvailableDays();
  });
})();

