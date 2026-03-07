<?php
/**
 * WordPress authentication wrapper for ADESZ admin pages.
 * Loads WordPress core to verify the current user is a WP admin.
 */

// WordPress is at the document root on OVH
$wp_load = $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';

if (!file_exists($wp_load)) {
    http_response_code(500);
    die('WordPress not found');
}

require_once $wp_load;

if (!is_user_logged_in() || !current_user_can('manage_options')) {
    wp_redirect(wp_login_url($_SERVER['REQUEST_URI']));
    exit;
}
