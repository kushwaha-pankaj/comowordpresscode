<?php
if (!defined('ABSPATH')) exit;

/* Force EUR currency for all price displays */
add_filter('woocommerce_currency', function($currency) {
  return 'EUR';
}, 999);

add_filter('woocommerce_currency_symbol', function($symbol, $currency) {
  return '€';
}, 999, 2);

/* helper to read $_REQUEST */
if (!function_exists('ct_req')) {
  function ct_req($key, $default = '') {
    if (!isset($_REQUEST[$key])) return $default;
    $v = $_REQUEST[$key];
    if (is_array($v)) return $v;
    return sanitize_text_field(wp_unslash($v));
  }
}

/* 0) Validate required booking fields */
add_filter('woocommerce_add_to_cart_validation', function($passed, $product_id, $quantity, $variation_id = null, $variations = null){
  $date    = ct_req('ct_date')    ?: ct_req('date') ?: ct_req('booking_date');
  $slot_id = ct_req('ct_slot_id') ?: ct_req('slot_id') ?: ct_req('time_slot') ?: ct_req('timeslot');
  if (!$date)    { wc_add_notice(__('Please select a booking date.', 'comotour'), 'error'); return false; }
  if (!$slot_id) { wc_add_notice(__('Please select a time slot.', 'comotour'), 'error');   return false; }
  return $passed;
}, 10, 5);

/* 1) Add booking data to cart item */
add_filter('woocommerce_add_cart_item_data', function ($cart_item_data, $product_id, $variation_id) {

  $date    = ct_req('ct_date')    ?: ct_req('date') ?: ct_req('booking_date');
  $slot_id = ct_req('ct_slot_id') ?: ct_req('slot_id') ?: ct_req('time_slot') ?: ct_req('timeslot');
  $mode    = ct_req('ct_mode')    ?: ct_req('mode') ?: ct_req('booking_type');

  $people   = ct_req('ct_people')   ?: ct_req('people');
  $adults   = ct_req('ct_adults')   ?: ct_req('adults');
  $children = ct_req('ct_children') ?: ct_req('children');

  $extras = [];
  if (!empty($_REQUEST['ct_extra']) && is_array($_REQUEST['ct_extra'])) {
    foreach ((array) $_REQUEST['ct_extra'] as $row) {
      if (!empty($row['label'])) {
        $extras[] = [
          'label' => sanitize_text_field(wp_unslash($row['label'])),
          'price' => isset($row['price']) ? floatval($row['price']) : 0,
        ];
      }
    }
  } elseif (!empty($_REQUEST['extras']) && is_array($_REQUEST['extras'])) {
    $labels = (array) $_REQUEST['extras'];
    $prices = !empty($_REQUEST['extras_price']) ? (array) $_REQUEST['extras_price'] : [];
    foreach ($labels as $i => $lbl) {
      $extras[] = [
        'label' => sanitize_text_field(wp_unslash($lbl)),
        'price' => isset($prices[$i]) ? floatval($prices[$i]) : 0,
      ];
    }
  } elseif ($json = ct_req('ct_extras_json')) {
    $dec = json_decode(stripslashes($json), true);
    if (is_array($dec)) {
      foreach ($dec as $row) {
        if (!empty($row['label'])) {
          $extras[] = [
            'label' => sanitize_text_field($row['label']),
            'price' => isset($row['price']) ? floatval($row['price']) : 0,
          ];
        }
      }
    }
  }

  if ($date)     $cart_item_data['date']     = $date;
  if ($slot_id)  {
    $cart_item_data['slot_id']  = $slot_id;
    // Also store human-friendly label and price for reliability
    global $wpdb;
    $table = $wpdb->prefix . 'turio_timeslots';
    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT `time`, `duration`, `price` FROM `{$table}` WHERE `id` = %d", intval($slot_id)),
      ARRAY_A
    );
    if ($row && !empty($row['time']) && is_numeric($row['duration'])) {
      list($sh, $sm) = array_map('intval', explode(':', $row['time']));
      $endMin = ($sh * 60 + $sm + intval($row['duration'])) % (24 * 60);
      $eh = floor($endMin / 60);
      $em = $endMin % 60;
      $cart_item_data['slot_label'] = $row['time'] . ' – ' . sprintf('%02d:%02d', $eh, $em);
      // Store slot price for price calculation
      if (isset($row['price'])) {
        $cart_item_data['slot_price'] = floatval($row['price']);
      }
    }
  }
  if ($mode)     $cart_item_data['mode']     = strtolower($mode);

  if ($people   !== '') $cart_item_data['people']   = max(0, intval($people));
  if ($adults   !== '') $cart_item_data['adults']   = max(0, intval($adults));
  if ($children !== '') $cart_item_data['children'] = max(0, intval($children));

  if (!empty($extras))  $cart_item_data['extras']   = array_values($extras);

  // Store tour ID if available (for featured image lookup)
  $tour_id = null;
  if (!empty($_REQUEST['tour_id'])) {
    $tour_id = intval($_REQUEST['tour_id']);
  } else {
    // Try to find tour by product ID
    $tours = get_posts(array(
      'post_type' => 'turio-package',
      'meta_query' => array(
        array(
          'key' => 'turio_product',
          'value' => $product_id,
          'compare' => '='
        )
      ),
      'posts_per_page' => 1,
      'fields' => 'ids'
    ));
    if (!empty($tours)) {
      $tour_id = $tours[0];
    }
  }
  if ($tour_id) {
    $cart_item_data['tour_id'] = $tour_id;
  }

  $cart_item_data['unique_key'] = md5(json_encode($cart_item_data) . microtime(true));
  return $cart_item_data;
}, 20, 3);

