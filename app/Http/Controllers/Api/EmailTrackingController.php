<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSuppression;
use App\Services\Email\EmailProviderFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class EmailTrackingController extends Controller
{
    /**
     * Public unsubscribe handler
     */
    public function unsubscribe(Request $request): Response
    {
        $campaignId = (int)$request->query('cid');
        $email = strtolower(trim((string)$request->query('email')));
        $token = (string)$request->query('token');

        if (empty($email) || empty($campaignId)) {
            return response(
                $this->renderUnsubscribePage('Invalid Request', 'The unsubscribe link appears to be malformed or incomplete.', false),
                400
            );
        }

        // Verify token authenticity
        $expectedToken = hash_hmac('sha256', $email . '|' . $campaignId, config('app.key', 'secret'));
        if (!hash_equals($expectedToken, $token)) {
            return response(
                $this->renderUnsubscribePage('Verification Failed', 'Could not verify the unsubscribe request authenticity.', false),
                403
            );
        }

        $campaign = EmailCampaign::find($campaignId);
        if (!$campaign) {
            return response(
                $this->renderUnsubscribePage('Campaign Not Found', 'This campaign is no longer active.', false),
                404
            );
        }

        // 1. Add to global suppressions
        EmailSuppression::updateOrCreate(
            [
                'tenant_id' => $campaign->tenant_id,
                'email' => $email,
            ],
            [
                'reason' => EmailSuppression::REASON_UNSUBSCRIBED,
                'details' => 'Unsubscribed via email campaign link.',
            ]
        );

        // 2. Update recipient status if present
        $recipient = EmailCampaignRecipient::where('campaign_id', $campaignId)
            ->where('email', $email)
            ->first();

        if ($recipient) {
            $recipient->update(['status' => EmailCampaignRecipient::STATUS_UNSUBSCRIBED]);
            $campaign->recalculateMetrics();
        }

        return response(
            $this->renderUnsubscribePage(
                'Unsubscribed Successfully',
                "Your email ({$email}) has been removed from our marketing mailing lists and you will not receive further promotional emails.",
                true
            ),
            200
        );
    }

    /**
     * Webhook handler for email provider status updates
     */
    public function webhook(Request $request, string $provider)
    {
        Log::info("[EmailWebhook] Received event from {$provider}", ['payload' => $request->all()]);

        $providerInstance = EmailProviderFactory::make(null);
        $eventData = $providerInstance->handleWebhook($request->all(), $request->headers->all());

        if (!$eventData) {
            return response()->json(['status' => 'ignored']);
        }

        $msgId = $eventData['message_id'] ?? null;
        $event = $eventData['event'] ?? 'unknown';

        if ($msgId) {
            $recipient = EmailCampaignRecipient::where('provider_message_id', $msgId)->first();
            if ($recipient) {
                if ($event === 'delivered') {
                    $recipient->update([
                        'status' => EmailCampaignRecipient::STATUS_DELIVERED,
                        'delivered_at' => now(),
                    ]);
                } elseif ($event === 'bounced') {
                    $recipient->update([
                        'status' => EmailCampaignRecipient::STATUS_BOUNCED,
                        'failed_at' => now(),
                        'error_message' => $eventData['reason'] ?? 'Bounced',
                    ]);

                    // Add to suppressions
                    EmailSuppression::updateOrCreate(
                        ['tenant_id' => $recipient->tenant_id, 'email' => $recipient->email],
                        ['reason' => EmailSuppression::REASON_HARD_BOUNCE, 'details' => 'Hard bounce reported by provider']
                    );
                } elseif ($event === 'failed') {
                    $recipient->update([
                        'status' => EmailCampaignRecipient::STATUS_FAILED,
                        'failed_at' => now(),
                        'error_message' => $eventData['reason'] ?? 'Failed',
                    ]);
                } elseif ($event === 'unsubscribed' || $event === 'spam_complaint') {
                    $recipient->update([
                        'status' => EmailCampaignRecipient::STATUS_UNSUBSCRIBED,
                    ]);

                    EmailSuppression::updateOrCreate(
                        ['tenant_id' => $recipient->tenant_id, 'email' => $recipient->email],
                        ['reason' => $event === 'spam_complaint' ? EmailSuppression::REASON_SPAM_COMPLAINT : EmailSuppression::REASON_UNSUBSCRIBED]
                    );
                }

                $recipient->campaign?->recalculateMetrics();
            }
        }

        return response()->json(['status' => 'processed']);
    }

    /**
     * Render a clean unsubscribe HTML confirmation page
     */
    protected function renderUnsubscribePage(string $title, string $message, bool $isSuccess): string
    {
        $iconSvg = $isSuccess
            ? '<svg style="width:48px;height:48px;color:#10b981;margin:0 auto 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>'
            : '<svg style="width:48px;height:48px;color:#ef4444;margin:0 auto 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 40px 16px; display: flex; align-items: center; justify-content: center; min-height: 80vh; }
        .card { background: #ffffff; max-width: 480px; width: 100%; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01); border: 1px solid #e2e8f0; padding: 40px; text-align: center; }
        h1 { font-size: 22px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
        p { font-size: 14px; color: #64748b; line-height: 1.6; margin-bottom: 24px; }
        .brand { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        {$iconSvg}
        <h1>{$title}</h1>
        <p>{$message}</p>
        <div class="brand">Marketing Subscription Preferences</div>
    </div>
</body>
</html>
HTML;
    }
}
