<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            // General Settings
            ['key' => 'general.website_name', 'value' => 'THROB CRM', 'group' => 'general', 'type' => 'string'],
            ['key' => 'general.website_title', 'value' => 'THROB - Premium WhatsApp CRM SaaS Portal', 'group' => 'general', 'type' => 'string'],
            ['key' => 'general.website_description', 'value' => 'Advanced SaaS WhatsApp CRM with Campaign automation and inbox chat controls.', 'group' => 'general', 'type' => 'text'],
            ['key' => 'general.website_keywords', 'value' => 'whatsapp crm, saas, campaign management, customer relationship', 'group' => 'general', 'type' => 'string'],
            ['key' => 'general.website_logo', 'value' => null, 'group' => 'general', 'type' => 'file'],
            ['key' => 'general.website_favicon', 'value' => null, 'group' => 'general', 'type' => 'file'],
            ['key' => 'general.footer_copyright', 'value' => '© 2026 THROB Tech. All rights reserved.', 'group' => 'general', 'type' => 'string'],
            ['key' => 'general.website_language', 'value' => 'en', 'group' => 'general', 'type' => 'string'],
            ['key' => 'general.timezone', 'value' => 'UTC', 'group' => 'general', 'type' => 'string'],
            ['key' => 'general.date_format', 'value' => 'Y-m-d', 'group' => 'general', 'type' => 'string'],
            ['key' => 'general.currency_code', 'value' => 'USD', 'group' => 'general', 'type' => 'string'],
            ['key' => 'general.currency_symbol', 'value' => '$', 'group' => 'general', 'type' => 'string'],
            ['key' => 'general.maintenance_mode', 'value' => 'false', 'group' => 'general', 'type' => 'boolean'],

            // Contact Info
            ['key' => 'contact.company_name', 'value' => 'THROB Technologies Ltd.', 'group' => 'contact', 'type' => 'string'],
            ['key' => 'contact.contact_email', 'value' => 'contact@throbtech.com', 'group' => 'contact', 'type' => 'string'],
            ['key' => 'contact.support_email', 'value' => 'support@throbtech.com', 'group' => 'contact', 'type' => 'string'],
            ['key' => 'contact.phone_number', 'value' => '+1-555-123-4567', 'group' => 'contact', 'type' => 'string'],
            ['key' => 'contact.whatsapp_number', 'value' => '+1-555-987-6543', 'group' => 'contact', 'type' => 'string'],
            ['key' => 'contact.physical_address', 'value' => '123 Tech Corridor, Silicon Valley, CA, USA', 'group' => 'contact', 'type' => 'text'],
            ['key' => 'contact.google_maps_embed', 'value' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3168.639290620188!2d-122.0862784!3d37.4220656!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x808fba02425dad8f%3A0x6c296c66619367e0!2sGoogleplex!5e0!3m2!1sen!2sus!4v1655931200000!5m2!1sen!2sus', 'group' => 'text', 'type' => 'text'],

            // Social Media
            ['key' => 'social.facebook', 'value' => 'https://facebook.com/throbtech', 'group' => 'social', 'type' => 'string'],
            ['key' => 'social.instagram', 'value' => 'https://instagram.com/throbtech', 'group' => 'social', 'type' => 'string'],
            ['key' => 'social.linkedin', 'value' => 'https://linkedin.com/company/throbtech', 'group' => 'social', 'type' => 'string'],
            ['key' => 'social.twitter', 'value' => 'https://twitter.com/throbtech', 'group' => 'social', 'type' => 'string'],
            ['key' => 'social.youtube', 'value' => 'https://youtube.com/throbtech', 'group' => 'social', 'type' => 'string'],
            ['key' => 'social.tiktok', 'value' => 'https://tiktok.com/@throbtech', 'group' => 'social', 'type' => 'string'],

            // SMTP settings
            ['key' => 'email.smtp_host', 'value' => 'sandbox.smtp.mailtrap.io', 'group' => 'email', 'type' => 'string'],
            ['key' => 'email.smtp_port', 'value' => '2525', 'group' => 'email', 'type' => 'string'],
            ['key' => 'email.smtp_username', 'value' => '', 'group' => 'email', 'type' => 'string'],
            ['key' => 'email.smtp_password', 'value' => '', 'group' => 'email', 'type' => 'string'],
            ['key' => 'email.smtp_encryption', 'value' => 'tls', 'group' => 'email', 'type' => 'string'],
            ['key' => 'email.sender_name', 'value' => 'THROB System Alert', 'group' => 'email', 'type' => 'string'],
            ['key' => 'email.sender_email', 'value' => 'noreply@throbtech.com', 'group' => 'email', 'type' => 'string'],

            // Notifications
            ['key' => 'notification.email_enabled', 'value' => 'true', 'group' => 'notification', 'type' => 'boolean'],
            ['key' => 'notification.sms_enabled', 'value' => 'false', 'group' => 'notification', 'type' => 'boolean'],
            ['key' => 'notification.push_enabled', 'value' => 'false', 'group' => 'notification', 'type' => 'boolean'],
            ['key' => 'notification.whatsapp_enabled', 'value' => 'true', 'group' => 'notification', 'type' => 'boolean'],
            ['key' => 'notification.admin_alerts', 'value' => 'true', 'group' => 'notification', 'type' => 'boolean'],

            // Authentication settings
            ['key' => 'auth.registration_enabled', 'value' => 'true', 'group' => 'auth', 'type' => 'boolean'],
            ['key' => 'auth.email_verification_required', 'value' => 'false', 'group' => 'auth', 'type' => 'boolean'],
            ['key' => 'auth.phone_verification_required', 'value' => 'false', 'group' => 'auth', 'type' => 'boolean'],
            ['key' => 'auth.social_login_enabled', 'value' => 'false', 'group' => 'auth', 'type' => 'boolean'],
            ['key' => 'auth.password_min_length', 'value' => '8', 'group' => 'auth', 'type' => 'integer'],
            ['key' => 'auth.password_require_uppercase', 'value' => 'false', 'group' => 'auth', 'type' => 'boolean'],
            ['key' => 'auth.password_require_numbers', 'value' => 'false', 'group' => 'auth', 'type' => 'boolean'],
            ['key' => 'auth.session_timeout', 'value' => '120', 'group' => 'auth', 'type' => 'integer'],

            // Payment Gateways
            ['key' => 'payment.stripe_key', 'value' => '', 'group' => 'payment', 'type' => 'string'],
            ['key' => 'payment.stripe_secret', 'value' => '', 'group' => 'payment', 'type' => 'string'],
            ['key' => 'payment.paypal_client_id', 'value' => '', 'group' => 'payment', 'type' => 'string'],
            ['key' => 'payment.paypal_secret', 'value' => '', 'group' => 'payment', 'type' => 'string'],
            ['key' => 'payment.razorpay_key', 'value' => '', 'group' => 'payment', 'type' => 'string'],
            ['key' => 'payment.razorpay_secret', 'value' => '', 'group' => 'payment', 'type' => 'string'],
            ['key' => 'payment.jazzcash_merchant_id', 'value' => '', 'group' => 'payment', 'type' => 'string'],
            ['key' => 'payment.jazzcash_password', 'value' => '', 'group' => 'payment', 'type' => 'string'],
            ['key' => 'payment.easypaisa_store_id', 'value' => '', 'group' => 'payment', 'type' => 'string'],
            ['key' => 'payment.easypaisa_hash_key', 'value' => '', 'group' => 'payment', 'type' => 'string'],
            ['key' => 'payment.sandbox_mode', 'value' => 'true', 'group' => 'payment', 'type' => 'boolean'],

            // API Keys
            ['key' => 'api.google_maps_key', 'value' => '', 'group' => 'api', 'type' => 'string'],
            ['key' => 'api.google_client_id', 'value' => '', 'group' => 'api', 'type' => 'string'],
            ['key' => 'api.google_client_secret', 'value' => '', 'group' => 'api', 'type' => 'string'],
            ['key' => 'api.facebook_app_id', 'value' => '', 'group' => 'api', 'type' => 'string'],
            ['key' => 'api.facebook_app_secret', 'value' => '', 'group' => 'api', 'type' => 'string'],
            ['key' => 'api.whatsapp_business_id', 'value' => '', 'group' => 'api', 'type' => 'string'],
            ['key' => 'api.openai_api_key', 'value' => '', 'group' => 'api', 'type' => 'string'],

            // SEO config
            ['key' => 'seo.meta_title', 'value' => 'THROB - Intelligent WhatsApp CRM platform', 'group' => 'seo', 'type' => 'string'],
            ['key' => 'seo.meta_description', 'value' => 'Configurable CRM framework featuring automated marketing tools.', 'group' => 'seo', 'type' => 'text'],
            ['key' => 'seo.meta_keywords', 'value' => 'whatsapp marketing, meta app, customer support', 'group' => 'seo', 'type' => 'string'],
            ['key' => 'seo.og_image', 'value' => null, 'group' => 'seo', 'type' => 'file'],
            ['key' => 'seo.robots_txt', 'value' => "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /api", 'group' => 'seo', 'type' => 'text'],
            ['key' => 'seo.google_analytics_id', 'value' => '', 'group' => 'seo', 'type' => 'string'],
            ['key' => 'seo.google_tag_manager_id', 'value' => '', 'group' => 'seo', 'type' => 'string'],
            ['key' => 'seo.facebook_pixel_id', 'value' => '', 'group' => 'seo', 'type' => 'string'],

            // Security settings
            ['key' => 'security.recaptcha_site_key', 'value' => '', 'group' => 'security', 'type' => 'string'],
            ['key' => 'security.recaptcha_secret_key', 'value' => '', 'group' => 'security', 'type' => 'string'],
            ['key' => 'security.login_attempt_limit', 'value' => '5', 'group' => 'security', 'type' => 'integer'],
            ['key' => 'security.ip_whitelist', 'value' => '', 'group' => 'security', 'type' => 'text'],
            ['key' => 'security.two_factor_auth_enabled', 'value' => 'false', 'group' => 'security', 'type' => 'boolean'],
            ['key' => 'security.headers_csp', 'value' => '', 'group' => 'security', 'type' => 'text'],

            // Media uploads
            ['key' => 'media.max_upload_size', 'value' => '2048', 'group' => 'media', 'type' => 'integer'],
            ['key' => 'media.allowed_file_types', 'value' => 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx', 'group' => 'media', 'type' => 'string'],
            ['key' => 'media.image_compression', 'value' => '80', 'group' => 'media', 'type' => 'integer'],
            ['key' => 'media.storage_driver', 'value' => 'local', 'group' => 'media', 'type' => 'string'],

            // Appearance settings
            ['key' => 'appearance.theme_color', 'value' => '#6d4aff', 'group' => 'appearance', 'type' => 'string'],
            ['key' => 'appearance.dark_mode', 'value' => 'false', 'group' => 'appearance', 'type' => 'boolean'],
            ['key' => 'appearance.branding_title', 'value' => 'THROB SaaS Management', 'group' => 'appearance', 'type' => 'string'],
            ['key' => 'appearance.custom_css', 'value' => '/* Add custom CSS rules here */', 'group' => 'appearance', 'type' => 'text'],
            ['key' => 'appearance.custom_js', 'value' => '// Add custom JS scripts here', 'group' => 'appearance', 'type' => 'text'],

            // Database Backup
            ['key' => 'backup.db_backup_enabled', 'value' => 'true', 'group' => 'backup', 'type' => 'boolean'],
            ['key' => 'backup.scheduled_time', 'value' => '02:00', 'group' => 'backup', 'type' => 'string'],

            // Language Configurations
            ['key' => 'language.default', 'value' => 'en', 'group' => 'language', 'type' => 'string'],
            ['key' => 'language.available', 'value' => 'en,es,fr,ur', 'group' => 'language', 'type' => 'string'],

            // Advanced settings
            ['key' => 'advanced.cron_config', 'value' => '* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1', 'group' => 'advanced', 'type' => 'string'],
            ['key' => 'advanced.queue_driver', 'value' => 'database', 'group' => 'advanced', 'type' => 'string'],
            ['key' => 'advanced.feature_flag_ai_copilot', 'value' => 'true', 'group' => 'advanced', 'type' => 'boolean'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
