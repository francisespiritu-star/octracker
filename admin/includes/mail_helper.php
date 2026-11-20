<?php

/**
 * Mail helper: SMTP (preferred) → PHP mail() → mailto: fallback.
 *
 * Railway / production — set these env vars:
 *   SMTP_HOST   e.g. smtp-relay.brevo.com / smtp.gmail.com / smtp.sendgrid.net
 *   SMTP_PORT   e.g. 587
 *   SMTP_USER   SMTP username / API user
 *   SMTP_PASS   SMTP password / API key
 *   SMTP_FROM   e.g. noreply@yourdomain.com (or verified sender)
 *   SMTP_SECURE tls|ssl|none  (default tls for 587, ssl for 465)
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
 * Minimal SMTP client (AUTH LOGIN + STARTTLS/SSL).
 *
 * @return array{ok:bool,message:string}
 */
function sendSmtpEmail(string $to, string $subject, string $body, string $from): array
{
    $host = mailEnv('SMTP_HOST');
    $user = mailEnv('SMTP_USER');
    // Gmail app passwords are often copied with spaces — strip them
    $pass = mailEnv('SMTP_PASS');
    if ($pass !== null) {
        $pass = preg_replace('/\s+/', '', $pass) ?? $pass;
    }
    $port = (int) (mailEnv('SMTP_PORT', '587') ?? '587');
    $secure = strtolower((string) (mailEnv('SMTP_SECURE') ?? ''));

    if ($host === null || $user === null || $pass === null) {
        return ['ok' => false, 'message' => 'SMTP is not configured.'];
    }

    if ($secure === '') {
        $secure = ($port === 465) ? 'ssl' : 'tls';
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        return ['ok' => false, 'message' => 'Could not connect to SMTP server: ' . $errstr];
    }

    stream_set_timeout($socket, 20);

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

    $greeting = $read();
    if ($err = $expect($greeting, '220')) {
        return ['ok' => false, 'message' => 'SMTP greeting failed: ' . $err];
    }

    $write('EHLO octracker.local');
    $ehlo = $read();
    if ($err = $expect($ehlo, '250')) {
        return ['ok' => false, 'message' => 'SMTP EHLO failed: ' . $err];
    }

    if ($secure === 'tls') {
        $write('STARTTLS');
        $tls = $read();
        if ($err = $expect($tls, '220')) {
            return ['ok' => false, 'message' => 'SMTP STARTTLS failed: ' . $err];
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['ok' => false, 'message' => 'SMTP TLS negotiation failed.'];
        }
        $write('EHLO octracker.local');
        $ehlo2 = $read();
        if ($err = $expect($ehlo2, '250')) {
            return ['ok' => false, 'message' => 'SMTP EHLO after TLS failed: ' . $err];
        }
    }

    $write('AUTH LOGIN');
    $auth = $read();
    if ($err = $expect($auth, '334')) {
        return ['ok' => false, 'message' => 'SMTP AUTH rejected: ' . $err];
    }
    $write(base64_encode($user));
    $userResp = $read();
    if ($err = $expect($userResp, '334')) {
        return ['ok' => false, 'message' => 'SMTP username rejected: ' . $err];
    }
    $write(base64_encode($pass));
    $passResp = $read();
    if ($err = $expect($passResp, '235')) {
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

    // 1) SMTP when configured (Railway / production)
    if (mailEnv('SMTP_HOST') && mailEnv('SMTP_USER') && mailEnv('SMTP_PASS')) {
        $smtp = sendSmtpEmail($to, $subject, $body, $from);
        if ($smtp['ok']) {
            return [
                'ok' => true,
                'method' => 'smtp',
                'message' => $smtp['message'],
                'mailto' => null,
            ];
        }
        // Fall through to mailto with SMTP error shown
        $mailto = buildMailtoLink($to, $subject, $body);
        return [
            'ok' => false,
            'method' => 'mailto',
            'message' => $smtp['message'] . ' Use the email app link below as a fallback.',
            'mailto' => $mailto,
        ];
    }

    // 2) PHP mail() (rarely works on XAMPP / many hosts)
    $headers = 'From: ' . $from . "\r\n"
        . 'Reply-To: ' . $from . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = @mail($to, $subject, wordwrap($body, 70), $headers);
    if ($sent) {
        return [
            'ok' => true,
            'method' => 'mail',
            'message' => 'Email sent to ' . $to . '.',
            'mailto' => null,
        ];
    }

    // 3) Mailto fallback for demos / local
    $mailto = buildMailtoLink($to, $subject, $body);
    return [
        'ok' => false,
        'method' => 'mailto',
        'message' => 'Outgoing email is not configured. Add SMTP_HOST, SMTP_USER, SMTP_PASS, and SMTP_FROM on Railway (or use the email app link below).',
        'mailto' => $mailto,
    ];
}
