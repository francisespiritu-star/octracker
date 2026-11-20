<?php

/**
 * Simple mail helper for local XAMPP + hosted deploy.
 * Tries PHP mail(); if that fails, builds a mailto: fallback for demos.
 */

function buildMailtoLink(string $to, string $subject, string $body): string
{
    return 'mailto:' . rawurlencode($to)
        . '?subject=' . rawurlencode($subject)
        . '&body=' . rawurlencode($body);
}

/**
 * @return array{ok:bool,method:string,message:string,mailto:?string}
 */
function sendAppEmail(string $to, string $subject, string $body, string $from = 'no-reply@octracker.local'): array
{
    $to = trim($to);
    $subject = trim($subject);
    $body = trim($body);

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok' => false,
            'method' => 'none',
            'message' => 'Invalid recipient email address.',
            'mailto' => null,
        ];
    }

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

    // Local XAMPP usually has no SMTP/mail server configured.
    $mailto = buildMailtoLink($to, $subject, $body);

    return [
        'ok' => false,
        'method' => 'mailto',
        'message' => 'Server mail is not configured on this computer (common on XAMPP). Use the email app link below, or configure SMTP on your hosting when you deploy.',
        'mailto' => $mailto,
    ];
}
