<?php
// Stripe configuration
// IMPORTANT: On production (OVH), replace with live keys manually
$stripe_secret_key = 'sk_test_REPLACE_ME';
$stripe_mode = 'test'; // 'test' or 'live'

// Site URL for redirects (include base path if any, e.g. '/test')
$site_url = 'https://adesz.fr';
$base_path = ''; // Set to '/test' for staging

// Stripe Webhook
$stripe_webhook_secret = 'whsec_REPLACE_ME';

// Brevo (ex-Sendinblue) configuration
$brevo_api_key = 'xkeysib-REPLACE_ME';
$brevo_list_adherents = 0; // ID liste Brevo "Adhérents"
$brevo_list_donateurs = 0; // ID liste Brevo "Donateurs"
$brevo_list_tous = 0;      // ID liste Brevo "Tous"

// Admin notification email (for Brevo sync failures)
$admin_email = 'admin@REPLACE_ME';
