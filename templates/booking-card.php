<?php
if (!defined('ABSPATH')) exit;

$post_id = get_the_ID();

// Get product ID - try multiple meta keys
$tour_product = get_post_meta($post_id, 'turio_product', true) 
    ?: get_post_meta($post_id, '_turio_product', true) 
    ?: get_post_meta($post_id, '_product_id', true);

// Get pricing from product
$regular = 0;
$sale = 0;
if (class_exists('WooCommerce') && !empty($tour_product)) {
    $product = wc_get_product($tour_product);
    if ($product) {
        $regular = (float)$product->get_regular_price();
        $sale = (float)$product->get_sale_price();
    }
}

$max_people = (int)get_post_meta($post_id, '_ct_max_people', true) ?: 10;
$mode = get_post_meta($post_id, '_ct_mode', true) ?: 'private';

// Load extras/services from backend
$extras = array();

// Step 1: Try to get extras from post meta (added via admin)
$extras_meta = get_post_meta($post_id, '_ct_product_extras', true);
if (!empty($extras_meta) && is_array($extras_meta)) {
    $extras = $extras_meta;
}

// Step 2: If no extras in meta, try product meta
if (empty($extras) && !empty($tour_product)) {
    $product_extras = get_post_meta($tour_product, '_product_extras', true);
    if (!empty($product_extras) && is_array($product_extras)) {
        $extras = $product_extras;
    }
    
    // Step 3: Check for WooCommerce Add-Ons data
    if (empty($extras)) {
        $addons = get_post_meta($tour_product, '_product_addons', true);
        if (!empty($addons) && is_array($addons)) {
            foreach ($addons as $addon) {
                if (isset($addon['name']) && isset($addon['options']) && is_array($addon['options'])) {
                    foreach ($addon['options'] as $option) {
                        if (isset($option['label']) && isset($option['price'])) {
                            $extras[] = array(
                                'id' => sanitize_title($option['label']),
                                'title' => esc_html($option['label']),
                                'price' => floatval($option['price'])
                            );
                        }
                    }
                }
            }
        }
    }
}

// Get extras from Turio meta bundle
if (empty($extras)) {
    $meta = get_post_meta($post_id, 'turio_turio_package_info_options', true);
    $product_id = isset($meta['tour_product']) ? $meta['tour_product'] : '';
    
    if ($product_id) {
        $turio_meta = get_post_meta($product_id, 'turio-meta-woocommerce', true);
        if (!empty($turio_meta['turio_woocommerce_services'])) {
            foreach ($turio_meta['turio_woocommerce_services'] as $srv) {
                $label = isset($srv['turio_woocommerce_services_label']) ? $srv['turio_woocommerce_services_label'] : '';
                $price = floatval(isset($srv['turio_woocommerce_services_price']) ? $srv['turio_woocommerce_services_price'] : 0);
                if ($label !== '') {
                    $extras[] = array(
                        'id' => sanitize_title($label),
                        'title' => esc_html($label),
                        'price' => $price
                    );
                }
            }
        }
    }
}

// Sanitize and validate all extras data
$extras = array_filter(array_map(function($e) {
    if (!is_array($e)) return null;
    
    $id = isset($e['id']) ? sanitize_key($e['id']) : (isset($e['title']) ? sanitize_title($e['title']) : null);
    $title = isset($e['title']) ? sanitize_text_field($e['title']) : null;
    $price = isset($e['price']) ? floatval($e['price']) : null;
    
    if (empty($id) || empty($title) || $price === null) {
        return null;
    }
    
    return array(
        'id' => $id,
        'title' => $title,
        'price' => $price
    );
}, $extras));

// Remove duplicates
$extras = array_values(array_unique($extras, SORT_REGULAR));

// Debug: Log loaded extras
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Loaded extras for post ' . $post_id . ': ' . json_encode($extras));
}
?>

<div id="ct-booking-card"
     data-post-id="<?php echo esc_attr($post_id); ?>"
     data-product-id="<?php echo esc_attr($tour_product); ?>"
     data-mode="<?php echo esc_attr($mode); ?>"
     data-regular-price="<?php echo esc_attr(number_format($regular, 2, '.', '')); ?>"
     data-sale-price="<?php echo esc_attr(number_format($sale, 2, '.', '')); ?>"
     data-max-people="<?php echo esc_attr($max_people); ?>">
  <div class="ct-inner-card">
    <!-- Date Section -->
    <div class="ct-section">
      <div class="ct-section-title">Select Your Date</div>
      <div id="ct_date_inline"></div>
      <div id="ct_selected_text">
        Selected: <span id="ct_selected_iso">—</span>
        <a href="#" id="ct_clear_selected">Clear</a>
      </div>
    </div>

    <!-- Time Section -->
    <div class="ct-section">
      <div class="ct-section-title">Select Time</div>
      <div id="ct_slots_list">
        <div class="ct-slot-hint">Select a date to view available times</div>
      </div>
    </div>

    <div class="ct-divider"></div>

    <!-- People Section -->
    <div class="ct-section">
      <div class="ct-section-title">Details</div>
      <div class="ct-people-wrap">
        <div class="ct-people-title">Number of People</div>
        <div class="people-controls">
          <button type="button" class="ct-step" id="ct_people_minus">−</button>
          <input id="ct_people" type="text" value="1" readonly>
          <button type="button" class="ct-step" id="ct_people_plus">+</button>
        </div>
        <div class="ct-people-max">Bookings available: <strong id="ct_max_display"><?php echo esc_html($max_people); ?></strong></div>
      </div>
    </div>

    <div class="ct-divider"></div>

    <!-- Extras Section -->
    <h3 class="ct-section-title">Add Extras</h3>
    <div id="ct_extras">
      <?php if (!empty($extras)) : ?>
        <?php foreach ($extras as $extra) : ?>
          <label class="ct-extra-row">
            <input type="checkbox"
                   class="ct-extra-checkbox"
                   data-id="<?php echo esc_attr($extra['id']); ?>"
                   data-price="<?php echo esc_attr($extra['price']); ?>">
            <span class="ct-extra-title"><?php echo esc_html($extra['title']); ?></span>
            <span class="ct-extra-price"><?php echo wc_price($extra['price']); ?></span>
          </label>
        <?php endforeach; ?>
      <?php else : ?>
        <p class="ct-slot-hint">No extras available for this tour.</p>
      <?php endif; ?>
    </div>

    <!-- Total -->
    <div class="ct-total-row">
      <div class="label">Total</div>
      <div id="ct_total_price">$0.00</div>
    </div>

    <!-- BOOK NOW BUTTON REMOVED -->
  </div>
</div>