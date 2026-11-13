# Platform email delivery and webhooks

Campaigns, previews, and connection tests send through the platform Mailtrap account using `MAILTRAP_API_TOKEN` from `.env` (via `config/services.php`). Customers add and verify their sending domains. Customer API tokens, SMTP credentials, and webhook secrets are not used for campaign delivery. The settings API rejects new customer Mailtrap credentials.

Campaign requests use `https://bulk.api.mailtrap.io/api/send`. Mailtrap configures webhooks per stream and sending domain, so a Transactional webhook or a webhook limited to another domain will not report these campaign deliveries.

In the platform's Mailtrap account, configure the **Bulk** stream webhook for the customer sending domains, with the public HTTPS URL:

```text
https://YOUR_PUBLIC_BACKEND/api/webhooks/mailtrap
```

Copy that webhook's signing secret into `MAILTRAP_WEBHOOK_SECRET` in the backend `.env`. The signing secret is separate from the API token. JSON and JSON Lines payloads are supported. Existing `/api/webhook/mailtrap` and `/api/webhook/email/mailtrap` URLs use the same signed event handler.

With Laravel configuration caching enabled, reload configuration after updating `.env`:

```sh
php artisan config:cache
php artisan queue:restart
```

The database queue needs a running worker to send campaigns and process delivery events. `composer run dev` includes a worker. To run one separately:

```sh
php artisan queue:work --sleep=3 --tries=3
```

Production workers should be managed by the deployment's process manager and restarted when code changes. Keep the queue connection's `retry_after` longer than the longest job timeout (campaign batches currently allow 600 seconds).

An accepted send is marked `sent`. Signed provider callbacks update delivery, bounce, and other statuses. Exact provider message IDs and campaign metadata associate callbacks with the correct send; unrelated standalone test messages do not change campaign reports. Preview/test emails do not create campaign recipient records.

Regression tests use fake HTTP responses and in-memory SQLite; they do not send real emails or change application data:

```sh
php vendor/bin/phpunit tests/Feature/MailtrapCampaignWebhookTest.php
```
