<?php

namespace App\Support;

use App\Exceptions\AssociationMailNotSent;
use App\Exceptions\AssociationMailUncertain;
use App\Exceptions\BillingReminderNotSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;

final class AssociationMailer
{
    /** @return array<string, mixed>|null */
    public static function configuration(string $association): ?array
    {
        $encrypted = DB::table('association_mail_settings')->where('association_id', $association)->value('encrypted_configuration');
        if (! $encrypted) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new AssociationMailNotSent('Saved email configuration could not be decrypted. Ask the technical administrator to verify the application key and resave the configuration.');
        }
    }

    /** @return array<string, mixed> */
    public static function publicConfiguration(string $association): array
    {
        $data = self::configuration($association) ?? ['mode' => 'managed', 'sender_email' => config('mail.from.address'), 'sender_name' => '', 'reply_to' => '', 'host' => '', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => ''];
        $hasPassword = ! empty($data['password']);
        $data = array_intersect_key($data, array_flip(['mode', 'sender_email', 'sender_name', 'reply_to', 'host', 'port', 'encryption', 'username', 'provider_reviewed']));
        $data['has_password'] = $hasPassword;
        $row = DB::table('association_mail_settings')->where('association_id', $association)->first(['status', 'last_error', 'tested_at']);

        return [...$data, 'allowed_ports' => config('association-mail.smtp_ports'), 'status' => $row->status ?? 'not_configured', 'last_error' => $row?->last_error, 'tested_at' => $row?->tested_at];
    }

    public static function validatedAddress(string $host, int $port): string
    {
        if (! in_array($port, config('association-mail.smtp_ports', []), true) || ! preg_match('/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/i', $host)) {
            throw new AssociationMailNotSent('SMTP destination is outside the administrator-approved host/port policy.');
        }
        $records = filter_var($host, FILTER_VALIDATE_IP) ? [['ip' => $host]] : @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($address) {
                $blocked = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '::/128', '::1/128', '::ffff:0:0/96', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32'];
                $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && ! IpUtils::checkIp($address, $blocked);
                $explicit = IpUtils::checkIp($address, config('association-mail.private_smtp_cidrs', []));
                if (! $public && ! $explicit) {
                    throw new AssociationMailNotSent('SMTP destination resolves to a restricted network. Only a deployment administrator may approve a self-hosted exception.');
                }
                $addresses[] = $address;
            }
        }
        if (! $addresses) {
            throw new AssociationMailNotSent('SMTP destination could not be resolved. Verify the provider configuration.');
        }

        // Pin the checked address; do not resolve the hostname again while connecting.
        return $addresses[0];
    }

    public static function send(string $association, string $recipient, Mailable $message): void
    {
        $configuration = self::configuration($association);
        if (! $configuration) {
            try {
                $pending = Mail::to($recipient);
            } catch (\Throwable) {
                throw new AssociationMailNotSent('Email delivery failed. Retry delivery.');
            }
            try {
                $pending->send($message);
            } catch (AssociationMailNotSent|BillingReminderNotSent $error) {
                throw $error;
            } catch (\Throwable) {
                throw new AssociationMailUncertain('Delivery outcome is uncertain. Review provider evidence before another send.');
            }

            return;
        }
        self::checkIdentity($association, $configuration);
        if ($configuration['mode'] === 'smtp' && empty($configuration['password'])) {
            throw new AssociationMailNotSent('SMTP password is absent. Save credentials before sending.');
        }
        $identity = array_intersect_key($configuration, array_flip(['sender_email', 'sender_name', 'reply_to']));
        $message->withSymfonyMessage(function ($email) use ($identity) {
            $email->from(new Address($identity['sender_email'], $identity['sender_name']));
            if ($identity['reply_to']) {
                $email->replyTo($identity['reply_to']);
            }
        });
        // Never open a network connection when a workflow test installed the mail fake.
        if (Mail::getFacadeRoot() instanceof MailFake) {
            Mail::to($recipient)->send($message);

            return;
        }
        if ($configuration['mode'] === 'managed') {
            try {
                Mail::to($recipient)->send($message);
            } catch (\Throwable) {
                throw new AssociationMailUncertain('Managed provider delivery outcome is uncertain. Review provider evidence before allowing another send.');
            }

            return;
        }
        if (empty($configuration['password'])) {
            throw new AssociationMailNotSent('SMTP password is absent. Save credentials before sending.');
        }
        $address = self::validatedAddress($configuration['host'], (int) $configuration['port']);
        $transport = new EsmtpTransport($address, (int) $configuration['port'], $configuration['encryption'] === 'tls');
        $transport->setAutoTls($configuration['encryption'] === 'starttls');
        $transport->setRequireTls(true);
        $transport->setUsername($configuration['username']);
        $transport->setPassword($configuration['password']);
        $stream = $transport->getStream();
        if (! $stream instanceof SocketStream) {
            throw new AssociationMailNotSent('The configured SMTP transport does not support the required network/TLS restrictions.');
        }
        $stream->setStreamOptions(['ssl' => ['peer_name' => $configuration['host'], 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $stream->setTimeout(15);
        $mailer = new Mailer('association-'.$association, app('view'), $transport, app('events'));
        $mailer->alwaysFrom($configuration['sender_email'], $configuration['sender_name']);
        try {
            $mailer->to($recipient)->send($message);
        } catch (\Throwable) {
            // Do not attach the raw exception: provider debug output can contain credentials/connection details.
            throw new AssociationMailUncertain('Custom SMTP delivery could not be confirmed. Check provider authentication, TLS and authorized sender configuration; review provider evidence before retry. No sender fallback was attempted.');
        } finally {
            try {
                $transport->stop();
            } catch (\Throwable) { /* The message result above is authoritative; never expose transport debug details. */
            }
        }
    }

    /** @param array<string, mixed> $configuration */
    public static function checkIdentity(string $association, array $configuration): void
    {
        $permitted = [config('mail.from.address'), ...config('association-mail.managed_senders.'.$association, [])];
        if ($configuration['mode'] === 'managed' && ! in_array($configuration['sender_email'], $permitted, true)) {
            throw new AssociationMailNotSent('This managed sender has not been authorized by the deployment administrator. Use the FILMUNITY sender or complete provider/domain verification.');
        }
        if (! ($configuration['provider_reviewed'] ?? false)) {
            throw new AssociationMailNotSent('Review sender/domain authorization with the provider before enabling this configuration.');
        }
    }
}