/* 2) Add slot price, people multiplier, and extras cost to price */
add_action('woocommerce_before_calculate_totals', function ($cart) {
  if (is_admin() && !defined('DOING_AJAX')) return;
  if (!$cart instanceof WC_Cart) return;
  
  foreach ($cart->get_cart() as $cart_item_key => $item) {
    if (empty($item['data']) || !is_object($item['data']) || !method_exists($item['data'], 'get_price')) continue;
    
    // Get base product price
    $base_price = floatval($item['data']->get_price());
    
    // Get slot price from cart item data (stored when adding to cart)
    $slot_price = isset($item['slot_price']) ? floatval($item['slot_price']) : 0;
    
    // Use slot price if available, otherwise use base price
    $price = $slot_price > 0 ? $slot_price : $base_price;
    
    // Add extras cost FIRST (before multiplying by people)
    $extra_total = 0;
    
    // Try multiple ways to access extras (WooCommerce might store it differently)
    $extras = [];
    if (!empty($item['extras']) && is_array($item['extras'])) {
      $extras = $item['extras'];
    } elseif (!empty($item['custom_data']['extras']) && is_array($item['custom_data']['extras'])) {
      $extras = $item['custom_data']['extras'];
    }
    
    if (!empty($extras)) {
      foreach ($extras as $extra) {
        if (is_array($extra) && isset($extra['price'])) {
          $extra_total += floatval($extra['price']);
        } elseif (is_numeric($extra)) {
          $extra_total += floatval($extra);
        }
      }
    }
    
    // Add extras to base price
    $total = $price + $extra_total;
    
    // Apply people multiplier for private tours (multiply the total: slot + extras)
    $people = isset($item['people']) ? max(1, intval($item['people'])) : 1;
    $mode = isset($item['mode']) ? strtolower($item['mode']) : 'private';
    
    if ($mode === 'private' && $people > 0) {
      $total = $total * $people;
    }
    
    // Set final price
    $item['data']->set_price($total);
  }
}, 20);

/* 3) Add featured image to cart items */
add_filter('woocommerce_cart_item_thumbnail', function($image, $cart_item, $cart_item_key) {
  // Get product ID
  $product_id = $cart_item['product_id'];
  
  // Try to get tour ID from cart item data
  $tour_id = null;
  if (!empty($cart_item['tour_id'])) {
    $tour_id = intval($cart_item['tour_id']);
  } elseif (!empty($cart_item['custom_data']['tour_id'])) {
    $tour_id = intval($cart_item['custom_data']['tour_id']);
  }
  
  // Get featured image from tour or product
  if ($tour_id && has_post_thumbnail($tour_id)) {
    $image = get_the_post_thumbnail($tour_id, 'woocommerce_thumbnail', array('class' => 'ct-cart-item-image'));
  } elseif (has_post_thumbnail($product_id)) {
    $image = get_the_post_thumbnail($product_id, 'woocommerce_thumbnail', array('class' => 'ct-cart-item-image'));
  }
  
  return $image;
}, 10, 3);

