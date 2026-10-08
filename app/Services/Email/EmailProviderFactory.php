<?php

namespace App\Services\Email;

use App\Models\EmailSetting;
use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Email\Providers\LogEmailProvider;
use App\Services\Email\Providers\SendGridEmailProvider;
use App\Services\Email\Providers\SmtpEmailProvider;

class EmailProviderFactory
{
    /**
     * Resolve the appropriate EmailProviderInterface for a tenant or setting
     */
    public static function make(?EmailSetting $setting = null): EmailProviderInterface
    {
        if ($setting && $setting->is_active) {
            $provider = strtolower((string)$setting->provider);

            if ($provider === 'mailtrap') {
                return new \App\Services\Email\Providers\MailtrapEmailProvider(
                    apiToken: $setting->mailtrap_api_token ?: $setting->api_key,
                    webhookSecret: $setting->mailtrap_webhook_secret,
                    sendingDomain: $setting->mailtrap_domain ?: $setting->api_domain
                );
            }

            if ($provider === 'smtp' && !empty($setting->smtp_host)) {
                return new SmtpEmailProvider(
                    host: $setting->smtp_host,
                    port: (int) ($setting->smtp_port ?: 587),
                    encryption: $setting->smtp_encryption ?: 'tls',
                    username: $setting->smtp_username,
                    password: $setting->smtp_password,
                );
            }

            if ($provider === 'sendgrid' && !empty($setting->api_key)) {
                return new SendGridEmailProvider(
                    apiKey: $setting->api_key
                );
            }

            if ($provider === 'log') {
                return new LogEmailProvider();
            }
        }

        // Fallback to Mailtrap if environment has MAILTRAP_API_TOKEN configured
        if (!empty(env('MAILTRAP_API_TOKEN'))) {
            return new \App\Services\Email\Providers\MailtrapEmailProvider();
        }

        // Fallback to system env if configured
        $envHost = config('mail.mailers.smtp.host');
        if (!empty($envHost) && $envHost !== '127.0.0.1' && config('mail.default') === 'smtp') {
            return new SmtpEmailProvider(
                host: $envHost,
                port: (int) (config('mail.mailers.smtp.port') ?: 587),
                encryption: config('mail.mailers.smtp.encryption') ?: 'tls',
                username: config('mail.mailers.smtp.username'),
                password: config('mail.mailers.smtp.password'),
            );
        }

        // Safe default: log provider for dev/testing
        return new LogEmailProvider();
    }
}
