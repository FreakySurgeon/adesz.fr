<?php
// Stripe configuration
// IMPORTANT: On production (OVH), replace with live keys manually
$stripe_secret_key = trim('sk_test_REPLACE_ME');
$stripe_mode = trim('test'); // 'test' or 'live'

// Site URL for redirects (include base path if any, e.g. '/test')
$site_url = 'https://adesz.fr';
$base_path = ''; // Set to '/test' for staging

// Stripe Webhook
$stripe_webhook_secret = trim('whsec_REPLACE_ME');

// Brevo (ex-Sendinblue) configuration
$brevo_api_key = trim('xkeysib-REPLACE_ME');
$brevo_list_adherents = (int)trim('LIST_ADHERENTS_REPLACE_ME');
$brevo_list_donateurs = (int)trim('LIST_DONATEURS_REPLACE_ME');
$brevo_list_tous = (int)trim('LIST_TOUS_REPLACE_ME');

// Admin notification email (for Brevo sync failures)
$admin_email = trim('admin@REPLACE_ME');