/* 4) Prevent default display for booking items - we'll use custom format instead */
add_filter('woocommerce_get_item_data', function ($item_data, $cart_item) {
  // Return empty array for booking items to prevent default display
  // We'll use action hook for custom formatting
  if (!empty($cart_item['date']) || !empty($cart_item['slot_id'])) {
    return array();
  }
  return $item_data;
}, 5, 2);

/* 4.1) Also filter the formatted output to ensure nothing shows */
add_filter('woocommerce_cart_item_data_output', function($output, $cart_item) {
  // Return empty for booking items since we use action hook
  if (!empty($cart_item['date']) || !empty($cart_item['slot_id'])) {
    return '';
  }
  return $output;
}, 10, 2);

/* 4.5) Customize cart item data display format - output after cart item name */
add_action('woocommerce_after_cart_item_name', function($cart_item, $cart_item_key) {
  // Only customize for cart items with our booking data
  if (empty($cart_item['date']) && empty($cart_item['slot_id'])) {
    return;
  }
  
  $html_parts = array();
  
  // Format date
  if (!empty($cart_item['date'])) {
    $date_obj = DateTime::createFromFormat('Y-m-d', $cart_item['date']);
    $formatted_date = $date_obj ? $date_obj->format('F j, Y') : $cart_item['date'];
    $html_parts[] = '<div class="ct-cart-meta-item"><span class="ct-cart-meta-label">📅 ' . __('Booking Date', 'comotour') . ':</span> <strong>' . esc_html($formatted_date) . '</strong></div>';
  }
  
  // Format time slot
  if (!empty($cart_item['slot_id'])) {
    $slotLabel = !empty($cart_item['slot_label']) ? esc_html($cart_item['slot_label']) : esc_html($cart_item['slot_id']);
    $html_parts[] = '<div class="ct-cart-meta-item"><span class="ct-cart-meta-label">🕐 ' . __('Time Slot', 'comotour') . ':</span> <strong>' . $slotLabel . '</strong></div>';
  }
  
  // Format booking type
  if (!empty($cart_item['mode'])) {
    $mode_label = ucfirst(esc_html($cart_item['mode']));
    $mode_icon = $cart_item['mode'] === 'private' ? '👥' : '🎫';
    $html_parts[] = '<div class="ct-cart-meta-item"><span class="ct-cart-meta-label">' . $mode_icon . ' ' . __('Booking Type', 'comotour') . ':</span> <strong>' . $mode_label . '</strong></div>';
  }
  
  // Format people count
  if (isset($cart_item['people']) && $cart_item['people'] > 0) {
    $html_parts[] = '<div class="ct-cart-meta-item"><span class="ct-cart-meta-label">👤 ' . __('Number of People', 'comotour') . ':</span> <strong>' . intval($cart_item['people']) . '</strong></div>';
  }
  
  // Format extras
  if (!empty($cart_item['extras']) && is_array($cart_item['extras'])) {
    $extras_html = '<div class="ct-cart-meta-section"><strong class="ct-cart-extras-title">✨ ' . __('Selected Extras', 'comotour') . '</strong><div class="ct-cart-extras">';
    foreach ($cart_item['extras'] as $extra) {
      $label = esc_html($extra['label']);
      $price = wc_price(floatval($extra['price']), array('currency' => 'EUR'));
      $extras_html .= '<div class="ct-cart-extra-item">';
      $extras_html .= '<span class="ct-cart-extra-label">✨ ' . $label . '</span>';
      $extras_html .= '<span class="ct-cart-extra-price">+ ' . $price . '</span>';
      $extras_html .= '</div>';
    }
    $extras_html .= '</div></div>';
    $html_parts[] = $extras_html;
  }
  
  if (!empty($html_parts)) {
    echo '<div class="ct-cart-booking-details">' . implode('', $html_parts) . '</div>';
  }
}, 10, 2);

