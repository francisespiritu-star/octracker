<?php

/**
 * Mail helper: Resend/Brevo HTTP API → SMTP → PHP mail() → mailto fallback.
 *
 * Railway often blocks outbound SMTP (Gmail :587 times out). Prefer HTTPS APIs:
 *
 *   RESEND_API_KEY   from https://resend.com (free tier)
 *   RESEND_FROM      e.g. OCTRacker <onboarding@resend.dev> for tests
 *                    or a verified domain sender
 *
 *   BREVO_API_KEY    from Brevo SMTP & API → API keys
 *   BREVO_FROM       verified sender email
 *
 * SMTP (may fail on Railway):
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_SECURE
 */

function buildMailtoLink(string $to, string $subject, string $body): string
{
    return 'mailto:' . rawurlencode($to)
        . '?subject=' . rawurlencode($subject)
        . '&body=' . rawurlencode($body);
}

function mailEnv(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && (string) $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    return $default;
}

/**
 * Parse "Name <email@domain>" or plain "email@domain".
 *
 * @return array{email:string,name:string}
 */
function parseMailAddress(string $value, string $defaultName = 'OCTRacker'): array
{
    $value = trim($value);
    if (preg_match('/^(.+?)\s*<([^>]+)>$/', $value, $m)) {
        $name = trim($m[1], " \t\"'");
        $email = trim($m[2]);
        return [
            'email' => $email,
            'name' => $name !== '' ? $name : $defaultName,
        ];
    }
    return ['email' => $value, 'name' => $defaultName];
}

/**
 * @return array{ok:bool,message:string}
 */
function sendResendEmail(string $to, string $subject, string $body, string $from): array
{
    $apiKey = mailEnv('RESEND_API_KEY');
    if ($apiKey === null) {
        return ['ok' => false, 'message' => 'RESEND_API_KEY is not set.'];
    }

    $from = mailEnv('RESEND_FROM', $from) ?? $from;
    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'text' => $body,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'message' => 'Resend request failed: ' . $error];
    }

    $decoded = json_decode((string) $response, true);
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'message' => 'Email sent to ' . $to . ' via Resend.'];
    }

    $detail = is_array($decoded) ? (string) ($decoded['message'] ?? $response) : (string) $response;
    return ['ok' => false, 'message' => 'Resend error (' . $status . '): ' . $detail];
}

/**
 * @return array{ok:bool,message:string}
 */
function sendBrevoApiEmail(string $to, string $subject, string $body, string $from): array
{
    $apiKey = mailEnv('BREVO_API_KEY');
    if ($apiKey === null) {
        return ['ok' => false, 'message' => 'BREVO_API_KEY is not set.'];
    }

    $rawFrom = mailEnv('BREVO_FROM', $from) ?? $from;
    $parsed = parseMailAddress($rawFrom, 'OCTRacker');
    if ($parsed['email'] === '' || !filter_var($parsed['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'BREVO_FROM must be a verified email (e.g. OCTracker <you@gmail.com>).'];
    }

    $payload = json_encode([
        'sender' => ['email' => $parsed['email'], 'name' => $parsed['name']],
        'to' => [['email' => $to]],
        'subject' => $subject,
        'textContent' => $body,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'message' => 'Brevo request failed: ' . $error];
    }
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'message' => 'Email sent to ' . $to . ' via Brevo.'];
    }

    $decoded = json_decode((string) $response, true);
    $detail = is_array($decoded) ? (string) ($decoded['message'] ?? json_encode($decoded)) : (string) $response;
    return ['ok' => false, 'message' => 'Brevo error (' . $status . '): ' . $detail];
}

/**
 * Minimal SMTP client (AUTH LOGIN + STARTTLS/SSL).
 *
 * @return array{ok:bool,message:string}
 */
