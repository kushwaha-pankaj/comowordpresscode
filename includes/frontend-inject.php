<?php
if (!defined('ABSPATH')) exit;

add_action('after_setup_theme', function () {
    remove_action('egns_tour_booking_form', 'turio_render_tour_data_booking_form', 10);
}, 20);

add_action('wp_enqueue_scripts', function () {
    $base = plugin_dir_path(__FILE__) . '../';
    $url = plugin_dir_url(__FILE__) . '../';

    // Enqueue booking styles/scripts for tour pages
    if (is_singular('turio-package')) {
        wp_enqueue_style('litepicker', 'https://cdn.jsdelivr.net/npm/litepicker@2.0.12/dist/css/litepicker.css', [], '2.0.12');
        wp_enqueue_script('litepicker', 'https://cdn.jsdelivr.net/npm/litepicker@2.0.12/dist/litepicker.js', [], '2.0.12', true);

        $css = $base . 'assets/css/ct-booking.css';
        if (file_exists($css)) {
            wp_enqueue_style('ct-booking-css', $url . 'assets/css/ct-booking.css', ['litepicker'], filemtime($css));
        }

        $js = $base . 'assets/js/ct-booking.js';
        if (file_exists($js)) {
            wp_enqueue_script('ct-booking-js', $url . 'assets/js/ct-booking.js', ['litepicker'], filemtime($js), true);

            wp_localize_script('ct-booking-js', 'CT_BOOKING', [
                'postId' => get_the_ID(),
                'currency' => 'EUR', // Force EUR currency
                'restBase' => esc_url_raw(rest_url('ct-timeslots/v1')),
            ]);
        }
    }

    // Enqueue cart styles for cart/checkout pages
    if (function_exists('is_cart') && (is_cart() || is_checkout())) {
        $cart_css = $base . 'assets/css/ct-cart.css';
        if (file_exists($cart_css)) {
            wp_enqueue_style('ct-cart-css', $url . 'assets/css/ct-cart.css', [], filemtime($cart_css));
        }
    }
}, 20);

add_action('egns_tour_booking_form', function() {
    if (!is_singular('turio-package')) return;
    static $done = false;
    if ($done) return;
    $done = true;

    echo '<div id="ct-inline-booking">';
    $tpl = plugin_dir_path(__FILE__) . '../templates/booking-card.php';
    if (file_exists($tpl)) include $tpl;
    echo '</div>';
    
    // Inject hidden fields for WooCommerce form submission
    $post_id = get_the_ID();
    echo '<input type="hidden" name="ct_date" id="ct_date_hidden" value="">';
    echo '<input type="hidden" name="ct_slot_id" id="ct_slot_id_hidden" value="">';
    echo '<input type="hidden" name="ct_mode" id="ct_mode_hidden" value="">';
    echo '<input type="hidden" name="ct_people" id="ct_people_hidden" value="">';
    echo '<input type="hidden" name="ct_extras_json" id="ct_extras_hidden" value="">';
    echo '<input type="hidden" name="tour_id" value="' . esc_attr($post_id) . '">';
}, 5);

add_filter('body_class', function ($classes) {
    if (is_singular('turio-package')) {
        $classes[] = 'ct-ts-single';
    }
    return $classes;
});

add_action('wp_footer', function () {
    if (!is_singular('turio-package')) return;
    static $ct_ts_mobile_btn_rendered = false;
    if ($ct_ts_mobile_btn_rendered) return;
    $ct_ts_mobile_btn_rendered = true;
    echo '<div class="ct-mobile-book-bar" role="region" aria-label="Book this experience">';
    echo '<a class="ct-mobile-book-button" href="#ct-booking-card">'.esc_html__('Book This Experience', 'comotour').'</a>';
    echo '</div>';
}, 20);