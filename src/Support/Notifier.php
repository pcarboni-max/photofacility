<?php

declare(strict_types=1);

namespace PhotoFacility\Support;

/**
 * Invio alert. Per una pipeline non presidiata (nessuno guarda i log) è
 * l'unico modo di accorgersi di un guasto.
 *
 * Canali, in ordine di preferenza:
 *   1. Webhook (POST JSON via cURL) — Telegram/Slack/Discord/endpoint proprio.
 *      Affidabile su Lightsail dove mail() spesso non è configurato.
 *   2. mail() — fallback opzionale.
 * Se nessun canale è configurato, gli alert vengono solo loggati (no-op silenzioso).
 *
 * De-duplica gli alert entro una finestra temporale per non inondare il canale
 * quando un guasto persiste (es. S3 down per ore).
 */
final class Notifier
{
    public function __construct(
        private readonly ?string $webhookUrl,
        private readonly ?string $email,
        private readonly Logger $log,
        private readonly string $stateDir,
        private readonly int $dedupeMinutes = 30,
    ) {
    }

    public function alert(string $key, string $subject, string $message): void
    {
        $this->log->warn('ALERT ' . $key . ': ' . $subject, ['message' => $message]);

        if ($this->isDeduped($key)) {
            $this->log->debug('Alert de-duplicato (già inviato di recente)', ['key' => $key]);
            return;
        }

        $sent = false;
        if ($this->webhookUrl !== null && $this->webhookUrl !== '') {
            $sent = $this->sendWebhook($subject, $message) || $sent;
        }
        if ($this->email !== null && $this->email !== '') {
            $sent = $this->sendEmail($subject, $message) || $sent;
        }

        if (!$sent && ($this->webhookUrl === null || $this->webhookUrl === '')
            && ($this->email === null || $this->email === '')) {
            // nessun canale configurato: l'alert resta solo nel log
            return;
        }

        $this->markSent($key);
    }

    private function sendWebhook(string $subject, string $message): bool
    {
        $payload = json_encode([
            // 'text' è il campo usato da Slack/Discord/Telegram-bot generici
            'text' => "🔴 PhotoFacility — {$subject}\n{$message}",
            'subject' => $subject,
            'message' => $message,
            'source' => 'photofacility',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        if ($err !== '' || $status >= 400) {
            $this->log->error('Invio webhook alert fallito', ['status' => $status, 'error' => $err]);
            return false;
        }
        return true;
    }

    private function sendEmail(string $subject, string $message): bool
    {
        if (!function_exists('mail')) {
            return false;
        }
        $ok = @mail(
            (string) $this->email,
            '[PhotoFacility] ' . $subject,
            $message,
            'From: photofacility@localhost'
        );
        if (!$ok) {
            $this->log->error('Invio email alert fallito (MTA non configurato?)');
        }
        return $ok;
    }

    private function stampFile(string $key): string
    {
        return $this->stateDir . '/alert_' . preg_replace('/[^a-z0-9_]/i', '_', $key) . '.stamp';
    }

    private function isDeduped(string $key): bool
    {
        $file = $this->stampFile($key);
        if (!is_file($file)) {
            return false;
        }
        $last = (int) @file_get_contents($file);
        return (time() - $last) < ($this->dedupeMinutes * 60);
    }

    private function markSent(string $key): void
    {
        @file_put_contents($this->stampFile($key), (string) time());
    }
}