function sendSmtpEmail(string $to, string $subject, string $body, string $from, ?int $forcePort = null, ?string $forceSecure = null): array
{
    $host = mailEnv('SMTP_HOST');
    $user = mailEnv('SMTP_USER');
    $pass = mailEnv('SMTP_PASS');
    if ($pass !== null) {
        $pass = preg_replace('/\s+/', '', $pass) ?? $pass;
    }
    $port = $forcePort ?? (int) (mailEnv('SMTP_PORT', '587') ?? '587');
    $secure = strtolower((string) ($forceSecure ?? mailEnv('SMTP_SECURE') ?? ''));

    if ($host === null || $user === null || $pass === null || $pass === '') {
        return ['ok' => false, 'message' => 'SMTP is not configured.'];
    }

    if ($secure === '') {
        $secure = ($port === 465) ? 'ssl' : 'tls';
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        return ['ok' => false, 'message' => 'Could not connect to SMTP server: ' . ($errstr !== '' ? $errstr : 'timeout')];
    }

    stream_set_timeout($socket, 15);

    $read = static function () use ($socket): string {
        $data = '';
        while ($line = fgets($socket, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $write = static function (string $command) use ($socket): void {
        fwrite($socket, $command . "\r\n");
    };

    $expect = static function (string $response, string $code) use (&$socket): ?string {
        if (strpos($response, $code) !== 0) {
            fclose($socket);
            return trim($response) !== '' ? trim($response) : 'Unexpected SMTP response.';
        }
        return null;
    };

    if ($err = $expect($read(), '220')) {
        return ['ok' => false, 'message' => 'SMTP greeting failed: ' . $err];
    }

    $write('EHLO octracker.local');
    if ($err = $expect($read(), '250')) {
        return ['ok' => false, 'message' => 'SMTP EHLO failed: ' . $err];
    }

    if ($secure === 'tls') {
        $write('STARTTLS');
        if ($err = $expect($read(), '220')) {
            return ['ok' => false, 'message' => 'SMTP STARTTLS failed: ' . $err];
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['ok' => false, 'message' => 'SMTP TLS negotiation failed.'];
        }
        $write('EHLO octracker.local');
        if ($err = $expect($read(), '250')) {
            return ['ok' => false, 'message' => 'SMTP EHLO after TLS failed: ' . $err];
        }
    }

    $write('AUTH LOGIN');
    if ($err = $expect($read(), '334')) {
        return ['ok' => false, 'message' => 'SMTP AUTH rejected: ' . $err];
    }
    $write(base64_encode($user));
    if ($err = $expect($read(), '334')) {
        return ['ok' => false, 'message' => 'SMTP username rejected: ' . $err];
    }
    $write(base64_encode($pass));
    if ($err = $expect($read(), '235')) {
        return ['ok' => false, 'message' => 'SMTP login failed (check SMTP_USER / SMTP_PASS).'];
    }

    $write('MAIL FROM:<' . $from . '>');
    if ($err = $expect($read(), '250')) {
        return ['ok' => false, 'message' => 'SMTP MAIL FROM failed: ' . $err];
    }
    $write('RCPT TO:<' . $to . '>');
    $rcpt = $read();
    if (strpos($rcpt, '250') !== 0 && strpos($rcpt, '251') !== 0) {
        fclose($socket);
        return ['ok' => false, 'message' => 'SMTP recipient rejected: ' . trim($rcpt)];
    }

    $write('DATA');
    if ($err = $expect($read(), '354')) {
        return ['ok' => false, 'message' => 'SMTP DATA failed: ' . $err];
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $payload = 'From: OCTRacker <' . $from . ">\r\n"
        . 'To: <' . $to . ">\r\n"
        . 'Subject: ' . $encodedSubject . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n"
        . "\r\n"
        . str_replace("\n.", "\n..", $body)
        . "\r\n.";

    $write($payload);
    if ($err = $expect($read(), '250')) {
        return ['ok' => false, 'message' => 'SMTP send failed: ' . $err];
    }

    $write('QUIT');
    fclose($socket);

    return ['ok' => true, 'message' => 'Email sent to ' . $to . ' via SMTP.'];
}

/**
 * @return array{ok:bool,method:string,message:string,mailto:?string}
 */
function sendAppEmail(string $to, string $subject, string $body, string $from = 'no-reply@octracker.local'): array
{
    $to = trim($to);
    $subject = trim($subject);
    $body = trim($body);
    $from = trim((string) (mailEnv('SMTP_FROM', $from) ?? $from));

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok' => false,
            'method' => 'none',
            'message' => 'Invalid recipient email address.',
            'mailto' => null,
        ];
    }

    $mailto = buildMailtoLink($to, $subject, $body);

    // 1) HTTPS APIs (work on Railway; SMTP ports are often blocked)
    if (mailEnv('RESEND_API_KEY')) {
        $resend = sendResendEmail($to, $subject, $body, $from);
        if ($resend['ok']) {
            return ['ok' => true, 'method' => 'resend', 'message' => $resend['message'], 'mailto' => null];
        }
        return ['ok' => false, 'method' => 'mailto', 'message' => $resend['message'] . ' Use the email app link below as a fallback.', 'mailto' => $mailto];
    }

    if (mailEnv('BREVO_API_KEY')) {
        $brevo = sendBrevoApiEmail($to, $subject, $body, $from);
        if ($brevo['ok']) {
            return ['ok' => true, 'method' => 'brevo', 'message' => $brevo['message'], 'mailto' => null];
        }
        return ['ok' => false, 'method' => 'mailto', 'message' => $brevo['message'] . ' Use the email app link below as a fallback.', 'mailto' => $mailto];
    }

    // 2) SMTP — try configured port, then Gmail 465 SSL fallback
    if (mailEnv('SMTP_HOST') && mailEnv('SMTP_USER') && mailEnv('SMTP_PASS')) {
        $smtp = sendSmtpEmail($to, $subject, $body, $from);
        if (!$smtp['ok'] && stripos((string) mailEnv('SMTP_HOST'), 'gmail') !== false) {
            $smtp = sendSmtpEmail($to, $subject, $body, $from, 465, 'ssl');
        }
        if ($smtp['ok']) {
            return ['ok' => true, 'method' => 'smtp', 'message' => $smtp['message'], 'mailto' => null];
        }

        $hint = $smtp['message'];
        if (stripos($hint, 'timed out') !== false || stripos($hint, 'Connection') !== false) {
            $hint .= ' Railway often blocks Gmail SMTP ports. Add RESEND_API_KEY (https://resend.com) instead.';
        }

        return [
            'ok' => false,
            'method' => 'mailto',
            'message' => $hint . ' Use the email app link below as a fallback.',
            'mailto' => $mailto,
        ];
    }

    // 3) PHP mail()
    $headers = 'From: ' . $from . "\r\n"
        . 'Reply-To: ' . $from . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    if (@mail($to, $subject, wordwrap($body, 70), $headers)) {
        return ['ok' => true, 'method' => 'mail', 'message' => 'Email sent to ' . $to . '.', 'mailto' => null];
    }

    return [
        'ok' => false,
        'method' => 'mailto',
        'message' => 'Outgoing email is not configured. On Railway use RESEND_API_KEY (recommended) or SMTP vars. Or use the email app link below.',
        'mailto' => $mailto,
    ];
}
