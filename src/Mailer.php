<?php
declare(strict_types=1);

/**
 * Sends outgoing mail (password reset links, etc.) via one of two drivers,
 * selected with MAIL_DRIVER — no external dependencies either way, so the
 * app still needs nothing but PHP + MySQL to run:
 *
 *   'native' (default) — hands off to PHP's built-in mail(), i.e. the local
 *   mail system (Exim/sendmail). Works on most shared hosting even when the
 *   host blocks PHP scripts from opening their own outbound socket
 *   connections to SMTP ports — a common restriction that a raw SMTP client
 *   like the 'smtp' driver below runs straight into.
 *
 *   'smtp' — a minimal SMTP client that authenticates directly against an
 *   external mailbox/relay (EHLO, optional STARTTLS, AUTH LOGIN, MAIL
 *   FROM/RCPT TO/DATA, QUIT). Needs the SMTP_* environment variables.
 */
class Mailer
{
    public static function sendPasswordReset(string $to, string $resetUrl): bool
    {
        $subject = 'Passwort zurücksetzen – ' . Config::mailFromName();
        $body =
            "Hallo,\r\n\r\n" .
            "für Ihr Konto wurde das Zurücksetzen des Passworts angefordert.\r\n\r\n" .
            "Klicken Sie auf den folgenden Link, um ein neues Passwort zu vergeben " .
            "(der Link ist 10 Minuten gültig):\r\n" .
            $resetUrl . "\r\n\r\n" .
            "Falls Sie diese Anfrage nicht gestellt haben, können Sie diese E-Mail ignorieren " .
            "— es wird nichts an Ihrem Konto geändert.\r\n";

        return self::send($to, $subject, $body);
    }

    public static function send(string $to, string $subject, string $textBody): bool
    {
        return Config::mailDriver() === 'smtp'
            ? self::sendViaSmtp($to, $subject, $textBody)
            : self::sendViaNative($to, $subject, $textBody);
    }

    /** Hand off to PHP's built-in mail() — the local MTA does the actual delivery. */
    private static function sendViaNative(string $to, string $subject, string $textBody): bool
    {
        $from = Config::mailFromEmail();
        if ($from === '') {
            error_log('Mailer: MAIL_FROM_EMAIL not configured; email not sent.');
            return false;
        }

        $headers = implode("\r\n", [
            'From: ' . self::encodeHeader(Config::mailFromName()) . " <{$from}>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);

        // -f sets the envelope sender so bounces go to $from rather than the server's default user.
        $ok = mail($to, self::encodeHeader($subject), $textBody, $headers, '-f' . $from);
        if (!$ok) {
            error_log('Mailer: mail() returned false — check the server\'s mail log (not error_log) for the reason.');
        }
        return $ok;
    }

    private static function sendViaSmtp(string $to, string $subject, string $textBody): bool
    {
        $host = Config::smtpHost();
        if ($host === '') {
            error_log('Mailer: SMTP_HOST not configured; email not sent.');
            return false;
        }

        $encryption = Config::smtpEncryption();
        $transport  = $encryption === 'ssl' ? 'ssl://' : 'tcp://';

        $errno = 0;
        $errstr = '';
        $warning = '';
        set_error_handler(function (int $_errno, string $errstrRaised) use (&$warning) {
            $warning = $errstrRaised;
            return true;
        });
        $socket = stream_socket_client(
            $transport . $host . ':' . Config::smtpPort(),
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );
        restore_error_handler();
        if (!$socket) {
            error_log("Mailer: could not connect to SMTP server: {$errstr} ({$errno}) {$warning}");
            return false;
        }
        stream_set_timeout($socket, 15);

        try {
            self::expect($socket, [220]);
            $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
            self::command($socket, "EHLO {$ehloHost}", [250]);

            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed.');
                }
                self::command($socket, "EHLO {$ehloHost}", [250]);
            }

            self::command($socket, 'AUTH LOGIN', [334]);
            self::command($socket, base64_encode(Config::smtpUser()), [334]);
            self::command($socket, base64_encode(Config::smtpPass()), [235]);

            $from = Config::mailFromEmail();
            self::command($socket, "MAIL FROM:<{$from}>", [250]);
            self::command($socket, "RCPT TO:<{$to}>", [250, 251]);
            self::command($socket, 'DATA', [354]);

            $headers = implode("\r\n", [
                'From: ' . self::encodeHeader(Config::mailFromName()) . " <{$from}>",
                "To: <{$to}>",
                'Subject: ' . self::encodeHeader($subject),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . preg_replace('/:\d+$/', '', $host) . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ]);

            fwrite($socket, $headers . "\r\n\r\n" . self::dotStuff($textBody) . "\r\n.\r\n");
            self::expect($socket, [250]);

            self::command($socket, 'QUIT', [221]);
        } catch (Throwable $e) {
            error_log('Mailer: ' . $e->getMessage());
            fclose($socket);
            return false;
        }

        fclose($socket);
        return true;
    }

    /** Read one (possibly multi-line) SMTP reply. Continuation lines use '-' after the code. */
    private static function readResponse($socket): string
    {
        $data = '';
        while ($line = fgets($socket, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    private static function expect($socket, array $codes): void
    {
        $response = self::readResponse($socket);
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP error: expected ' . implode('/', $codes) . ', got: ' . trim($response));
        }
    }

    private static function command($socket, string $cmd, array $expectCodes): void
    {
        fwrite($socket, $cmd . "\r\n");
        self::expect($socket, $expectCodes);
    }

    /** RFC 5321 transparency: lines starting with '.' get an extra leading '.'. */
    private static function dotStuff(string $text): string
    {
        $lines = preg_split("/\r\n|\r|\n/", $text);
        foreach ($lines as &$line) {
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
        }
        return implode("\r\n", $lines);
    }

    /** RFC 2047-encode header values that contain non-ASCII characters. */
    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}
