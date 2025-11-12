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
    let allSlotsCache = {}; // Cache all slots by date
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
            // Re-mark days when month changes
            picker.on('view', () => {
              setTimeout(markAvailableDays, 200);
            });
            // Re-mark days when calendar is rendered
            picker.on('render', () => {
              setTimeout(markAvailableDays, 200);
            });
            // Re-mark days when month is changed via navigation
            picker.on('changeMonth', () => {
              setTimeout(markAvailableDays, 200);
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
          // Re-mark days after calendar is moved
          if (Object.keys(availableDays).length > 0) {
            markAvailableDays();
          }
        }, 200);

        console.log('ComoTour Booking: Calendar initialized');
      } catch (e) {
        console.error('ComoTour Booking: Error initializing calendar', e);
      }
    }

    // Load all slots and available days from REST API (preload everything)
    function loadAllSlotsAndDays() {
      const today = new Date();
      const nextYear = new Date();
      nextYear.setFullYear(today.getFullYear() + 1);

      const from = today.toISOString().split('T')[0];
      const to = nextYear.toISOString().split('T')[0];

      // Use the new bulk endpoint to get all slots at once
      $.ajax({
        url: restBase + '/all-slots',
        method: 'GET',
        data: {
          post_id: postId,
          from: from,
          to: to,
          mode: mode
        },
        success: function(response) {
          if (response && response.ok) {
            // Cache all slots by date
            if (response.slots) {
              allSlotsCache = response.slots;
            }
            // Set available days for calendar highlighting
            if (response.days) {
              availableDays = response.days;
            }
            // Mark days after a short delay to ensure calendar is rendered
            setTimeout(function() {
              markAvailableDays();
              // Also try again after a longer delay in case calendar wasn't ready
              setTimeout(markAvailableDays, 500);
            }, 300);
            console.log('ComoTour Booking: All slots preloaded for', Object.keys(allSlotsCache).length, 'dates');
          }
        },
        error: function(xhr, status, error) {
          console.error('ComoTour Booking: Error loading all slots', error, xhr);
          // Fallback to old method if bulk endpoint fails
          loadAvailableDays();
        }
      });
    }

    // Fallback: Load available days only (old method)
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
            setTimeout(function() {
              markAvailableDays();
              setTimeout(markAvailableDays, 500);
            }, 300);
          }
        },
        error: function(xhr, status, error) {
          console.error('ComoTour Booking: Error loading days', error, xhr);
        }
      });
    }

    // Mark days with available slots in calendar
    function markAvailableDays() {
      if (!picker) return;
      if (Object.keys(availableDays).length === 0) return; // No dates to mark

      // Wait a bit for calendar to render
      setTimeout(function() {
        if (!picker.calendars || picker.calendars.length === 0) {
          // Try again if calendar not ready
          setTimeout(markAvailableDays, 300);
          return;
        }

        console.log('ComoTour Booking: Marking available days', Object.keys(availableDays).length, 'dates');

        // Try multiple selectors for Litepicker - check all calendars
        for (let i = 0; i < picker.calendars.length; i++) {
          const calendar = picker.calendars[i];
          if (!calendar) continue;
          
          // Get the month and year from the calendar
          let month = null;
          let year = null;
          
          // Try to get from Litepicker's internal state
          try {
            if (picker.options && picker.options.months && picker.options.months[i]) {
              const monthDate = picker.options.months[i];
              month = monthDate.getMonth();
              year = monthDate.getFullYear();
            }
          } catch(e) {}
          
          // Fallback: Parse from calendar header
          if (month === null || year === null) {
            const monthEl = calendar.querySelector('.month-item-name');
            const yearEl = calendar.querySelector('.month-item-year');
            
            if (monthEl && yearEl) {
              const monthText = monthEl.textContent.trim();
              year = parseInt(yearEl.textContent.trim());
              
              const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                                  'July', 'August', 'September', 'October', 'November', 'December'];
              month = monthNames.indexOf(monthText);
              
              if (month < 0) {
                // Try abbreviated names
                const monthAbbr = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                                   'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                month = monthAbbr.indexOf(monthText);
              }
            }
          }
          
          if (month === null || year === null || isNaN(year) || month < 0) {
            console.warn('ComoTour Booking: Could not determine month/year for calendar', i);
            continue;
          }
          
          // Find all day items
          const allDays = calendar.querySelectorAll('.day-item:not(.is-disabled)');
          
          allDays.forEach(function(dayEl) {
            const dayNum = parseInt(dayEl.textContent.trim());
            if (isNaN(dayNum) || dayNum < 1 || dayNum > 31) return;
            
            // Create date string for this day
            const testDate = new Date(year, month, dayNum);
            const dateStr = testDate.getFullYear() + '-' + 
                          String(testDate.getMonth() + 1).padStart(2, '0') + '-' + 
                          String(testDate.getDate()).padStart(2, '0');
            
            // Check if this date has slots
            if (availableDays[dateStr]) {
              dayEl.classList.add('ct-day-has-slots');
            } else {
              dayEl.classList.remove('ct-day-has-slots');
            }
          });
        }
        
        console.log('ComoTour Booking: Finished marking available days');
      }, 600);
    }

    // Handle date selection
    function handleDateSelect(iso) {
      selectedDate = iso;
      $selectedIso.text(iso);
      $selectedText.show();
      loadSlotsForDate(iso);
    }

    // Load slots for selected date (uses cache if available)
    function loadSlotsForDate(date) {
      // Check cache first for instant response
      if (allSlotsCache[date] && allSlotsCache[date].length > 0) {
        availableSlots = allSlotsCache[date];
        renderSlots();
        return;
      }

      // If not in cache, show loading and fetch from API (fallback)
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
            // Cache it for future use
            allSlotsCache[date] = response.slots;
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
        // Calculate remaining slots (how many more times this slot can be booked)
        const remainingSlots = Math.max(0, (slot.max_bookings || slot.capacity) - slot.booked);
        const isAvailable = remainingSlots > 0;
        const timeLabel = slot.time + ' – ' + slot.end;
        const capacity = slot.capacity || 1;
        
        html += '<button type="button" class="ct-slot-btn' + 
                (selectedSlot && selectedSlot.id === slot.id ? ' ct-slot-selected' : '') +
                (!isAvailable ? ' ct-slot-unavailable' : '') +
                '" data-slot-id="' + slot.id + 
                '" data-price="' + slot.price + 
                '" data-capacity="' + capacity +
                '" ' + (!isAvailable ? 'disabled' : '') + '>';
        
        html += '<div class="ct-slot-header">';
        html += '<span class="ct-slot-time">' + timeLabel + '</span>';
        html += '<span class="ct-slot-price">' + formatPrice(slot.price) + '</span>';
        html += '</div>';
        
        html += '<div class="ct-slot-details">';
        html += '<span class="ct-slot-capacity">Capacity: ' + capacity + ' people</span>';
        if (isAvailable) {
          html += '<span class="ct-slot-remaining">' + remainingSlots + ' slot' + (remainingSlots !== 1 ? 's' : '') + ' available</span>';
        } else {
          html += '<span class="ct-slot-soldout">Fully Booked</span>';
        }
        html += '</div>';
        
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

      // Update max people display and limit based on slot capacity
      const slotCapacity = selectedSlot.capacity || 1;
      $maxDisplay.text(slotCapacity);
      $('#ct_max_display_wrapper').show();
      
      // Adjust current people count if it exceeds capacity
      if (people > slotCapacity) {
        people = slotCapacity;
        $peopleInput.val(people);
      }

      // Update people control buttons state
      updatePeopleButtonsState();

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
      
      // Capture selected extras
      const selectedExtras = [];
      $extras.filter(':checked').each(function() {
        const $extra = $(this);
        const title = $extra.closest('label').find('.ct-extra-title').text().trim();
        selectedExtras.push({
          label: title || $extra.data('id') || '',
          title: title,
          price: parseFloat($extra.data('price') || 0)
        });
      });
      
      // Store extras as JSON in hidden field
      $('#ct_extras_hidden').val(JSON.stringify(selectedExtras));
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

    // Update people control buttons state
    function updatePeopleButtonsState() {
      if (!selectedSlot) {
        // Disable buttons when no slot is selected
        $peopleMinus.prop('disabled', true).addClass('ct-step-disabled');
        $peoplePlus.prop('disabled', true).addClass('ct-step-disabled');
      } else {
        // Enable buttons when slot is selected
        $peopleMinus.prop('disabled', false).removeClass('ct-step-disabled');
        $peoplePlus.prop('disabled', false).removeClass('ct-step-disabled');
        
        // Disable minus if at minimum (1)
        if (people <= 1) {
          $peopleMinus.prop('disabled', true).addClass('ct-step-disabled');
        }
        
        // Disable plus if at maximum capacity
        const maxPeople = selectedSlot.capacity || 1;
        if (people >= maxPeople) {
          $peoplePlus.prop('disabled', true).addClass('ct-step-disabled');
        }
      }
    }

    // People controls
    $peopleMinus.on('click', function() {
      if (!selectedSlot) return; // Prevent action if no slot selected
      if (people > 1) {
        people--;
        $peopleInput.val(people);
        updatePeopleButtonsState();
        updateHiddenFields();
        updateTotal();
      }
    });

    $peoplePlus.on('click', function() {
      if (!selectedSlot) return; // Prevent action if no slot selected
      // Get max from selected slot's capacity
      const maxPeople = selectedSlot.capacity || 1;
      
      if (people < maxPeople) {
        people++;
        $peopleInput.val(people);
        updatePeopleButtonsState();
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
      // Hide max capacity display and reset
      $('#ct_max_display_wrapper').hide();
      $maxDisplay.text('—');
      selectedSlot = null;
      selectedDate = null;
      people = 1;
      $peopleInput.val(people);
      
      // Disable people control buttons
      updatePeopleButtonsState();
      
      updateHiddenFields();
      updateTotal();
    });

    // Extras change
    $extras.on('change', function() {
      updateHiddenFields();
      updateTotal();
    });

    // Form submission validation and data sync
    $(document).on('submit', 'form.cart', function(e) {
      // Update all hidden fields before submission
      updateHiddenFields();
      
      // Validate required fields
      if (!selectedDate || !selectedDate.trim()) {
        e.preventDefault();
        alert('Please select a booking date.');
        return false;
      }
      
      if (!selectedSlot || !selectedSlot.id) {
        e.preventDefault();
        alert('Please select a time slot.');
        return false;
      }
      
      // Ensure hidden fields are populated
      const $dateField = $('#ct_date_hidden');
      const $slotField = $('#ct_slot_id_hidden');
      
      if (!$dateField.val() || !$slotField.val()) {
        e.preventDefault();
        alert('Please complete your booking selection.');
        return false;
      }
      
      return true;
    });

    // Initialize
    initCalendar();
    loadAllSlotsAndDays(); // Preload all slots for instant response
    updatePeopleButtonsState(); // Disable buttons initially
  });
})();

