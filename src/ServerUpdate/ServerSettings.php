<?php

declare(strict_types=1);

namespace Quire\Server\ServerUpdate;

use Quire\Server\Backup\BackupSettings;
use Quire\Server\Notify\NotifySettings;
use Quire\Server\Site\EnvFile;
use Quire\Server\Site\SiteName;
use Quire\Server\Update\AutomaticUpdates;

/**
 * The settings of server.env an owner changes with "quire-server settings:set", and how each is shown
 * and checked. The rest of server.env is shown, never changed here: the agent's image and COMPOSE_FILE
 * are the updates' and "database:upgrade"'s, the generated secrets are the installer's, the backups'
 * bucket and keys are "backups:setup"'s, the sites' range the installer's.
 */
final class ServerSettings
{
    /** A value never shown, only whether it's set */
    private const string SECRET = 'secret';

    /** A mailer's DSN: shown without its password */
    private const string DSN = 'dsn';

    /**
     * What an owner may change, and how it's checked: "email", "emails" (comma-separated), "text",
     * "constraint", "hour", "seconds", "megabytes", "keep", "hosts", "dsn", "webhook", "secret".
     */
    public const array CHANGEABLE = [
        'QUIRE_SERVER_ACME_EMAIL' => 'email',
        'QUIRE_SERVER_MAILER_DSN' => self::DSN,
        'QUIRE_SERVER_NOTIFY_EMAIL' => 'email',
        'QUIRE_SERVER_NOTIFY_FROM' => 'email',
        'QUIRE_SERVER_NOTIFY_WEBHOOK' => 'webhook',
        'QUIRE_SERVER_NOTIFY_SECRET' => self::SECRET,
        'QUIRE_SERVER_NAME' => 'text',
        'QUIRE_SERVER_QUIRE_VERSION' => 'constraint',
        'QUIRE_SERVER_UPDATE_HOUR' => 'hour',
        'QUIRE_SERVER_BACKUP_HOUR' => 'hour',
        'QUIRE_SERVER_BACKUP_KEEP' => 'keep',
        'QUIRE_SERVER_GIT_POLL_SECONDS' => 'seconds',
        'QUIRE_SERVER_GIT_PRIVATE_HOSTS' => 'hosts',
        'QUIRE_SERVER_BUILD_MEMORY_MB' => 'megabytes',
    ];

    /** Never shown: secrets of the server, whoever sets them */
    private const array SECRETS = [
        'QUIRE_SERVER_APP_SECRET', 'QUIRE_SERVER_POSTGRES_PASSWORD', 'QUIRE_SERVER_DATABASE_PASSWORD',
        'QUIRE_SERVER_BACKUP_SECRET_KEY', 'QUIRE_SERVER_BACKUP_PASSWORD', 'QUIRE_SERVER_NOTIFY_SECRET',
        // A webhook's address is its key (Slack's, Discord's)
        'QUIRE_SERVER_NOTIFY_WEBHOOK',
    ];

    /**
     * The setting a name means: its full name ("QUIRE_SERVER_MAILER_DSN") or without the prefix, in
     * either case ("mailer_dsn", "mailer-dsn").
     */
    public static function name(string $name): string
    {
        $name = strtoupper(str_replace('-', '_', trim($name)));

        return str_starts_with($name, 'QUIRE_SERVER_') ? $name : 'QUIRE_SERVER_'.$name;
    }

    /**
     * Why the setting can't be changed here, or null when it can.
     */
    public static function refusal(string $name): ?string
    {
        if (isset(self::CHANGEABLE[$name])) {
            return null;
        }

        return match (true) {
            \in_array($name, ['COMPOSE_FILE', 'QUIRE_SERVER_IMAGE'], true) => \sprintf('%s is the release\'s: "quire-server update" and "database:upgrade" set it.', $name),
            str_starts_with($name, 'QUIRE_SERVER_BACKUP_') => \sprintf('%s is set with the other backup settings: "quire-server backups:setup".', $name),
            \in_array($name, ['QUIRE_SERVER_APP_SECRET', 'QUIRE_SERVER_POSTGRES_PASSWORD', 'QUIRE_SERVER_DATABASE_PASSWORD'], true) => \sprintf('%s was made by the installer, and the server\'s data depends on it: it isn\'t changed here.', $name),
            'QUIRE_SERVER_SITE_NETWORKS' === $name => 'QUIRE_SERVER_SITE_NETWORKS is checked against the machine\'s routes by the installer: change it in server.env, then "quire-server up" checks it.',
            default => \sprintf('There\'s no setting %s to change here: "quire-server settings" lists them.', SiteName::printable($name)),
        };
    }

    /** Whether its value is asked for, never taken from the command line (shell history, the process list) */
    public static function secret(string $name): bool
    {
        return \in_array(self::CHANGEABLE[$name] ?? null, [self::SECRET, self::DSN, 'webhook'], true);
    }

