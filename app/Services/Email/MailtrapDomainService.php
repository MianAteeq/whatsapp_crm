<?php

namespace App\Services\Email;

use App\Models\EmailDomainAuditLog;
use App\Models\EmailSendingDomain;
use App\Models\EmailSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use InvalidArgumentException;

class MailtrapDomainService
{
    protected string $apiBase = 'https://mailtrap.io/api';

    /**
     * Resolve the Mailtrap API token for the tenant, falling back to system config.
     */
    public function resolveApiToken(?int $tenantId = null): string
    {
        if ($tenantId) {
            $setting = EmailSetting::where('tenant_id', $tenantId)->first();
            if ($setting && !empty($setting->mailtrap_api_token)) {
                return $setting->mailtrap_api_token;
            }
        }

        $globalToken = config('services.mailtrap.api_token') ?: env('MAILTRAP_API_TOKEN');
        if (!empty($globalToken)) {
            return $globalToken;
        }

        // Fallback: Check if any other tenant configured a mailtrap token
        $anySetting = EmailSetting::whereNotNull('mailtrap_api_token')
            ->where('mailtrap_api_token', '!=', '')
            ->first();
        if ($anySetting) {
            return $anySetting->mailtrap_api_token;
        }

        throw new RuntimeException('Mailtrap API Token is not configured. Please add it in Email Settings.');
    }

    /**
     * Resolve the Mailtrap Account ID for the given API token.
     */
    public function resolveAccountId(string $apiToken, ?int $tenantId = null): int|string
    {
        $cacheKey = 'mailtrap_account_id_' . substr(hash('sha256', $apiToken), 0, 16);
        return Cache::remember($cacheKey, 3600 * 24, function () use ($apiToken) {
            $response = Http::withHeaders([
                'Api-Token' => $apiToken,
                'Accept' => 'application/json',
            ])->timeout(15)->get("{$this->apiBase}/accounts");

            if ($response->successful()) {
                $accounts = $response->json();
                if (!empty($accounts) && is_array($accounts)) {
                    $configuredAccountId = config('services.mailtrap.account_id') ?: env('MAILTRAP_ACCOUNT_ID');
                    
                    // If configured account ID exists in token's accessible accounts, use it
                    if (!empty($configuredAccountId)) {
                        foreach ($accounts as $acc) {
                            if ((string)($acc['id'] ?? '') === (string)$configuredAccountId) {
                                return $acc['id'];
                            }
                        }
                    }

                    // Otherwise use the first accessible account for this token
                    if (isset($accounts[0]['id'])) {
                        return $accounts[0]['id'];
                    }
                }
            }

            // Fallback to configured account ID if present
            $configuredAccountId = config('services.mailtrap.account_id') ?: env('MAILTRAP_ACCOUNT_ID');
            if (!empty($configuredAccountId)) {
                return $configuredAccountId;
            }

            throw new RuntimeException('Could not authenticate with Mailtrap API to locate Account ID: ' . ($response->body() ?: 'No accounts found for token.'));
        });
    }

