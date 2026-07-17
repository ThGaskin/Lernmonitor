<?php
declare(strict_types=1);

/**
 * Application configuration.
 *
 * Values are read from environment variables so the app works on any server
 * without code changes. Copy .env.example to .env and fill in your values,
 * or set the variables directly in your server/hosting control panel.
 *
 * A .env file is NOT loaded automatically here — use a real env loader or
 * set variables via Apache SetEnv / Nginx fastcgi_param / shell export.
 * For simple shared hosting, see the README for how to use .htaccess SetEnv.
 */
class Config
{
    const ASSET_VERSION = '0.58';

    public static function asset(string $path): string
    {
        return $path . '?v=' . self::ASSET_VERSION;
    }


    public static function get(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return ($value !== false && $value !== null) ? (string)$value : $default;
    }

    public static function dbDsn(): string
    {
        $host   = self::get('DB_HOST', 'localhost');
        $dbname = self::get('DB_NAME', 'student_database');
        $port   = self::get('DB_PORT', '3306');
        return "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    }

    public static function dbUser(): string
    {
        return self::get('DB_USER', 'root');
    }

    public static function dbPass(): string
    {
        return self::get('DB_PASS', '');
    }

    public static function appEnv(): string
    {
        return self::get('APP_ENV', 'production');
    }

    public static function isDebug(): bool
    {
        return self::get('APP_DEBUG', 'false') === 'true';
    }

    // -------------------------------------------------------------------
    // Outgoing mail (password reset links, etc.)
    // -------------------------------------------------------------------

    /**
     * 'native' — hand off to the local mail system via PHP's built-in mail()
     * (works on most shared hosting, even when hosts block PHP scripts from
     * opening their own outbound socket connections to SMTP ports).
     * 'smtp'   — speak SMTP directly to an external mailbox/relay; needs the
     * SMTP_* settings below.
     */
    public static function mailDriver(): string
    {
        return self::get('MAIL_DRIVER', 'native');
    }

    public static function mailFromEmail(): string
    {
        return self::get('MAIL_FROM_EMAIL') ?: self::smtpUser();
    }

    public static function mailFromName(): string
    {
        return self::get('MAIL_FROM_NAME', 'Lernmonitor');
    }

    public static function smtpHost(): string
    {
        return self::get('SMTP_HOST');
    }

    public static function smtpPort(): int
    {
        return (int)self::get('SMTP_PORT', '587');
    }

    /** 'tls' (STARTTLS, typically port 587) or 'ssl' (implicit TLS, typically port 465). */
    public static function smtpEncryption(): string
    {
        return self::get('SMTP_ENCRYPTION', 'tls');
    }

    public static function smtpUser(): string
    {
        return self::get('SMTP_USER');
    }

    public static function smtpPass(): string
    {
        return self::get('SMTP_PASS');
    }

    /**
     * Base URL used to build links in outgoing emails (e.g. password reset).
     * Falls back to the scheme/host of the current request so no APP_URL
     * needs to be set for a typical single-domain deployment.
     */
    public static function appUrl(): string
    {
        $configured = self::get('APP_URL');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$scheme}://{$host}";
    }
}
