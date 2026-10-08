<?php

namespace App\Services\Email\Providers;

use App\Services\Email\Contracts\EmailProviderInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Illuminate\Support\Str;
use Throwable;

class SmtpEmailProvider implements EmailProviderInterface
{
    protected ?EsmtpTransport $transport = null;
    protected ?Mailer $mailer = null;

    public function __construct(
        protected ?string $host,
        protected int $port = 587,
        protected ?string $encryption = 'tls',
        protected ?string $username = null,
        protected ?string $password = null,
        protected int $timeout = 15
    ) {
        if (!empty($this->host)) {
            $isTls = strtolower((string)$this->encryption) === 'tls' || strtolower((string)$this->encryption) === 'ssl';
            $this->transport = new EsmtpTransport($this->host, $this->port, $isTls);
            
            if (!empty($this->username)) {
                $this->transport->setUsername($this->username);
            }
            if (!empty($this->password)) {
                $this->transport->setPassword($this->password);
            }
            
            $this->mailer = new Mailer($this->transport);
        }
    }

    public function send(array $message): array
    {
        if (!$this->mailer) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'SMTP host is not configured. Please check your Email Settings.',
            ];
        }

        try {
            $email = new Email();
            
            // From
            $fromEmail = trim($message['from_email'] ?? '');
            $fromName = trim($message['from_name'] ?? '');
            $email->from(new Address($fromEmail, $fromName));

            // To
            $toEmail = trim($message['to_email'] ?? '');
            $toName = trim($message['to_name'] ?? '');
            $email->to(new Address($toEmail, $toName));

            // Reply-To
            if (!empty($message['reply_to'])) {
                $email->replyTo(new Address(trim($message['reply_to'])));
            }

            // Subject & Bodies
            $email->subject($message['subject'] ?? '(No Subject)');
            
            if (!empty($message['html_body'])) {
                $email->html($message['html_body']);
            }
            
            if (!empty($message['text_body'])) {
                $email->text($message['text_body']);
            } elseif (!empty($message['html_body'])) {
                $email->text(strip_tags($message['html_body']));
            }

            // Custom Headers if any
            if (!empty($message['headers']) && is_array($message['headers'])) {
                $headers = $email->getHeaders();
                foreach ($message['headers'] as $hKey => $hVal) {
                    $headers->addTextHeader($hKey, (string)$hVal);
                }
            }

            // Assign a unique client message ID header
            $customMessageId = 'smtp_' . Str::uuid()->toString() . '@' . ($this->host ?: 'app.local');
            $email->getHeaders()->addIdHeader('Message-ID', $customMessageId);

            $this->mailer->send($email);

            return [
                'success' => true,
                'message_id' => $customMessageId,
                'error' => null,
            ];
        } catch (TransportExceptionInterface $e) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'SMTP Transport Error: ' . $e->getMessage(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Email Send Error: ' . $e->getMessage(),
            ];
        }
    }

    public function sendBatch(array $messages): array
    {
        $results = [];
        foreach ($messages as $msg) {
            $results[] = $this->send($msg);
        }
        return $results;
    }

    public function getStatus(string $messageId): array
    {
        return [
            'status' => 'sent',
            'details' => 'SMTP standard delivery handed over to MTA',
        ];
    }

    public function handleWebhook(array $payload, array $headers = []): ?array
    {
        // Standard SMTP does not provide direct webhooks
        return null;
    }
}