    /**
     * Every setting of server.env, as it may be shown: secrets as "set" or "(not set)", a DSN without its
     * password.
     *
     * @return array<string, string>
     */
    public static function shown(EnvFile $env): array
    {
        $shown = [];

        foreach ($env->values() as $name => $value) {
            $shown[$name] = match (true) {
                '' === $value => '(not set)',
                \in_array($name, self::SECRETS, true) => 'set (not shown)',
                'QUIRE_SERVER_MAILER_DSN' === $name => self::dsnShown($value),
                default => SiteName::printable($value),
            };
        }

        return $shown;
    }

    /**
     * A DSN without its password: "smtp://user@host:587" (the user of an API token is the token, so
     * it's left out too when there's a password).
     */
    public static function dsnShown(string $dsn): string
    {
        $parts = parse_url($dsn);

        if (false === $parts || !isset($parts['scheme'])) {
            return 'set, but not a DSN';
        }

        $user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
        // A token as the user (Postmark's "<token>:<token>"): as secret as a password
        $who = null === $user ? '' : (isset($parts['pass']) && rawurldecode($parts['pass']) === $user ? '(a token)@' : SiteName::printable($user).(isset($parts['pass']) ? ':(password)' : '').'@');

        return \sprintf('%s://%s%s%s', $parts['scheme'], $who, $parts['host'] ?? '', isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * What's wrong with a value for a setting, or null when it's right (as the agent will read it).
     */
    public static function problem(string $name, string $value): ?string
    {
        if (1 === preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return \sprintf('%s can\'t hold control characters or line breaks.', $name);
        }

        $kind = self::CHANGEABLE[$name] ?? null;

        if ('' === $value) {
            return 'QUIRE_SERVER_QUIRE_VERSION' === $name || 'QUIRE_SERVER_BACKUP_KEEP' === $name ? \sprintf('%s needs a value.', $name) : null;
        }

        try {
            return match ($kind) {
                'email' => false === filter_var($value, \FILTER_VALIDATE_EMAIL) ? \sprintf('%s is an email address, e.g. ops@example.com.', $name) : null,
                'text' => [] === new NotifySettings(name: $value)->problems() ? null : 'QUIRE_SERVER_NAME is a short name for this server (63 plain characters at most), e.g. fra1-web-01.',
                'constraint' => 1 === preg_match('/^\^?\d+\.\d+(\.\d+)?$/', $value) ? null : 'QUIRE_SERVER_QUIRE_VERSION is the Quire version new sites get, e.g. ^0.2.',
                'hour' => self::passes(static fn (): ?int => AutomaticUpdates::parseHour($value, $name)),
                'seconds' => ctype_digit($value) && (int) $value >= 60 ? null : 'QUIRE_SERVER_GIT_POLL_SECONDS is a number of seconds, 60 at least.',
                'megabytes' => ctype_digit($value) && (int) $value >= 256 ? null : 'QUIRE_SERVER_BUILD_MEMORY_MB is a number of megabytes, 256 at least.',
                'keep' => self::passes(static fn (): array => new BackupSettings('', '', '', '', keep: $value)->retention()),
                'hosts' => 1 === preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(,[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)*$/', $value) ? null : 'QUIRE_SERVER_GIT_PRIVATE_HOSTS is host names, comma-separated, e.g. git.internal.example.',
                self::DSN => self::dsnProblem($value),
                'webhook' => [] === new NotifySettings(webhook: $value, secret: str_repeat('0', 64))->webhookProblems() ? null : 'QUIRE_SERVER_NOTIFY_WEBHOOK is an https:// address.',
                self::SECRET => \strlen($value) >= 32 ? null : 'QUIRE_SERVER_NOTIFY_SECRET is a long random value: "openssl rand -hex 32" makes one.',
                default => self::refusal($name),
            };
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Null when the check passes; its exception's message is the problem (caught by problem()).
     *
     * @param \Closure(): mixed $check
     */
    private static function passes(\Closure $check): null
    {
        $check();

        return null;
    }

    private static function dsnProblem(string $dsn): ?string
    {
        $parts = parse_url($dsn);
        $scheme = false === $parts ? null : ($parts['scheme'] ?? null);

        return match (true) {
            null === $scheme => 'QUIRE_SERVER_MAILER_DSN is a mailer DSN, e.g. smtp://user:password@smtp.example.com:587.',
            !\in_array($scheme, ['smtp', 'smtps', 'null'], true) => \sprintf('QUIRE_SERVER_MAILER_DSN starts with smtp://, smtps:// or null://, not %s:// (the server sends over SMTP).', SiteName::printable($scheme)),
            'null' !== $scheme && !isset($parts['host']) => 'QUIRE_SERVER_MAILER_DSN needs the mail server\'s host, e.g. smtp://user:password@smtp.example.com:587.',
            default => null,
        };
    }
}
