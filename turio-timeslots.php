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
    add_action('before_delete_post', [$this, 'delete_tour_slots'], 10, 1);
    add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
    add_action('wp_ajax_ct_admin_get_slots_by_date', [$this, 'ajax_admin_get_slots_by_date']);
    add_action('wp_ajax_ct_admin_get_all_slots', [$this, 'ajax_admin_get_all_slots']);
    add_action('wp_ajax_ct_admin_add_slot', [$this, 'ajax_admin_add_slot']);
    add_action('wp_ajax_ct_admin_delete_slot', [$this, 'ajax_admin_delete_slot']);
    add_action('wp_ajax_ct_admin_bulk_delete_slots', [$this, 'ajax_admin_bulk_delete_slots']);
    add_action('wp_ajax_ct_admin_update_slot_capacity', [$this, 'ajax_admin_update_slot_capacity']);
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
    // Add max_bookings column if it doesn't exist
    $max_bookings_col = $this->db()->get_var($this->db()->prepare("SHOW COLUMNS FROM `{$t}` LIKE %s", 'max_bookings'));
    if (!$max_bookings_col) {
      $this->db()->query("ALTER TABLE `{$t}` ADD COLUMN `max_bookings` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `capacity`");
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

    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
    echo '<h3 style="margin:0 0 20px 0;font-size:16px;font-weight:600;color:#23282d;border-bottom:2px solid #0073aa;padding-bottom:10px;">General Settings</h3>';
    
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;">';
    
    echo '<div>';
    echo '<label style="display:block;margin-bottom:8px;font-weight:600;color:#23282d;font-size:13px;">Product Type</label>';
    echo '<select name="ct_mode" id="ct_mode" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;background:#fff;transition:border-color 0.2s;">';
    echo '<option value="private" '.selected($mode,'private',false).'>Private (Tour)</option>';
    echo '<option value="shared"  '.selected($mode,'shared',false).'>Shared (Per-Seat)</option>';
    echo '</select>';
    echo '</div>';

    echo '<div>';
    echo '<label style="display:block;margin-bottom:8px;font-weight:600;color:#23282d;font-size:13px;">Date Range</label>';
    echo '<div style="display:flex;gap:10px;">';
    echo '<div style="flex:1;"><input type="text" class="ct-date" name="ct_date_from" id="ct_date_from" value="'.esc_attr($date_from).'" placeholder="From" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '<div style="flex:1;"><input type="text" class="ct-date" name="ct_date_to" id="ct_date_to" value="'.esc_attr($date_to).'" placeholder="To" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '</div>';
    echo '</div>';

    echo '<div>';
    echo '<label style="display:block;margin-bottom:8px;font-weight:600;color:#23282d;font-size:13px;">Specific Date (Optional)</label>';
    echo '<div style="display:flex;gap:8px;">';
    echo '<input type="text" class="ct-date" name="ct_specific_date" id="ct_specific_date" value="" placeholder="YYYY-MM-DD" autocomplete="off" style="flex:1;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;">';
    echo '<button type="button" class="button" id="ct_clear_specific_date" style="white-space:nowrap;padding:10px 16px;">Clear</button>';
    echo '</div>';
    echo '</div>';

    echo '<div>';
    echo '<label style="display:block;margin-bottom:8px;font-weight:600;color:#23282d;font-size:13px;">Bookings Available (Inventory)</label>';
    echo '<input type="number" min="0" name="ct_max_people" id="ct_max_people" value="'.esc_attr($max_people).'" placeholder="e.g., 20" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;">';
    echo '<span class="description" style="display:block;margin-top:6px;font-size:12px;color:#646970;line-height:1.5;">Maximum number of bookings allowed for this tour</span>';
    echo '</div>';

    echo '</div></div>';

    echo '<div id="ct_private_box" class="'.($mode==='shared'?'ct-hide':'').'" style="margin-bottom:24px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #0073aa;">';
    echo '<h3 style="margin:0;font-size:16px;font-weight:600;color:#23282d;">Private Tour – Time Slots & Pricing</h3>';
    echo '<span style="background:#0073aa;color:#fff;padding:4px 12px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase;">Private</span>';
    echo '</div>';
    echo '<div style="background:#f0f6fc;border-left:4px solid #0073aa;padding:12px 16px;margin-bottom:20px;border-radius:4px;">';
    echo '<p style="margin:0;font-size:13px;color:#1d2327;line-height:1.6;"><strong>💡 Tip:</strong> Leave "Specific Date" empty to duplicate this slot across the selected date range. <strong>Capacity</strong> = how many people can fit. <strong>Max Bookings</strong> = how many times this slot can be booked.</p>';
    echo '</div>';
    echo '<div class="ct-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;">';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">Start Time</label><input type="text" id="ct_p_start" class="ct-time" placeholder="07:00" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">End Time</label><input type="text" id="ct_p_end" class="ct-time" placeholder="09:00" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">Capacity (People)</label><input type="number" min="1" id="ct_p_capacity" placeholder="10" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">Max Bookings</label><input type="number" min="1" id="ct_p_max_bookings" placeholder="20" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">Price (€)</label><input type="number" step="0.01" id="ct_p_price" placeholder="100.00" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">Promo Price (€)</label><input type="number" step="0.01" id="ct_p_promo" placeholder="Optional" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">Discount (%)</label><input type="number" step="1" id="ct_p_disc" placeholder="Optional" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '</div>';
    echo '<button class="button button-primary" id="ct-add-p-slot" style="padding:12px 24px;font-size:14px;font-weight:600;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,0.1);">+ Add Private Time Slot</button>';
    echo '</div>';

    echo '<div id="ct_shared_box" class="'.($mode==='private'?'ct-hide':'').'" style="margin-bottom:24px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #00a32a;">';
    echo '<h3 style="margin:0;font-size:16px;font-weight:600;color:#23282d;">Shared Tour – Time Slots & Pricing</h3>';
    echo '<span style="background:#00a32a;color:#fff;padding:4px 12px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase;">Shared</span>';
    echo '</div>';
    echo '<div style="background:#f0f6fc;border-left:4px solid #00a32a;padding:12px 16px;margin-bottom:20px;border-radius:4px;">';
    echo '<p style="margin:0;font-size:13px;color:#1d2327;line-height:1.6;">Inventory above limits how many shared slots you can sell overall. Use capacity below for seats available in this slot.</p>';
    echo '</div>';
    echo '<div class="ct-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;">';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">Start Time</label><input type="text" id="ct_s_start" class="ct-time" placeholder="10:00" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">End Time</label><input type="text" id="ct_s_end" class="ct-time" placeholder="11:00" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">Capacity</label><input type="number" min="1" id="ct_s_capacity" placeholder="20" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '<div><label style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#646970;">Price per Person (€)</label><input type="number" step="0.01" id="ct_s_price" placeholder="50.00" style="width:100%;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;"></div>';
    echo '</div>';
    echo '<button class="button" id="ct-add-s-slot" style="padding:12px 24px;font-size:14px;font-weight:600;border-radius:4px;background:#00a32a;border-color:#00a32a;color:#fff;box-shadow:0 1px 2px rgba(0,0,0,0.1);">+ Add Shared Time Slot</button>';
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:16px;padding-bottom:16px;border-bottom:2px solid #0073aa;">';
    echo '<h3 style="margin:0;font-size:16px;font-weight:600;color:#23282d;">Time Slots Management</h3>';
    echo '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
    echo '<label style="margin:0;font-weight:600;font-size:13px;color:#646970;">Filter by date:</label>';
    echo '<input type="text" class="ct-date" id="ct_table_date_filter" value="" placeholder="YYYY-MM-DD" autocomplete="off" style="width:150px;padding:8px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;">';
    echo '<button type="button" class="button" id="ct_clear_table_filter" style="white-space:nowrap;padding:8px 16px;">Clear Filter</button>';
    echo '</div>';
    echo '</div>';
    echo '<div id="ct_bulk_actions" style="margin-bottom:16px;padding:14px 16px;background:linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);border:1px solid #ffc107;border-left:4px solid #ff9800;display:none;border-radius:6px;box-shadow:0 2px 4px rgba(0,0,0,0.05);">';
    echo '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
    echo '<button type="button" class="button" id="ct_select_all_slots" style="padding:8px 16px;font-weight:600;">Select All</button>';
    echo '<button type="button" class="button" id="ct_deselect_all_slots" style="padding:8px 16px;font-weight:600;">Deselect All</button>';
    echo '<button type="button" class="button button-link-delete" id="ct_bulk_delete_slots" style="color:#b32d2e;font-weight:600;padding:8px 16px;background:#fff;border:1px solid #b32d2e;border-radius:4px;">🗑️ Delete Selected</button>';
    echo '<span id="ct_selected_count" style="margin-left:auto;font-weight:600;color:#1d2327;font-size:14px;padding:6px 12px;background:#fff;border-radius:4px;"></span>';
    echo '</div>';
    echo '</div>';
    echo '<div style="overflow-x:auto;border:1px solid #ddd;border-radius:6px;">';
    echo '<table class="widefat fixed striped" id="ct_slots_table" style="margin:0;border-collapse:collapse;">';
    echo '<thead><tr id="ct_table_header" style="background:#f6f7f7;"></tr></thead>';
    echo '<tbody><tr><td colspan="11" id="ct_loading_msg" style="text-align:center;padding:40px;color:#646970;font-size:14px;">Loading time slots…</td></tr></tbody>';
    echo '</table>';
    echo '</div>';
    echo '<div id="ct_slots_pagination" style="margin-top:20px;display:none;"></div>';
    echo '</div>';
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

    // Remove extras saving: intentionally no-op; clean previous data if present
    delete_post_meta($post_id, '_ct_product_extras');
    
    // Migrate temporary slots from transient to database
    $user_id = get_current_user_id();
    $temp_key = '_ct_timeslots_temp_' . $user_id;
    $temp_slots = get_transient($temp_key);
    
    if ($temp_slots !== false && is_array($temp_slots) && !empty($temp_slots)) {
      if ($this->ensure_table_exists()) {
        $migrated = 0;
        $skipped = 0;
        
        foreach ($temp_slots as $slot_key => $slot_data) {
          try {
            // Check for duplicates
            $existing = $this->db()->get_var($this->db()->prepare(
              "SELECT COUNT(*) FROM `{$this->db_table()}` 
               WHERE `tour_id`=%d AND `date`=%s AND `time`=%s AND `duration`=%d",
              $post_id,
              $slot_data['date'],
              $slot_data['time'],
              $slot_data['duration']
            ));
            
            if ($existing > 0) {
              $skipped++;
              continue;
            }
            
            $inserted = $this->db()->insert(
              $this->db_table(),
              [
                'tour_id' => $post_id,
                'date' => $slot_data['date'],
                'time' => $slot_data['time'],
                'duration' => $slot_data['duration'],
                'capacity' => $slot_data['capacity'],
                'max_bookings' => isset($slot_data['max_bookings']) ? $slot_data['max_bookings'] : $slot_data['capacity'],
                'booked' => $slot_data['booked'],
                'price' => $slot_data['price'],
                'mode' => $slot_data['mode']
              ],
              ['%d','%s','%s','%d','%d','%d','%d','%f','%s']
            );
            
            if ($inserted !== false) {
              $migrated++;
            }
          } catch (Exception $e) {
            // Log error but continue migration
            error_log('CT Timeslots: Error migrating slot: ' . $e->getMessage());
          }
        }
        
        // Clear temporary slots after migration
        delete_transient($temp_key);
      }
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

    // No inline extras management script (feature removed)
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
    $range_from = $this->norm_date(sanitize_text_field($_POST['range_from'] ?? ''));
    $range_to = $this->norm_date(sanitize_text_field($_POST['range_to'] ?? ''));

    $is_unsaved = ($post_id === 0);
    
    if (!$is_unsaved && !current_user_can('edit_post', $post_id)) {
      wp_send_json_error(['msg'=>'No permission.']);
    }

    $dates = [];
    if ($range_from && $range_to) {
      try {
        $start = new DateTime($range_from);
        $end = new DateTime($range_to);
      } catch (Exception $e) {
        wp_send_json_error(['msg' => 'Invalid date range provided.']);
      }

      if ($start > $end) {
        wp_send_json_error(['msg' => '"From" date must be before "To" date.']);
      }

      $iter = clone $start;
      while ($iter <= $end) {
        $dates[] = $iter->format('Y-m-d');
        $iter->modify('+1 day');
      }
    } elseif ($date) {
      // Validate that date was normalized successfully
      if (empty($date)) {
        wp_send_json_error(['msg'=>'Invalid date format. Please use YYYY-MM-DD format.']);
      }
      $dates[] = $date;
    } else {
      wp_send_json_error(['msg'=>'Select a specific date or a valid date range.']);
    }
    
    // Ensure we have at least one date
    if (empty($dates)) {
      wp_send_json_error(['msg'=>'No valid date provided.']);
    }

    $slots = [];
    
    if ($is_unsaved) {
      // Read from user-specific transient
      $user_id = get_current_user_id();
      $meta_key = '_ct_timeslots_temp_' . $user_id;
      $temp_slots = get_transient($meta_key);
      if ($temp_slots === false) {
        $temp_slots = [];
      }
      $target_date = $dates[0] ?? $date;
      
      foreach ($temp_slots as $slot_key => $slot_data) {
        if ($slot_data['date'] === $target_date) {
          $start_time = $slot_data['time'];
          $duration = intval($slot_data['duration']);
          [$sh,$sm] = array_map('intval', explode(':', $start_time));
          $end_minutes = ($sh * 60 + $sm + $duration);
          
          if ($end_minutes >= 24 * 60) {
            $end_minutes = $end_minutes % (24*60);
          }
          
          $eh = floor($end_minutes / 60);
          $em = $end_minutes % 60;
          $end_time = sprintf('%02d:%02d', $eh, $em);
          
          // Use a negative ID to indicate it's from temp storage
          $slots[] = [
            'id' => -abs(crc32($slot_key)), // Negative ID for temp slots
            'date' => $target_date,
            'mode' => $slot_data['mode'] ?? 'private',
            'time' => $start_time,
            'end' => $end_time,
            'duration' => $duration,
            'capacity' => intval($slot_data['capacity']),
            'price' => (float)$slot_data['price'],
            'booked' => intval($slot_data['booked']),
            '_temp_key' => $slot_key // Store key for deletion
          ];
        }
      }
      
      // Sort by time
      usort($slots, function($a, $b) {
        return strcmp($a['time'], $b['time']);
      });
    } else {
      // Read from database
      if (!$this->ensure_table_exists()) wp_send_json_error(['msg'=>'DB table missing.']);

      $rows = $this->db()->get_results(
        $this->db()->prepare(
          "SELECT `id`, `time`, `duration`, `capacity`, `price`, `booked`, `mode`
           FROM `{$this->db_table()}`
           WHERE `tour_id`=%d AND `date`=%s
           ORDER BY `time` ASC",
          $post_id, $dates[0] ?? $date
        ),
        ARRAY_A
      );

      foreach ($rows as $r) {
        $start_time = $r['time'];
        $duration = intval($r['duration']);
        [$sh,$sm] = array_map('intval', explode(':', $start_time));
        $end_minutes = ($sh * 60 + $sm + $duration);
        
        if ($end_minutes >= 24 * 60) {
          $end_minutes = $end_minutes % (24*60);
        }
        
        $eh = floor($end_minutes / 60);
        $em = $end_minutes % 60;
        $end_time = sprintf('%02d:%02d', $eh, $em);

        $slots[] = [
          'id' => intval($r['id']),
          'date' => $dates[0] ?? $date,
          'mode' => $r['mode'] ?? 'private',
          'time' => $start_time,
          'end' => $end_time,
          'duration' => $duration,
          'capacity' => intval($r['capacity']),
          'price' => (float)$r['price'],
          'booked' => intval($r['booked']),
        ];
      }
    }

    wp_send_json_success(['date' => $dates[0] ?? $date, 'slots' => $slots]);
  }

  public function ajax_admin_get_all_slots() {
    check_ajax_referer('ct_ts_admin_nonce', 'nonce');

    $post_id = absint($_POST['post_id'] ?? 0);
    $filter_date = $this->norm_date(sanitize_text_field($_POST['filter_date'] ?? ''));
    $page = absint($_POST['page'] ?? 1);
    $per_page = absint($_POST['per_page'] ?? 10);
    $offset = ($page - 1) * $per_page;

    $is_unsaved = ($post_id === 0);
    
    if (!$is_unsaved && !current_user_can('edit_post', $post_id)) {
      wp_send_json_error(['msg'=>'No permission.']);
    }

    $slots = [];
    $total = 0;

    if ($is_unsaved) {
      // Read from user-specific transient
      $user_id = get_current_user_id();
      $meta_key = '_ct_timeslots_temp_' . $user_id;
      $temp_slots = get_transient($meta_key);
      if ($temp_slots === false) {
        $temp_slots = [];
      }
      
      foreach ($temp_slots as $slot_key => $slot_data) {
        if ($filter_date && $slot_data['date'] !== $filter_date) {
          continue;
        }
        
        $start_time = $slot_data['time'];
        $duration = intval($slot_data['duration']);
        [$sh,$sm] = array_map('intval', explode(':', $start_time));
        $end_minutes = ($sh * 60 + $sm + $duration);
        
        if ($end_minutes >= 24 * 60) {
          $end_minutes = $end_minutes % (24*60);
        }
        
        $eh = floor($end_minutes / 60);
        $em = $end_minutes % 60;
        $end_time = sprintf('%02d:%02d', $eh, $em);
        
        $slots[] = [
          'id' => -abs(crc32($slot_key)),
          'date' => $slot_data['date'],
          'mode' => $slot_data['mode'] ?? 'private',
          'time' => $start_time,
          'end' => $end_time,
          'duration' => $duration,
          'capacity' => intval($slot_data['capacity']),
          'max_bookings' => isset($slot_data['max_bookings']) ? intval($slot_data['max_bookings']) : intval($slot_data['capacity']),
          'price' => (float)$slot_data['price'],
          'booked' => intval($slot_data['booked']),
          '_temp_key' => $slot_key
        ];
      }
      
      $total = count($slots);
      // Sort by date, then time
      usort($slots, function($a, $b) {
        $dateCmp = strcmp($a['date'], $b['date']);
        return $dateCmp !== 0 ? $dateCmp : strcmp($a['time'], $b['time']);
      });
      $slots = array_slice($slots, $offset, $per_page);
    } else {
      // Read from database
      if (!$this->ensure_table_exists()) wp_send_json_error(['msg'=>'DB table missing.']);

      $where = "`tour_id`=%d";
      $params = [$post_id];
      
      if ($filter_date) {
        $where .= " AND `date`=%s";
        $params[] = $filter_date;
      }

      // Get total count
      $total = $this->db()->get_var($this->db()->prepare(
        "SELECT COUNT(*) FROM `{$this->db_table()}` WHERE {$where}",
        ...$params
      ));

      // Get paginated results
      $rows = $this->db()->get_results($this->db()->prepare(
        "SELECT `id`, `date`, `time`, `duration`, `capacity`, COALESCE(`max_bookings`, `capacity`) as `max_bookings`, `price`, `booked`, `mode`
         FROM `{$this->db_table()}`
         WHERE {$where}
         ORDER BY `date` ASC, `time` ASC
         LIMIT %d OFFSET %d",
        ...array_merge($params, [$per_page, $offset])
      ), ARRAY_A);

      foreach ($rows as $r) {
        $start_time = $r['time'];
        $duration = intval($r['duration']);
        [$sh,$sm] = array_map('intval', explode(':', $start_time));
        $end_minutes = ($sh * 60 + $sm + $duration);
        
        if ($end_minutes >= 24 * 60) {
          $end_minutes = $end_minutes % (24*60);
        }
        
        $eh = floor($end_minutes / 60);
        $em = $end_minutes % 60;
        $end_time = sprintf('%02d:%02d', $eh, $em);

        $slots[] = [
          'id' => intval($r['id']),
          'date' => $r['date'],
          'mode' => $r['mode'] ?? 'private',
          'time' => $start_time,
          'end' => $end_time,
          'duration' => $duration,
          'capacity' => intval($r['capacity']),
          'price' => (float)$r['price'],
          'booked' => intval($r['booked']),
        ];
      }
    }

    wp_send_json_success([
      'slots' => $slots,
      'total' => $total,
      'page' => $page,
      'per_page' => $per_page,
      'total_pages' => ceil($total / $per_page)
    ]);
  }

  public function ajax_admin_update_slot_capacity() {
    check_ajax_referer('ct_ts_admin_nonce', 'nonce');

    $post_id = absint($_POST['post_id'] ?? 0);
    // Don't use absint() for slot_id - we need to preserve negative IDs for unsaved posts
    $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
    $max_bookings = absint($_POST['capacity'] ?? 0); // This is actually max_bookings, not capacity

    $is_unsaved = ($post_id === 0);
    
    if (!$slot_id || $max_bookings < 1) {
      wp_send_json_error(['msg'=>'Invalid slot ID or max bookings.']);
    }
    
    if (!$is_unsaved && !current_user_can('edit_post', $post_id)) {
      wp_send_json_error(['msg'=>'No permission.']);
    }

    if ($is_unsaved) {
      // Update in transient
      $user_id = get_current_user_id();
      $meta_key = '_ct_timeslots_temp_' . $user_id;
      $temp_slots = get_transient($meta_key);
      if ($temp_slots === false) {
        wp_send_json_error(['msg'=>'Slot not found.']);
      }
      
      $found = false;
      foreach ($temp_slots as $key => $slot_data) {
        $temp_id = -abs(crc32($key));
        if ($temp_id == $slot_id) {
          $temp_slots[$key]['max_bookings'] = $max_bookings;
          $found = true;
          break;
        }
      }
      
      if ($found) {
        set_transient($meta_key, $temp_slots, DAY_IN_SECONDS);
        wp_send_json_success(['ok' => true, 'max_bookings' => $max_bookings]);
      } else {
        wp_send_json_error(['msg'=>'Slot not found in temporary storage.']);
      }
    } else {
      // Update in database
      if (!$this->ensure_table_exists()) wp_send_json_error(['msg'=>'DB table missing.']);

      $updated = $this->db()->update(
        $this->db_table(),
        ['max_bookings' => $max_bookings],
        ['id' => $slot_id, 'tour_id' => $post_id],
        ['%d'],
        ['%d', '%d']
      );

      if ($updated === false) {
        wp_send_json_error(['msg' => 'DB error updating max bookings.']);
      }

      wp_send_json_success(['ok' => true, 'max_bookings' => $max_bookings]);
    }
  }

  public function ajax_admin_add_slot() {
    check_ajax_referer('ct_ts_admin_nonce', 'nonce');

    $post_id = absint($_POST['post_id'] ?? 0);
    $date_raw = sanitize_text_field($_POST['date'] ?? '');
    $date = $this->norm_date($date_raw);
    $date_list_raw = !empty($_POST['date_list']) ? json_decode(wp_unslash($_POST['date_list']), true) : [];
    $mode = sanitize_text_field($_POST['mode'] ?? 'private');
    $mode = ($mode === 'shared') ? 'shared' : 'private';

    $start = $this->norm_time(sanitize_text_field($_POST['start'] ?? ''));
    $end = $this->norm_time(sanitize_text_field($_POST['end'] ?? ''));

    $capacity = isset($_POST['capacity']) && is_numeric($_POST['capacity']) ? absint($_POST['capacity']) : null;
    $max_bookings = isset($_POST['max_bookings']) && is_numeric($_POST['max_bookings']) ? absint($_POST['max_bookings']) : null;
    $price = is_numeric($_POST['price'] ?? null) ? floatval($_POST['price']) : 0.0;
    $promo = is_numeric($_POST['promo'] ?? null) ? floatval($_POST['promo']) : 0.0;
    $disc = is_numeric($_POST['disc'] ?? null) ? floatval($_POST['disc']) : 0.0;

    // For unsaved posts (post_id = 0), we'll store in a temporary meta key
    $is_unsaved = ($post_id === 0);
    
    if (!$is_unsaved && !current_user_can('edit_post', $post_id)) {
      wp_send_json_error(['msg'=>'No permission.']);
    }

    $dates = [];
    if (is_array($date_list_raw) && !empty($date_list_raw)) {
      foreach ($date_list_raw as $candidate) {
        $normalized = $this->norm_date(sanitize_text_field($candidate));
        if ($normalized) {
          // Validate date is not in the past
          $date_obj = DateTime::createFromFormat('Y-m-d', $normalized);
          $today = new DateTime();
          $today->setTime(0, 0, 0);
          if ($date_obj && $date_obj < $today) {
            wp_send_json_error(['msg' => 'Cannot add time slots for past dates. Selected date: ' . $normalized]);
          }
          $dates[] = $normalized;
        }
      }
    }

    if (empty($dates)) {
      if ($date) {
        // Validate date is not in the past
        $date_obj = DateTime::createFromFormat('Y-m-d', $date);
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        if ($date_obj && $date_obj < $today) {
          wp_send_json_error(['msg' => 'Cannot add time slots for past dates. Selected date: ' . $date]);
        }
        $dates[] = $date;
      } else {
        wp_send_json_error(['msg'=>'Select a specific date or provide a valid date range.']);
      }
    }

    $dates = array_values(array_unique($dates));

    if (count($dates) > 366) {
      wp_send_json_error(['msg'=>'Date range too large. Please add at most 366 days at once.']);
    }

    if (!$start || !$end) wp_send_json_error(['msg'=>'Invalid start or end time.']);

    $provided_max = isset($_POST['post_max_people']) && is_numeric($_POST['post_max_people']) ? absint($_POST['post_max_people']) : 0;
    $meta_max = $is_unsaved ? 0 : (int)(get_post_meta($post_id, '_ct_max_people', true) ?: 0);
    $max_people = ($provided_max > 0) ? $provided_max : $meta_max;

    if ($mode === 'private') {
      // For private tours, use the provided capacity if available, otherwise use max_people
      if ($capacity === null || $capacity < 1) {
        $capacity = ($max_people >= 0) ? $max_people : 1;
      }
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

    $created = [];
    $duplicates = [];
    $errors = [];

    if ($is_unsaved) {
      // Store in user-specific transient for unsaved posts
      $user_id = get_current_user_id();
      $meta_key = '_ct_timeslots_temp_' . $user_id;
      $existing_slots = get_transient($meta_key);
      if ($existing_slots === false) {
        $existing_slots = [];
      }
      
      foreach ($dates as $current_date) {
        $slot_key = $current_date . '_' . $start . '_' . $dur;
        
        // Check for duplicates
        if (isset($existing_slots[$slot_key])) {
          $duplicates[] = $current_date;
          continue;
        }
        
        $slot_data = [
          'date' => $current_date,
          'time' => $start,
          'duration' => $dur,
          'capacity' => $capacity,
          'max_bookings' => $max_bookings ?: $capacity, // Default to capacity if not provided
          'booked' => 0,
          'price' => floatval($final),
          'mode' => $mode
        ];
        
        $existing_slots[$slot_key] = $slot_data;
        $created[] = $current_date;
      }
      
      // Store in transient (expires in 24 hours)
      set_transient($meta_key, $existing_slots, DAY_IN_SECONDS);
    } else {
      // Store in database for saved posts
      if (!$this->ensure_table_exists()) wp_send_json_error(['msg'=>'DB table missing.']);
      
      foreach ($dates as $current_date) {
        try {
          $inserted = $this->db()->insert(
            $this->db_table(),
            [
              'tour_id' => $post_id,
              'date' => $current_date,
              'time' => $start,
              'duration' => $dur,
              'capacity' => $capacity,
              'max_bookings' => $max_bookings ?: $capacity, // Default to capacity if not provided
              'booked' => 0,
              'price' => floatval($final),
              'mode' => $mode
            ],
            ['%d','%s','%s','%d','%d','%d','%d','%f','%s']
          );

          if ($inserted === false) {
            if ($this->db()->last_error && strpos($this->db()->last_error, 'Duplicate') !== false) {
              $duplicates[] = $current_date;
              $this->db()->last_error = '';
              continue;
            }
            $errors[] = [
              'date' => $current_date,
              'error' => $this->db()->last_error ?: 'Unknown database error.'
            ];
          } else {
            $created[] = $current_date;
          }
        } catch (Exception $e) {
          $errors[] = [
            'date' => $current_date,
            'error' => $e->getMessage()
          ];
        }
      }
    }

    if (empty($created)) {
      if (!empty($duplicates) && empty($errors)) {
        wp_send_json_error(['msg' => 'An identical time slot already exists for the selected dates.']);
      }

      if (!empty($errors)) {
        $messages = array_map(function($row){
          return $row['date'] . ': ' . $row['error'];
        }, $errors);
        wp_send_json_error(['msg' => 'Error creating slots: ' . implode(' | ', $messages)]);
      }

      wp_send_json_error(['msg' => 'Unable to create time slots for the selected dates.']);
    }

    $message = sprintf(
      'Created %d time slot%s%s.',
      count($created),
      count($created) === 1 ? '' : 's',
      !empty($duplicates) ? sprintf(' (Skipped %d duplicate%s)', count($duplicates), count($duplicates) === 1 ? '' : 's') : ''
    );

    $response = [
      'ok' => true,
      'message' => $message,
      'created' => count($created),
      'appliedDates' => $created,
    ];

    if (!empty($duplicates)) {
      $response['duplicates'] = $duplicates;
    }
    if (!empty($errors)) {
      $response['errors'] = $errors;
    }

    wp_send_json_success($response);
  }

  public function ajax_admin_delete_slot() {
    check_ajax_referer('ct_ts_admin_nonce', 'nonce');

    $post_id = absint($_POST['post_id'] ?? 0);
    // Don't use absint() for slot_id - we need to preserve negative IDs for unsaved posts
    $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;

    $is_unsaved = ($post_id === 0);
    
    if (!$slot_id) wp_send_json_error(['msg'=>'Missing slot ID.']);
    
    if (!$is_unsaved && !current_user_can('edit_post', $post_id)) {
      wp_send_json_error(['msg'=>'No permission.']);
    }

    if ($is_unsaved) {
      // Delete from user-specific transient
      $user_id = get_current_user_id();
      $meta_key = '_ct_timeslots_temp_' . $user_id;
      $temp_slots = get_transient($meta_key);
      if ($temp_slots === false) {
        $temp_slots = [];
      }
      
      // Find slot by negative ID (temp slots have negative IDs)
      $found_key = null;
      foreach ($temp_slots as $key => $slot_data) {
        $temp_id = -abs(crc32($key));
        if ($temp_id == $slot_id) {
          $found_key = $key;
          break;
        }
      }
      
      if ($found_key && isset($temp_slots[$found_key])) {
        unset($temp_slots[$found_key]);
        set_transient($meta_key, $temp_slots, DAY_IN_SECONDS);
        wp_send_json_success(['ok' => true]);
      } else {
        wp_send_json_error(['msg' => 'Slot not found in temporary storage.']);
      }
    } else {
      // Delete from database
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
  }

  public function delete_tour_slots($post_id) {
    $post_type = get_post_type($post_id);
    $allowed = apply_filters('ct_ts_post_types', ['turio-package']);
    
    if (!in_array($post_type, $allowed, true)) {
      return;
    }

    if (!$this->ensure_table_exists()) {
      return;
    }

    // Delete all slots for this tour
    $this->db()->delete(
      $this->db_table(),
      ['tour_id' => $post_id],
      ['%d']
    );
  }

  public function ajax_admin_bulk_delete_slots() {
    check_ajax_referer('ct_ts_admin_nonce', 'nonce');

    $post_id = absint($_POST['post_id'] ?? 0);
    $slot_ids_raw = isset($_POST['slot_ids']) ? $_POST['slot_ids'] : [];
    
    if (!is_array($slot_ids_raw) || empty($slot_ids_raw)) {
      wp_send_json_error(['msg'=>'No slots selected.']);
    }

    // Don't use absint() - we need to preserve negative IDs for unsaved posts
    $slot_ids = array_map('intval', $slot_ids_raw);
    $slot_ids = array_filter($slot_ids, function($id) { return $id != 0; });

    if (empty($slot_ids)) {
      wp_send_json_error(['msg'=>'Invalid slot IDs.']);
    }

    $is_unsaved = ($post_id === 0);
    
    if (!$is_unsaved && !current_user_can('edit_post', $post_id)) {
      wp_send_json_error(['msg'=>'No permission.']);
    }

    $deleted = 0;
    $errors = [];

    if ($is_unsaved) {
      // Delete from transient
      $user_id = get_current_user_id();
      $meta_key = '_ct_timeslots_temp_' . $user_id;
      $temp_slots = get_transient($meta_key);
      if ($temp_slots === false) {
        $temp_slots = [];
      }
      
      foreach ($slot_ids as $slot_id) {
        $found = false;
        foreach ($temp_slots as $key => $slot_data) {
          $temp_id = -abs(crc32($key));
          if ($temp_id == $slot_id) {
            unset($temp_slots[$key]);
            $deleted++;
            $found = true;
            break;
          }
        }
        if (!$found) {
          $errors[] = $slot_id;
        }
      }
      
      if ($deleted > 0) {
        set_transient($meta_key, $temp_slots, DAY_IN_SECONDS);
      }
    } else {
      // Delete from database (only positive IDs for saved posts)
      if (!$this->ensure_table_exists()) {
        wp_send_json_error(['msg'=>'DB table missing.']);
      }

      // Filter to only positive IDs for database deletion
      $db_slot_ids = array_filter($slot_ids, function($id) { return $id > 0; });
      
      if (empty($db_slot_ids)) {
        wp_send_json_error(['msg' => 'No valid slot IDs for database deletion.']);
      }

      $placeholders = implode(',', array_fill(0, count($db_slot_ids), '%d'));
      $query = $this->db()->prepare(
        "DELETE FROM `{$this->db_table()}` WHERE `tour_id`=%d AND `id` IN ($placeholders)",
        array_merge([$post_id], $db_slot_ids)
      );
      
      $deleted = $this->db()->query($query);
      
      if ($deleted === false) {
        wp_send_json_error(['msg' => 'DB error deleting slots.']);
      }
    }

    if ($deleted > 0) {
      wp_send_json_success([
        'ok' => true,
        'deleted' => $deleted,
        'message' => sprintf('Deleted %d slot%s.', $deleted, $deleted === 1 ? '' : 's')
      ]);
    } else {
      wp_send_json_error(['msg' => 'No slots were deleted.']);
    }
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
