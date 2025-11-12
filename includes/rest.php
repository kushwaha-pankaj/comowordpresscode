<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('ct-timeslots/v1', '/days', [
        'methods' => 'GET',
        'callback' => 'ctts_get_days',
        'permission_callback' => '__return_true'
    ]);
    register_rest_route('ct-timeslots/v1', '/slots', [
        'methods' => 'GET',
        'callback' => 'ctts_get_slots',
        'permission_callback' => '__return_true'
    ]);
});

function ctts_get_days(WP_REST_Request $req) {
    global $wpdb;
    $post_id = intval($req->get_param('post_id'));
    $from = sanitize_text_field($req->get_param('from'));
    $to = sanitize_text_field($req->get_param('to'));
    $mode = sanitize_text_field($req->get_param('mode')) ?: 'private';

    if (!$post_id || !$from || !$to) {
        return rest_ensure_response(['ok' => false, 'msg' => 'Invalid parameters.']);
    }

    // FIX: Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return rest_ensure_response(['ok' => false, 'msg' => 'Invalid date format.']);
    }

    if (!in_array($mode, ['private', 'shared'], true)) {
        $mode = 'private';
    }

    $table = $wpdb->prefix . 'turio_timeslots';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$exists) {
        return rest_ensure_response(['ok' => false, 'msg' => 'Timeslots table missing.']);
    }

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT `date` as dt, COUNT(*) as cnt, MIN(`price`) as min_price
        FROM {$table}
        WHERE `tour_id`=%d 
          AND `date` BETWEEN %s AND %s
          AND `mode`=%s
        GROUP BY `date`
    ", $post_id, $from, $to, $mode), ARRAY_A);

    $days = [];
    $minPrices = [];
    $globalLowest = null;
    
    foreach ($rows as $r) {
        $days[$r['dt']] = intval($r['cnt']);
        $price = (float)$r['min_price'];
        $minPrices[$r['dt']] = $price;
        
        if ($globalLowest === null || $price < $globalLowest) {
            $globalLowest = $price;
        }
    }

    return rest_ensure_response([
        'ok' => true, 
        'days' => $days, 
        'minPrices' => $minPrices,
        'lowest' => $globalLowest
    ]);
}

function ctts_get_slots(WP_REST_Request $req) {
    global $wpdb;
    $post_id = intval($req->get_param('post_id'));
    $date = sanitize_text_field($req->get_param('date'));
    $mode = sanitize_text_field($req->get_param('mode')) ?: 'private';

    if (!$post_id || !$date) {
        return rest_ensure_response(['ok' => false, 'msg' => 'Invalid parameters.']);
    }

    // FIX: Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return rest_ensure_response(['ok' => false, 'msg' => 'Invalid date format.']);
    }

    if (!in_array($mode, ['private', 'shared'], true)) {
        $mode = 'private';
    }

    $table = $wpdb->prefix . 'turio_timeslots';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$exists) {
        return rest_ensure_response(['ok' => false, 'msg' => 'Timeslots table missing.']);
    }

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT id, `time`, duration, capacity, booked, price, mode, COALESCE(max_bookings, capacity) as max_bookings
        FROM {$table}
        WHERE tour_id=%d 
          AND `date`=%s
          AND `mode`=%s
        ORDER BY `time` ASC
    ", $post_id, $date, $mode), ARRAY_A);

    $slots = [];
    foreach ($rows as $r) {
        $start = $r['time'];
        $duration = intval($r['duration']);
        
        list($sh, $sm) = array_map('intval', explode(':', $start));
        $end_minutes = ($sh * 60 + $sm + $duration);

        // FIX: Handle midnight crossing properly
        if ($end_minutes >= 24 * 60) {
            $end_minutes = $end_minutes % (24 * 60);
        }

        $eh = floor($end_minutes / 60);
        $em = $end_minutes % 60;
        $end = sprintf('%02d:%02d', $eh, $em);
        
        $slots[] = [
            'id' => intval($r['id']),
            'time' => $r['time'],
            'end' => $end,
            'duration' => intval($r['duration']),
            'capacity' => intval($r['capacity']),
            'booked' => intval($r['booked']),
            'max_bookings' => intval($r['max_bookings']),
            'price' => (float)$r['price'],
            'mode' => $r['mode']
        ];
    }

    return rest_ensure_response(['ok' => true, 'slots' => $slots]);
}