    /**
     * Register a new sending domain with Mailtrap and save locally as pending.
     */
    public function createDomain(int $tenantId, string $domain, ?int $userId = null): EmailSendingDomain
    {
        $normalizedDomain = EmailSendingDomain::normalizeDomain($domain);

        // 1. Validate syntax
        if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $normalizedDomain)) {
            throw new InvalidArgumentException("Invalid domain format: '{$domain}'. Please provide a valid root domain or subdomain (e.g. example.com or mail.example.com).");
        }

        if (in_array($normalizedDomain, ['localhost', 'example.com', 'test.com', 'mail.local'])) {
            throw new InvalidArgumentException("Domain '{$normalizedDomain}' cannot be used as a public sending domain.");
        }

        // 2. Check if tenant already owns it
        $existing = EmailSendingDomain::where('tenant_id', $tenantId)
            ->where('domain', $normalizedDomain)
            ->first();

        if ($existing) {
            throw new InvalidArgumentException("Domain '{$normalizedDomain}' has already been added to your account.");
        }

        $apiToken = $this->resolveApiToken($tenantId);
        $accountId = $this->resolveAccountId($apiToken, $tenantId);

        // 3. Create or find domain in Mailtrap
        $createResponse = Http::withHeaders([
            'Api-Token' => $apiToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(20)->post("{$this->apiBase}/accounts/{$accountId}/sending_domains", [
            'sending_domain' => [
                'domain_name' => $normalizedDomain,
            ],
        ]);

        $mailtrapData = null;

        if ($createResponse->successful()) {
            $mailtrapData = $createResponse->json();
        } elseif ($createResponse->status() === 422 || $createResponse->status() === 400) {
            // Check if domain was already registered on this Mailtrap account
            $listResponse = Http::withHeaders([
                'Api-Token' => $apiToken,
                'Accept' => 'application/json',
            ])->timeout(15)->get("{$this->apiBase}/accounts/{$accountId}/sending_domains");

            if ($listResponse->successful()) {
                $rawList = $listResponse->json();
                $domains = $rawList['data'] ?? (is_array($rawList) ? $rawList : []);
                foreach ($domains as $d) {
                    if (strcasecmp($d['domain_name'] ?? ($d['name'] ?? ''), $normalizedDomain) === 0) {
                        $mailtrapData = $d;
                        break;
                    }
                }
            }

            if (!$mailtrapData) {
                $err = $createResponse->json('error') ?? $createResponse->json('errors') ?? $createResponse->body();
                throw new RuntimeException("Mailtrap rejected domain creation: " . (is_array($err) ? json_encode($err) : $err));
            }
        } else {
            throw new RuntimeException("Mailtrap API error ({$createResponse->status()}): " . $createResponse->body());
        }

        $mailtrapDomainId = (string) ($mailtrapData['id'] ?? '');
        $dnsRecords = $this->formatDnsRecords($mailtrapData['dns_records'] ?? [], $normalizedDomain);

        // 4. Save to database
        $domainRecord = EmailSendingDomain::create([
            'tenant_id' => $tenantId,
            'domain' => $normalizedDomain,
            'status' => ($mailtrapData['dns_verified'] ?? false) ? EmailSendingDomain::STATUS_VERIFIED : EmailSendingDomain::STATUS_PENDING,
            'mailtrap_domain_id' => $mailtrapDomainId,
            'mailtrap_domain_name' => $mailtrapData['domain_name'] ?? $normalizedDomain,
            'spf_status' => $this->detectRecordStatus($dnsRecords, 'spf'),
            'dkim_status' => $this->detectRecordStatus($dnsRecords, 'dkim'),
            'dmarc_status' => $this->detectRecordStatus($dnsRecords, 'dmarc'),
            'dns_records' => $dnsRecords,
            'verified_at' => ($mailtrapData['dns_verified'] ?? false) ? now() : null,
            'last_checked_at' => now(),
            'verification_error' => null,
        ]);

        // 5. Audit Log
        EmailDomainAuditLog::record(
            $tenantId,
            $userId,
            $domainRecord->id,
            'domain_added',
            null,
            $domainRecord->status,
            ['domain' => $normalizedDomain, 'mailtrap_domain_id' => $mailtrapDomainId]
        );

        EmailDomainAuditLog::record(
            $tenantId,
            $userId,
            $domainRecord->id,
            'dns_records_generated',
            $domainRecord->status,
            $domainRecord->status,
            ['records_count' => count($dnsRecords)]
        );

        return $domainRecord;
    }

    /**
     * Check Mailtrap for the domain's current DNS verification status.
     */
    public function verifyDomain(EmailSendingDomain $domainRecord, ?int $userId = null): array
    {
        $tenantId = $domainRecord->tenant_id;
        $apiToken = $this->resolveApiToken($tenantId);
        $accountId = $this->resolveAccountId($apiToken, $tenantId);
        $mailtrapDomainId = $domainRecord->mailtrap_domain_id;

        if (empty($mailtrapDomainId)) {
            throw new RuntimeException("Domain record does not have a linked Mailtrap Domain ID.");
        }

        // 1. Fetch domain status from Mailtrap
        $response = Http::withHeaders([
            'Api-Token' => $apiToken,
            'Accept' => 'application/json',
        ])->timeout(20)->get("{$this->apiBase}/accounts/{$accountId}/sending_domains/{$mailtrapDomainId}");

        if (!$response->successful()) {
            $err = "Mailtrap check failed with HTTP {$response->status()}: " . $response->body();
            Log::warning("[MailtrapDomainService] {$err}");
            
            $domainRecord->update([
                'last_checked_at' => now(),
                'verification_error' => 'Could not communicate with Mailtrap API to verify DNS records.',
            ]);

            EmailDomainAuditLog::record(
                $tenantId,
                $userId,
                $domainRecord->id,
                'verification_failed',
                $domainRecord->status,
                $domainRecord->status,
                ['error' => $err]
            );

            return [
                'success' => false,
                'status' => $domainRecord->status,
                'message' => 'Unable to check verification status with Mailtrap at this time. Please retry in a few moments.',
                'domain' => $domainRecord,
            ];
        }

        $data = $response->json();
        $dnsVerified = (bool) ($data['dns_verified'] ?? false);
        $dnsRecords = $this->formatDnsRecords($data['dns_records'] ?? [], $domainRecord->domain);

        $spfStatus = $this->detectRecordStatus($dnsRecords, 'spf');
        $dkimStatus = $this->detectRecordStatus($dnsRecords, 'dkim');
        $dmarcStatus = $this->detectRecordStatus($dnsRecords, 'dmarc');

        $oldStatus = $domainRecord->status;
        $newStatus = EmailSendingDomain::STATUS_PENDING;

        if ($dnsVerified) {
            $newStatus = EmailSendingDomain::STATUS_VERIFIED;
        } elseif (($data['status'] ?? '') === 'rejected' || ($data['compliance_status'] ?? '') === 'rejected') {
            $newStatus = EmailSendingDomain::STATUS_REJECTED;
        } elseif (($data['status'] ?? '') === 'failed') {
            $newStatus = EmailSendingDomain::STATUS_FAILED;
        }

        $verifiedAt = $newStatus === EmailSendingDomain::STATUS_VERIFIED ? ($domainRecord->verified_at ?: now()) : null;
        $verificationError = null;

        if ($newStatus === EmailSendingDomain::STATUS_PENDING) {
            $unverified = array_filter($dnsRecords, fn($r) => in_array($r['status'] ?? '', ['fail', 'unchecked', 'pending']));
            if (!empty($unverified)) {
                $names = implode(', ', array_column($unverified, 'record_type'));
                $verificationError = "DNS records for {$names} are still propagating or not found. Please verify records at your DNS registrar.";
            }
        } elseif ($newStatus === EmailSendingDomain::STATUS_VERIFIED) {
            $complianceStatus = $data['compliance_status'] ?? null;
            if (in_array($complianceStatus, ['missing_company_info', 'under_review', 'pending_approval'])) {
                $verificationError = "DNS records verified! Mailtrap compliance action required: please log in to mailtrap.io → Sending Domains → open '{$domainRecord->domain}' to complete your company details.";
            }
        } elseif ($newStatus === EmailSendingDomain::STATUS_REJECTED) {
            $verificationError = "Mailtrap has rejected this domain. Please review Mailtrap compliance guidelines.";
        }

        $domainRecord->update([
            'status' => $newStatus,
            'spf_status' => $spfStatus,
            'dkim_status' => $dkimStatus,
            'dmarc_status' => $dmarcStatus,
            'dns_records' => $dnsRecords,
            'verified_at' => $verifiedAt,
            'last_checked_at' => now(),
            'verification_error' => $verificationError,
        ]);

        // Audit Log
        $event = match ($newStatus) {
            EmailSendingDomain::STATUS_VERIFIED => 'verification_succeeded',
            EmailSendingDomain::STATUS_REJECTED => 'domain_rejected',
            default => 'verification_requested',
        };

        EmailDomainAuditLog::record(
            $tenantId,
            $userId,
            $domainRecord->id,
            $event,
            $oldStatus,
            $newStatus,
            [
                'dns_verified' => $dnsVerified,
                'spf' => $spfStatus,
                'dkim' => $dkimStatus,
                'dmarc' => $dmarcStatus,
            ]
        );

        return [
            'success' => true,
            'status' => $newStatus,
            'dns_verified' => $dnsVerified,
            'message' => $newStatus === EmailSendingDomain::STATUS_VERIFIED
                ? "Domain '{$domainRecord->domain}' is successfully verified with Mailtrap!"
                : "Domain DNS records are still pending verification. Please allow up to 24 hours for DNS propagation.",
            'domain' => $domainRecord->fresh(),
        ];
    }

    /**
     * Delete domain from local database and optionally from Mailtrap.
     */
    public function deleteDomain(EmailSendingDomain $domainRecord, ?int $userId = null): void
    {
        $tenantId = $domainRecord->tenant_id;
        $domainId = $domainRecord->id;
        $mailtrapDomainId = $domainRecord->mailtrap_domain_id;

        // Try to delete from Mailtrap
        if (!empty($mailtrapDomainId)) {
            try {
                $apiToken = $this->resolveApiToken($tenantId);
                $accountId = $this->resolveAccountId($apiToken, $tenantId);

                Http::withHeaders([
                    'Api-Token' => $apiToken,
                    'Accept' => 'application/json',
                ])->timeout(10)->delete("{$this->apiBase}/accounts/{$accountId}/sending_domains/{$mailtrapDomainId}");
            } catch (\Throwable $e) {
                Log::warning("[MailtrapDomainService] Could not remove domain from Mailtrap: " . $e->getMessage());
            }
        }

        EmailDomainAuditLog::record(
            $tenantId,
            $userId,
            $domainId,
            'domain_disabled',
            $domainRecord->status,
            'deleted',
            ['domain' => $domainRecord->domain]
        );

        $domainRecord->delete();
    }

    /**
     * HARD SECURITY RULE: Validate that From email domain is verified and owned by the tenant.
     *
     * @return array ['allowed' => bool, 'message' => string, 'domain' => EmailSendingDomain|null]
     */
    public function validateDomainForSending(int $tenantId, string $fromEmail, ?int $sendingDomainId = null): array
    {
        $fromEmail = trim($fromEmail);
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'allowed' => false,
                'message' => "The From email address '{$fromEmail}' is invalid.",
                'domain' => null,
            ];
        }

        $parts = explode('@', $fromEmail);
        if (count($parts) !== 2) {
            return [
                'allowed' => false,
                'message' => "Invalid From email format.",
                'domain' => null,
            ];
        }

        $fromDomain = EmailSendingDomain::normalizeDomain($parts[1]);

        // 1. If explicit sending_domain_id is given, ensure it exists and matches tenant
        if ($sendingDomainId) {
            $domainRecord = EmailSendingDomain::where('id', $sendingDomainId)
                ->where('tenant_id', $tenantId)
                ->first();

            if (!$domainRecord) {
                return [
                    'allowed' => false,
                    'message' => "Unauthorized: The selected sending domain does not belong to this account.",
                    'domain' => null,
                ];
            }

            // Must match the From email domain
            if ($domainRecord->domain !== $fromDomain) {
                return [
                    'allowed' => false,
                    'message' => "Domain mismatch: From email '{$fromEmail}' uses domain '{$fromDomain}', but campaign specifies '{$domainRecord->domain}'.",
                    'domain' => null,
                ];
            }
        } else {
            // Locate domain by From email domain
            $domainRecord = EmailSendingDomain::where('tenant_id', $tenantId)
                ->where('domain', $fromDomain)
                ->first();
        }

        // 2. Check if domain exists in tenant's domain list
        if (!$domainRecord) {
            return [
                'allowed' => false,
                'message' => "The From email domain '{$fromDomain}' is not registered for this account. Before sending emails, you must add and verify this domain in Settings → Email → Sending Domains.",
                'domain' => null,
            ];
        }

        // 3. Confirm local status is verified
        if ($domainRecord->status !== EmailSendingDomain::STATUS_VERIFIED) {
            return [
                'allowed' => false,
                'message' => "The sending domain '{$fromDomain}' is not verified (current status: {$domainRecord->status}). Only verified sending domains are permitted to dispatch emails.",
                'domain' => $domainRecord,
            ];
        }

        return [
            'allowed' => true,
            'message' => 'Sending domain verified and authorized.',
            'domain' => $domainRecord,
        ];
    }

    /**
     * Standardize Mailtrap DNS records array into clean, presentation-ready items.
     */
    protected function formatDnsRecords(array $records, string $rootDomain): array
    {
        $formatted = [];

        foreach ($records as $r) {
            $type = strtoupper($r['record_type'] ?? $r['type'] ?? 'TXT');
            $name = $r['name'] ?? $r['host'] ?? '';
            $domain = $r['domain'] ?? '';
            $value = $r['value'] ?? $r['target'] ?? '';
            $rawStatus = strtolower($r['status'] ?? 'pending');
            $key = strtolower($r['key'] ?? '');

            // Determine purpose / description
            $purpose = 'Domain Authentication';
            if ($key === 'verification') {
                $purpose = 'SPF / Return-Path';
            } elseif (str_starts_with($key, 'dkim') || str_contains($name, 'domainkey') || str_contains($value, 'domainkey')) {
                $purpose = 'DKIM Authentication';
            } elseif ($key === 'dmarc' || str_contains($name, '_dmarc') || str_contains($value, 'DMARC1')) {
                $purpose = 'DMARC Policy';
            } elseif ($key === 'link_verification' || str_contains($name, 'track') || str_contains($name, 'link')) {
                $purpose = 'Tracking Domain';
            } elseif (str_contains($value, 'spf') || str_contains($name, 'mailtrap')) {
                $purpose = 'SPF Validation';
            }

            $isVerified = in_array($rawStatus, ['pass', 'verified', 'ok', 'valid']);
            $isFailed = in_array($rawStatus, ['fail', 'failed']);

            $formatted[] = [
                'record_type' => $type,
                'name' => $name ?: ($domain ?: $rootDomain),
                'domain' => $domain ?: $rootDomain,
                'value' => $value,
                'purpose' => $purpose,
                'status' => $isVerified ? 'verified' : ($isFailed ? 'failed' : 'pending'),
            ];
        }

        return $formatted;
    }

    /**
     * Detect status for SPF, DKIM, or DMARC from formatted DNS records.
     */
    protected function detectRecordStatus(array $dnsRecords, string $type): string
    {
        $hasMatch = false;
        $allPassed = true;

        foreach ($dnsRecords as $r) {
            $purpose = strtolower($r['purpose'] ?? '');
            $name = strtolower($r['name'] ?? '');
            $val = strtolower($r['value'] ?? '');

            $matches = false;
            if ($type === 'spf' && (str_contains($purpose, 'spf') || str_contains($val, 'v=spf1') || str_contains($val, 'smtp.mailtrap'))) {
                $matches = true;
            } elseif ($type === 'dkim' && (str_contains($purpose, 'dkim') || str_contains($name, 'domainkey') || str_contains($val, 'dkim'))) {
                $matches = true;
            } elseif ($type === 'dmarc' && (str_contains($purpose, 'dmarc') || str_contains($name, '_dmarc') || str_contains($val, 'dmarc1'))) {
                $matches = true;
            }

            if ($matches) {
                $hasMatch = true;
                if (($r['status'] ?? '') !== 'verified') {
                    $allPassed = false;
                }
            }
        }

        if (!$hasMatch) {
            return 'pending';
        }

        return $allPassed ? 'verified' : 'pending';
    }
}
