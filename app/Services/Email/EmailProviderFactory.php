<?php

namespace App\Services\Email;

use App\Models\EmailSetting;
use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Email\Providers\MailtrapEmailProvider;

class EmailProviderFactory
{
    /**
     * All campaign email is billed through the platform Mailtrap account.
     */
    public static function make(?EmailSetting $setting = null): EmailProviderInterface
    {
        return new MailtrapEmailProvider(
            apiToken: config('services.mailtrap.api_token'),
            webhookSecret: config('services.mailtrap.webhook_secret'),
            sendingDomain: $setting?->mailtrap_domain ?: config('services.mailtrap.domain')
        );
    }
}
