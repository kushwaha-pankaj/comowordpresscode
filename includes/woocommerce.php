<?php
if (!defined('ABSPATH')) exit;

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
  if ($slot_id)  $cart_item_data['slot_id']  = $slot_id;
  if ($mode)     $cart_item_data['mode']     = strtolower($mode);

  if ($people   !== '') $cart_item_data['people']   = max(0, intval($people));
  if ($adults   !== '') $cart_item_data['adults']   = max(0, intval($adults));
  if ($children !== '') $cart_item_data['children'] = max(0, intval($children));

  if (!empty($extras))  $cart_item_data['extras']   = array_values($extras);

  $cart_item_data['unique_key'] = md5(json_encode($cart_item_data) . microtime(true));
  return $cart_item_data;
}, 20, 3);

/* 2) Add extras cost to price */
add_action('woocommerce_before_calculate_totals', function ($cart) {
  if (is_admin() && !defined('DOING_AJAX')) return;
  if (!$cart instanceof WC_Cart) return;
  foreach ($cart->get_cart() as $k => $item) {
    if (empty($item['data']) || !is_object($item['data']) || !method_exists($item['data'], 'get_price')) continue;
    $base = floatval($item['data']->get_price());
    $extra_total = 0;
    if (!empty($item['extras']) && is_array($item['extras'])) {
      foreach ($item['extras'] as $x) $extra_total += floatval($x['price'] ?? 0);
    }
    $item['data']->set_price($base + $extra_total);
  }
}, 20);

/* 3) Show data in Cart/Checkout */
add_filter('woocommerce_get_item_data', function ($item_data, $cart_item) {
  if (!empty($cart_item['date']))     $item_data[] = ['name'=>__('Date','comotour'),         'value'=>esc_html($cart_item['date'])];
  if (!empty($cart_item['slot_id']))  $item_data[] = ['name'=>__('Time Slot','comotour'),    'value'=>esc_html($cart_item['slot_id'])];
  if (!empty($cart_item['mode']))     $item_data[] = ['name'=>__('Booking Type','comotour'), 'value'=>ucfirst(esc_html($cart_item['mode']))];
  if (isset($cart_item['adults']))    $item_data[] = ['name'=>__('Adults','comotour'),       'value'=>intval($cart_item['adults'])];
  if (isset($cart_item['children']))  $item_data[] = ['name'=>__('Children','comotour'),     'value'=>intval($cart_item['children'])];
  if (isset($cart_item['people']))    $item_data[] = ['name'=>__('People','comotour'),       'value'=>intval($cart_item['people'])];

  if (!empty($cart_item['extras']) && is_array($cart_item['extras'])) {
    foreach ($cart_item['extras'] as $extra) {
      $label = esc_html($extra['label']);
      $price = wc_price(floatval($extra['price']));
      $item_data[] = ['name'=>sprintf(__('Extra: %s','comotour'), $label),'value'=>'+ '.$price];
    }
  }
  return $item_data;
}, 10, 2);

/* 4) Persist on Order */
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values) {
  if (!empty($values['date']))     $item->add_meta_data('Date', $values['date']);
  if (!empty($values['slot_id']))  $item->add_meta_data('Time Slot', $values['slot_id']);
  if (!empty($values['mode']))     $item->add_meta_data('Booking Type', ucfirst($values['mode']));
  if (isset($values['adults']))    $item->add_meta_data('Adults',   intval($values['adults']));
  if (isset($values['children']))  $item->add_meta_data('Children', intval($values['children']));
  if (isset($values['people']))    $item->add_meta_data('People',   intval($values['people']));
  if (!empty($values['extras']) && is_array($values['extras'])) {
    foreach ($values['extras'] as $extra) {
      $label = sanitize_text_field($extra['label']);
      $price = wc_price(floatval($extra['price']));
      $item->add_meta_data('Extra', "{$label} - {$price}");
    }
  }
}, 10, 3);

/* 5) Redirect to Cart */
add_filter('woocommerce_add_to_cart_redirect', function ($url) {
  return function_exists('wc_get_cart_url') ? wc_get_cart_url() : $url;
});
