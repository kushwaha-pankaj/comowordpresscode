<?php
/**
 * Plugin Name: Turio Timeslots (ComoTour)
 * Description: Manage per-date timeslots (private/shared) for tour posts with services/extras
 * Version: 2.1.0-FIXED
 * Author: ComoTour
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/rest.php';

final class CT_Turio_Timeslots {
  private function db_table() {
    global $wpdb;
    return $wpdb->prefix . 'turio_timeslots';
  }
  
  private function db() { 
    global $wpdb; 
    return $wpdb; 
  }

  public function __construct() {
    register_activation_hook(__FILE__, [$this, 'activate']);
    add_action('add_meta_boxes', [$this, 'add_metabox_generic'], 10, 2);
    add_action('save_post', [$this, 'save_meta'], 10, 1);
    add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
    add_action('wp_ajax_ct_admin_get_slots_by_date', [$this, 'ajax_admin_get_slots_by_date']);
    add_action('wp_ajax_ct_admin_add_slot', [$this, 'ajax_admin_add_slot']);
    add_action('wp_ajax_ct_admin_delete_slot', [$this, 'ajax_admin_delete_slot']);
  }

  public function activate() {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $t = $this->db_table();
    $charset = $this->db()->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS `{$t}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `tour_id` BIGINT UNSIGNED NOT NULL,
      `date` DATE NOT NULL,
      `time` CHAR(5) NOT NULL,
      `duration` SMALLINT UNSIGNED NOT NULL,
      `capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
      `booked` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `mode` VARCHAR(10) NOT NULL DEFAULT 'private',
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_slot` (`tour_id`, `date`, `time`, `duration`)
    ) {$charset};";

    dbDelta($sql);
    $this->maybe_add_mode_column();
  }

  private function maybe_add_mode_column() {
    $t = $this->db_table();
    $col = $this->db()->get_var($this->db()->prepare("SHOW COLUMNS FROM `{$t}` LIKE %s", 'mode'));
    if (!$col) {
      $this->db()->query("ALTER TABLE `{$t}` ADD COLUMN `mode` VARCHAR(10) NOT NULL DEFAULT 'private'");
    }
  }

  public function add_metabox_generic($post_type, $post) {
    $allowed = apply_filters('ct_ts_post_types', ['turio-package']);
    if (!in_array($post_type, $allowed, true)) return;

    add_meta_box(
      'ct_timeslots_box',
      'ComoTour – Booking Setup (Private / Shared)',
      [$this, 'box_html'],
      $post_type,
      'normal',
      'high'
    );
  }

  public function box_html($post) {
    $mode = get_post_meta($post->ID, '_ct_mode', true) ?: 'private';
    $date_from = get_post_meta($post->ID, '_ct_date_from', true) ?: '';
    $date_to = get_post_meta($post->ID, '_ct_date_to', true) ?: '';
    $max_people = (int)(get_post_meta($post->ID, '_ct_max_people', true) ?: 8);
    $extras = get_post_meta($post->ID, '_ct_product_extras', true) ?: [];

    wp_nonce_field('ct_ts_save_meta', 'ct_ts_nonce');

    echo '<div class="ct-grid">';
    echo '<p><label><strong>Product Type</strong><br>';
    echo '<select name="ct_mode" id="ct_mode">';
    echo '<option value="private" '.selected($mode,'private',false).'>Private (Tour)</option>';
    echo '<option value="shared"  '.selected($mode,'shared',false).'>Shared (Per-Seat)</option>';
    echo '</select></label></p>';

    echo '<p><label><strong>Date Range (From – To)</strong></label><br>';
    echo '<input type="text" class="ct-date" name="ct_date_from" id="ct_date_from" value="'.esc_attr($date_from).'" placeholder="YYYY-MM-DD"> ';
    echo '<input type="text" class="ct-date" name="ct_date_to" id="ct_date_to" value="'.esc_attr($date_to).'" placeholder="YYYY-MM-DD">';
    echo '</p>';

    echo '<p><label><strong>Select Specific Date for Time Slots</strong></label><br>';
    echo '<input type="text" class="ct-date" name="ct_specific_date" id="ct_specific_date" value="" placeholder="YYYY-MM-DD" autocomplete="off">';
    echo '</p>';

    echo '<p><label><strong>Max number of people</strong><br>';
    echo '<input type="number" min="1" name="ct_max_people" id="ct_max_people" value="'.esc_attr($max_people).'">';
    echo '</label></p>';

    // FIX: Add extras/services management - IMPROVED
    echo '<hr style="margin: 20px 0;">';
    echo '<p><label><strong>Add Extras / Services</strong></label></p>';
    echo '<div id="ct_extras_container" style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 15px;">';
    
    if (!empty($extras) && is_array($extras)) {
        foreach ($extras as $idx => $extra) {
            echo '<div class="ct-extra-input" style="display: grid; grid-template-columns: 1fr 120px auto; gap: 10px; margin-bottom: 10px; align-items: flex-end; padding: 10px; background: white; border-radius: 3px; border-left: 3px solid #1caf5f;">';
            echo '<input type="text" name="ct_extras_title[]" value="'.esc_attr($extra['title']).'" placeholder="Service name (e.g., Travel Insurance)" style="padding: 8px; border: 1px solid #ddd; border-radius: 3px;">';
            echo '<input type="number" step="0.01" name="ct_extras_price[]" value="'.esc_attr($extra['price']).'" placeholder="Price" style="padding: 8px; border: 1px solid #ddd; border-radius: 3px;">';
            echo '<button type="button" class="button button-secondary ct-remove-extra" style="padding: 8px 15px;">Remove</button>';
            echo '</div>';
        }
    }
    echo '</div>';
    
    echo '<button type="button" class="button button-primary" id="ct-add-extra" style="margin-bottom: 20px;">+ Add Extra</button>';

    echo '</div><hr class="ct-hr"/>';

    echo '<div id="ct_private_box" class="'.($mode==='shared'?'ct-hide':'').'">';
    echo '<h3>Private – Time Slots & Pricing</h3>';
    echo '<div class="ct-row">';
    echo '<input type="text" id="ct_p_start" class="ct-time" placeholder="Start (07:00)">';
    echo '<input type="text" id="ct_p_end" class="ct-time" placeholder="End (09:00)">';
    echo '<input type="number" step="0.01" id="ct_p_price" placeholder="Price €">';
    echo '<input type="number" step="0.01" id="ct_p_promo" placeholder="Promo € (optional)">';
    echo '<input type="number" step="1" id="ct_p_disc" placeholder="Discount % (optional)">';
    echo '</div>';
    echo '<p><button class="button button-primary" id="ct-add-p-slot">+ Add Private Time Slot</button></p>';
    echo '</div>';

    echo '<div id="ct_shared_box" class="'.($mode==='private'?'ct-hide':'').'">';
    echo '<h3>Shared – Time Slots & Pricing</h3>';
    echo '<div class="ct-row">';
    echo '<input type="text" id="ct_s_start" class="ct-time" placeholder="Start (10:00)">';
    echo '<input type="text" id="ct_s_end" class="ct-time" placeholder="End (11:00)">';
    echo '<input type="number" min="1" id="ct_s_capacity" placeholder="Capacity">';
    echo '<input type="number" step="0.01" id="ct_s_price" placeholder="Price per person €">';
    echo '</div>';
    echo '<p><button class="button" id="ct-add-s-slot">+ Add Shared Time Slot</button></p>';
    echo '</div>';

    echo '<hr class="ct-hr"/>';
    echo '<h3>Time Slots for <span id="ct_date_label">[pick a date]</span></h3>';
    echo '<table class="widefat fixed striped" id="ct_slots_table">';
    echo '<thead><tr><th>Date</th><th>Type</th><th>Start</th><th>End</th><th>Duration</th><th>Capacity</th><th>Price (€)</th><th>Booked</th><th>Actions</th></tr></thead>';
    echo '<tbody><tr><td colspan="9">Pick a date to load time slots…</td></tr></tbody>';
    echo '</table>';
  }

  public function save_meta($post_id) {
    // Prevent infinite loops
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check nonce
    if (!isset($_POST['ct_ts_nonce'])) {
        return;
    }

    if (!wp_verify_nonce($_POST['ct_ts_nonce'], 'ct_ts_save_meta')) {
        return;
    }

    // Check capability
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Get post type
    $post_type = get_post_type($post_id);
    $allowed = apply_filters('ct_ts_post_types', ['turio-package']);
    if (!in_array($post_type, $allowed)) {
        return;
    }

    // Save basic settings
    if (isset($_POST['ct_mode'])) {
        update_post_meta($post_id, '_ct_mode', sanitize_text_field($_POST['ct_mode']));
    }
    if (isset($_POST['ct_date_from'])) {
        update_post_meta($post_id, '_ct_date_from', sanitize_text_field($_POST['ct_date_from']));
    }
    if (isset($_POST['ct_date_to'])) {
        update_post_meta($post_id, '_ct_date_to', sanitize_text_field($_POST['ct_date_to']));
    }
    if (isset($_POST['ct_max_people'])) {
        update_post_meta($post_id, '_ct_max_people', absint($_POST['ct_max_people']));
    }

    // FIX: Save extras/services - MORE ROBUST
    $extras = [];
    if (isset($_POST['ct_extras_title']) && is_array($_POST['ct_extras_title'])) {
        $titles = $_POST['ct_extras_title'];
        $prices = isset($_POST['ct_extras_price']) ? $_POST['ct_extras_price'] : [];
        
        foreach ($titles as $idx => $title) {
            $title = sanitize_text_field($title);
            $price = isset($prices[$idx]) ? floatval($prices[$idx]) : 0;
            
            if (!empty($title) && $price > 0) {
                $extras[] = [
                    'id' => sanitize_key(strtolower($title)),
                    'title' => $title,
                    'price' => $price
                ];
            }
        }
    }

    // Save extras
    if (!empty($extras)) {
        update_post_meta($post_id, '_ct_product_extras', $extras);
        error_log('Saved ' . count($extras) . ' extras for post ' . $post_id);
    } else {
        delete_post_meta($post_id, '_ct_product_extras');
    }
  }

  public function admin_assets($hook) {
    if ($hook !== 'post.php' && $hook !== 'post-new.php') return;

    $css_path = plugin_dir_path(__FILE__) . 'css/admin.css';
    if (file_exists($css_path)) {
      wp_enqueue_style('ct-ts-admin', plugins_url('css/admin.css', __FILE__), [], filemtime($css_path));
    }

    wp_enqueue_script('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', [], '4.6.13', true);

    $js_path = plugin_dir_path(__FILE__) . 'js/admin.js';
    $ver = file_exists($js_path) ? filemtime($js_path) : time();
    wp_enqueue_script('ct-ts-admin', plugins_url('js/admin.js', __FILE__), ['jquery','flatpickr'], $ver, true);

    $post_id = 0;
    if (isset($_GET['post'])) {
      $post_id = intval($_GET['post']);
    } elseif (isset($_REQUEST['post_ID'])) {
      $post_id = intval($_REQUEST['post_ID']);
    }

    $max_people = $post_id ? intval(get_post_meta($post_id, '_ct_max_people', true) ?: 0) : 0;

    wp_localize_script('ct-ts-admin', 'CT_TS_ADMIN', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('ct_ts_admin_nonce'),
      'postId' => $post_id,
      'maxPeople' => $max_people,
    ]);

    // Inline script for extras management
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      const addExtraBtn = document.getElementById('ct-add-extra');
      const extrasContainer = document.getElementById('ct_extras_container');
      
      function attachRemoveHandler(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          this.closest('.ct-extra-input').remove();
        });
      }

      if (addExtraBtn && extrasContainer) {
        addExtraBtn.addEventListener('click', function(e) {
          e.preventDefault();
          const div = document.createElement('div');
          div.className = 'ct-extra-input';
          div.style.cssText = 'display: grid; grid-template-columns: 1fr 120px auto; gap: 10px; margin-bottom: 10px; align-items: flex-end; padding: 10px; background: white; border-radius: 3px; border-left: 3px solid #1caf5f;';
          div.innerHTML = `
            <input type="text" name="ct_extras_title[]" placeholder="Service name (e.g., Travel Insurance)" style="padding: 8px; border: 1px solid #ddd; border-radius: 3px;">
            <input type="number" step="0.01" name="ct_extras_price[]" placeholder="Price" style="padding: 8px; border: 1px solid #ddd; border-radius: 3px;">
            <button type="button" class="button button-secondary ct-remove-extra" style="padding: 8px 15px;">Remove</button>
          `;
          extrasContainer.appendChild(div);
          
          // Attach handler to new remove button
          const removeBtn = div.querySelector('.ct-remove-extra');
          attachRemoveHandler(removeBtn);
        });
      }
      
      // Handle existing remove buttons
      document.querySelectorAll('.ct-remove-extra').forEach(btn => {
        attachRemoveHandler(btn);
      });
    });
    </script>
    <?php
  }

  private function norm_date($d) {
    if (!is_string($d) || empty($d)) {
        return '';
    }
    
    try {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
    } catch (Exception $e) {
        return '';
    }
  }

  private function norm_time($t) {
    if (!preg_match('/^\d{2}:\d{2}$/', $t)) return '';
    [$h,$m] = array_map('intval', explode(':',$t));
    if ($h<0||$h>23||$m<0||$m>59) return '';
    return sprintf('%02d:%02d', $h, $m);
  }

  private function ensure_table_exists() {
    $check = $this->db()->get_var($this->db()->prepare("SHOW TABLES LIKE %s", $this->db_table()));
    if (!$check) {
      $this->activate();
      $check = $this->db()->get_var($this->db()->prepare("SHOW TABLES LIKE %s", $this->db_table()));
    }
    if ($check) $this->maybe_add_mode_column();
    return (bool)$check;
  }

  public function ajax_admin_get_slots_by_date() {
    check_ajax_referer('ct_ts_admin_nonce', 'nonce');

    $post_id = absint($_POST['post_id'] ?? 0);
    $date = $this->norm_date(sanitize_text_field($_POST['date'] ?? ''));

    if (!$post_id || !current_user_can('edit_post', $post_id)) wp_send_json_error(['msg'=>'No permission.']);
    if (!$date) wp_send_json_error(['msg'=>'Invalid date.']);
    if (!$this->ensure_table_exists()) wp_send_json_error(['msg'=>'DB table missing.']);

    $rows = $this->db()->get_results(
      $this->db()->prepare(
        "SELECT `id`, `time`, `duration`, `capacity`, `price`, `booked`, `mode`
         FROM `{$this->db_table()}`
         WHERE `tour_id`=%d AND `date`=%s
         ORDER BY `time` ASC",
        $post_id, $date
      ),
      ARRAY_A
    );

    $slots = [];
    foreach ($rows as $r) {
      $start = $r['time'];
      $duration = intval($r['duration']);
      [$sh,$sm] = array_map('intval', explode(':', $start));
      $end_minutes = ($sh * 60 + $sm + $duration);
      
      if ($end_minutes >= 24 * 60) {
        $end_minutes = $end_minutes % (24*60);
      }
      
      $eh = floor($end_minutes / 60);
      $em = $end_minutes % 60;
      $end = sprintf('%02d:%02d', $eh, $em);

      $slots[] = [
        'id' => intval($r['id']),
        'date' => $date,
        'mode' => $r['mode'] ?? 'private',
        'time' => $start,
        'end' => $end,
        'duration' => $duration,
        'capacity' => intval($r['capacity']),
        'price' => (float)$r['price'],
        'booked' => intval($r['booked']),
      ];
    }

    wp_send_json_success(['date' => $date, 'slots' => $slots]);
  }

  public function ajax_admin_add_slot() {
    check_ajax_referer('ct_ts_admin_nonce', 'nonce');

    $post_id = absint($_POST['post_id'] ?? 0);
    $date = $this->norm_date(sanitize_text_field($_POST['date'] ?? ''));
    $mode = sanitize_text_field($_POST['mode'] ?? 'private');
    $mode = ($mode === 'shared') ? 'shared' : 'private';

    $start = $this->norm_time(sanitize_text_field($_POST['start'] ?? ''));
    $end = $this->norm_time(sanitize_text_field($_POST['end'] ?? ''));

    $capacity = isset($_POST['capacity']) && is_numeric($_POST['capacity']) ? absint($_POST['capacity']) : null;
    $price = is_numeric($_POST['price'] ?? null) ? floatval($_POST['price']) : 0.0;
    $promo = is_numeric($_POST['promo'] ?? null) ? floatval($_POST['promo']) : 0.0;
    $disc = is_numeric($_POST['disc'] ?? null) ? floatval($_POST['disc']) : 0.0;

    if (!$post_id || !current_user_can('edit_post', $post_id)) wp_send_json_error(['msg'=>'No permission.']);
    if (!$date) wp_send_json_error(['msg'=>'Invalid date.']);
    if (!$start || !$end) wp_send_json_error(['msg'=>'Invalid start or end time.']);

    $provided_max = isset($_POST['post_max_people']) && is_numeric($_POST['post_max_people']) ? absint($_POST['post_max_people']) : 0;
    $meta_max = (int)(get_post_meta($post_id, '_ct_max_people', true) ?: 0);
    $max_people = ($provided_max > 0) ? $provided_max : $meta_max;

    if ($mode === 'private') {
      $capacity = ($max_people > 0) ? $max_people : 1;
    } else {
      if ($capacity === null || $capacity < 1) $capacity = 1;
      if ($max_people > 0 && $capacity > $max_people) {
        wp_send_json_error(['msg' => 'Capacity cannot exceed max people.']);
      }
    }

    [$sh,$sm] = array_map('intval', explode(':', $start));
    [$eh,$em] = array_map('intval', explode(':', $end));
    $dur = ($eh * 60 + $em) - ($sh * 60 + $sm);
    
    if ($dur <= 0) wp_send_json_error(['msg'=>'End must be after Start.']);
    if ($eh * 60 + $em >= 24 * 60) wp_send_json_error(['msg'=>'Slots cannot cross midnight.']);

    if ($promo > 0 && $disc > 0) {
        wp_send_json_error(['msg' => 'Cannot specify both promo price and discount.']);
    }

    $final = $price;
    if ($promo > 0) $final = $promo;
    elseif ($disc > 0) $final = max(0, $price * (1 - ($disc/100)));

    if (!$this->ensure_table_exists()) wp_send_json_error(['msg'=>'DB table missing.']);

    try {
      $inserted = $this->db()->insert(
        $this->db_table(),
        [
          'tour_id' => $post_id,
          'date' => $date,
          'time' => $start,
          'duration' => $dur,
          'capacity' => $capacity,
          'booked' => 0,
          'price' => floatval($final),
          'mode' => $mode
        ],
        ['%d','%s','%s','%d','%d','%d','%f','%s']
      );

      if ($inserted === false) {
        if ($this->db()->last_error && strpos($this->db()->last_error, 'Duplicate') !== false) {
          wp_send_json_error(['msg' => 'An identical time slot already exists.']);
        } else {
          wp_send_json_error(['msg' => 'DB error: ' . $this->db()->last_error]);
        }
      }
    } catch (Exception $e) {
      wp_send_json_error(['msg' => 'Error: ' . $e->getMessage()]);
    }

    wp_send_json_success(['ok' => true]);
  }

  public function ajax_admin_delete_slot() {
    check_ajax_referer('ct_ts_admin_nonce', 'nonce');

    $post_id = absint($_POST['post_id'] ?? 0);
    $slot_id = absint($_POST['slot_id'] ?? 0);

    if (!$post_id || !$slot_id || !current_user_can('edit_post', $post_id))
      wp_send_json_error(['msg'=>'No permission.']);

    if (!$this->ensure_table_exists()) wp_send_json_error(['msg'=>'DB table missing.']);

    $deleted = $this->db()->delete(
      $this->db_table(),
      ['id' => $slot_id, 'tour_id' => $post_id],
      ['%d','%d']
    );

    if ($deleted === false) {
      wp_send_json_error(['msg' => 'DB error deleting slot.']);
    }

    wp_send_json_success(['ok' => true]);
  }

  public function increment_booked_count($slot_id, $quantity = 1) {
    if (!$this->ensure_table_exists()) {
        return false;
    }

    $result = $this->db()->query(
        $this->db()->prepare(
            "UPDATE `{$this->db_table()}` 
             SET `booked` = `booked` + %d 
             WHERE `id` = %d",
            absint($quantity),
            absint($slot_id)
        )
    );

    return $result !== false;
  }

  public function get_slot_availability($slot_id) {
    if (!$this->ensure_table_exists()) {
        return 0;
    }

    $slot = $this->db()->get_row(
        $this->db()->prepare(
            "SELECT `capacity`, `booked` FROM `{$this->db_table()}` WHERE `id` = %d",
            absint($slot_id)
        ),
        ARRAY_A
    );

    if (!$slot) {
        return 0;
    }

    return max(0, intval($slot['capacity']) - intval($slot['booked']));
  }
  
      // ADD/REPLACE this method in the class
    public function frontend_assets() {
      if (!function_exists('is_product')) return;
    
      wp_register_script(
        'ct-booking',
        plugins_url('assets/js/ct-booking.js', __FILE__),
        ['jquery'],
        (defined('WP_DEBUG') && WP_DEBUG) ? time() : '2.1.0',
        true
      );
      wp_enqueue_script('ct-booking');
    
      global $post, $product;
      $post_id = $post ? intval($post->ID) : 0;
      $product_id = 0;
    
      if (function_exists('wc_get_product')) {
        if (!empty($product) && is_a($product, 'WC_Product')) {
          $product_id = intval($product->get_id());
        } elseif ($post_id) {
          $maybe = wc_get_product($post_id);
          if ($maybe) $product_id = intval($maybe->get_id());
        }
    }

  wp_localize_script('ct-booking', 'CT_BOOKING', [
    'product_id' => $product_id,
    'post_id'    => $post_id,
    'currency'   => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
    'cart_url'   => function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/'),
    // IMPORTANT: give JS the REST base your endpoints use
    'restBase'   => esc_url_raw( rest_url('ct-timeslots/v1') ),
  ]);
}

    // ADD/REPLACE this method in the class
    public function inject_hidden_booking_inputs() { ?>
      <input type="hidden" name="ct_date" id="ct_date_hidden" value="">
      <input type="hidden" name="ct_slot_id" id="ct_slot_id_hidden" value="">
      <input type="hidden" name="ct_mode" id="ct_mode_hidden" value="">
      <input type="hidden" name="ct_people" id="ct_people_hidden" value="">
      <input type="hidden" name="ct_adults" id="ct_adults_hidden" value="">
      <input type="hidden" name="ct_children" id="ct_children_hidden" value="">
      <input type="hidden" name="ct_extras_json" id="ct_extras_json_hidden" value="">
    <?php 
    }

}

new CT_Turio_Timeslots();
require_once plugin_dir_path(__FILE__) . 'includes/woocommerce.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend-inject.php';