/* 5) Persist on Order */
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values) {
  if (!empty($values['date']))     $item->add_meta_data('Date', $values['date']);
  if (!empty($values['slot_id']))  {
    // Save human-friendly time range if possible
    $toSave = !empty($values['slot_label']) ? $values['slot_label'] : $values['slot_id'];
    global $wpdb;
    $table = $wpdb->prefix . 'turio_timeslots';
    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT `time`, `duration` FROM `{$table}` WHERE `id` = %d", intval($values['slot_id'])),
      ARRAY_A
    );
    if ($row && !empty($row['time']) && is_numeric($row['duration'])) {
      list($sh, $sm) = array_map('intval', explode(':', $row['time']));
      $endMin = ($sh * 60 + $sm + intval($row['duration'])) % (24 * 60);
      $eh = floor($endMin / 60);
      $em = $endMin % 60;
      $end = sprintf('%02d:%02d', $eh, $em);
      $toSave = $row['time'] . ' – ' . $end;
    }
    $item->add_meta_data('Time Slot', $toSave);
  }
  if (!empty($values['mode']))     $item->add_meta_data('Booking Type', ucfirst($values['mode']));
  if (isset($values['adults']))    $item->add_meta_data('Adults',   intval($values['adults']));
  if (isset($values['children']))  $item->add_meta_data('Children', intval($values['children']));
  if (isset($values['people']))    $item->add_meta_data('People',   intval($values['people']));
  if (!empty($values['extras']) && is_array($values['extras'])) {
    foreach ($values['extras'] as $extra) {
      $label = sanitize_text_field($extra['label']);
      $price = wc_price(floatval($extra['price']), array('currency' => 'EUR'));
      $item->add_meta_data('Extra', "{$label} - {$price}");
    }
  }
}, 10, 3);

/* 5) Redirect to Cart */
add_filter('woocommerce_add_to_cart_redirect', function ($url) {
  return function_exists('wc_get_cart_url') ? wc_get_cart_url() : $url;
});

/* 6) Move payment section into order review area */
// Remove payment from default location
remove_action('woocommerce_checkout_after_order_review', 'woocommerce_checkout_payment', 20);

// Add payment section inside order review
add_action('woocommerce_checkout_order_review', function() {
  if (WC()->cart && !WC()->cart->is_empty()) {
    echo '<div id="payment-wrapper">';
    do_action('woocommerce_checkout_payment');
    echo '</div>';
  }
}, 25);

/* 7) Enhance additional information field label */
add_filter('woocommerce_form_field', function($field, $key, $args, $value) {
  if ($key === 'order_comments') {
    // Add custom styling class
    $field = str_replace('class="', 'class="ct-order-notes-field ', $field);
  }
  return $field;
}, 10, 4);

/* 8) Customize "Return to shop" button */
add_filter('woocommerce_return_to_shop_text', function ($text) {
  return __('Back to Experiences', 'comotour');
});

add_filter('woocommerce_return_to_shop_redirect', function ($url) {
  $experiences_url = '';

  if (post_type_exists('turio-package')) {
    $archive_url = get_post_type_archive_link('turio-package');
    if ($archive_url) {
      $experiences_url = $archive_url;
    }
  }

  if (!$experiences_url) {
    $experiences_page = get_page_by_path('experiences');
    if ($experiences_page) {
      $experiences_url = get_permalink($experiences_page);
    }
  }

  if (!$experiences_url) {
    $page_id = wc_get_page_id('shop');
    if ($page_id > 0) {
      $experiences_url = get_permalink($page_id);
    } else {
      $experiences_url = wc_get_page_permalink('shop');
    }
  }

  if (!$experiences_url) {
    $experiences_url = home_url('/');
  }

  return $experiences_url;
}, 10, 1);

/* 7) Hide coupon form on cart page */
add_filter('woocommerce_coupons_enabled', function ($enabled) {
  if (function_exists('is_cart') && is_cart()) {
    return false;
  }
  return $enabled;
});
