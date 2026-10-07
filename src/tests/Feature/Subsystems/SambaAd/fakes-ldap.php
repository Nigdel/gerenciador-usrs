<?php

/**
 * Simulated LDAP directory for the SambaAdService tests.
 *
 * SambaAdService calls ldap_connect(), ldap_search(), ldap_rename() and the
 * rest *unqualified*, from inside the App\Services\Subsystems namespace. PHP
 * resolves such a call by looking for a function in the calling namespace before
 * falling back to the global one, so declaring them here — in that same
 * namespace — is what makes the service talk to this fake instead of to a real
 * directory. No server, no schema, and no change to how the driver is written.
 *
 * These have to be free functions rather than static methods on a class: a
 * class's methods are never consulted when resolving a function call.
 *
 * Deliberately not in composer's `files` autoload. These declarations would then
 * load in every real request and every queue worker, and would shadow the
 * extension's functions across the whole application. Only the SambaAd tests
 * require this file.
 *
 * @see FakeLdapDirectory for the state and the assertion helpers.
 */

namespace App\Services\Subsystems;

/**
 * The state behind the fake, and the helpers tests use to assert on it.
 *
 * A class rather than static properties on this file so the SambaAd tests can
 * reach the state without reaching into function scope. It is declared here,
 * in the same file that declares the functions, because loading this file is
 * what puts the driver on the fake — a separate file would have to be loaded
 * too and the two could drift apart.
 */
class FakeLdapDirectory
{
    /** @var array<int, array{0: string, 1: array}> [function name, arguments] per call. */
    public static array $calls = [];

    /** The handle ldap_connect() hands back. Opaque to the service. */
    public static mixed $handle = null;

    /** Users in the directory, keyed by lowercase sAMAccountName => userAccountControl. */
    public static array $users = [];

    /** What the last search/read returned, in ldap_get_entries() shape. */
    public static array $lastEntries = ['count' => 0];

    /** Function name => bool, for the calls that answer success or failure. */
    public static array $succeeds = [];

    /** DN renames requested, as [from, to]. */
    public static array $renames = [];

    /** sAMAccountName => DN, for users whose DN a rename has moved. */
    public static array $currentDn = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$handle = null;
        self::$users = [];
        self::$lastEntries = ['count' => 0];
        self::$succeeds = [];
        self::$renames = [];
        self::$currentDn = [];
    }

    /**
     * Puts a user in the directory.
     *
     * The DN's CN is the login, not the display name, because SambaAdService
     * resolves DNs by sAMAccountName precisely because the CN can go stale.
     */
    public static function addUser(string $samAccountName, int $userAccountControl = 512): void
    {
        self::$users[strtolower($samAccountName)] = $userAccountControl;
    }

    public static function defaultDn(string $sam): string
    {
        return 'CN='.$sam.',OU=Usuarios,DC=klios,DC=br';
    }

    /** @return array<int, array> Arguments of every call to $function. */
    public static function callsTo(string $function): array
    {
        return array_values(array_filter(
            self::$calls,
            static fn (array $call): bool => $call[0] === $function,
        ));
    }

    public static function record(string $function, array $arguments): void
    {
        self::$calls[] = [$function, $arguments];
    }

    /**
     * Works out which user a filter is about, or null for "matches nothing".
     *
     * The service builds three shapes: a bare '(sAMAccountName=x)' when it
     * resolves a DN, a '(|(samaccountname=x)(userprincipalname=y))' when it
     * checks availability, and '(objectClass=*)' when it reads an entry's
     * control flag. The last one carries no name at all, so it is attributed to
     * the only user in the directory — correct, because the service only reads
     * an entry after having resolved that user's DN.
     */
    public static function samAccountNameInFilter(string $filter): ?string
    {
        if (preg_match('/\((?:sAMAccountName|samaccountname)=([^)]*)\)/i', $filter, $coincidencia) === 1) {
            return strtolower($coincidencia[1]);
        }

        if (str_contains($filter, 'objectClass=*') && count(self::$users) === 1) {
            return (string) array_key_first(self::$users);
        }

        return null;
    }
}

function ldap_set_option(mixed $link, int $option, mixed $value): bool
{
    FakeLdapDirectory::record('ldap_set_option', [$option]);

    return true;
}

function ldap_get_option(mixed $link, int $option, mixed &$value): bool
{
    return true;
}

function ldap_connect(?string $uri = null): mixed
{
    FakeLdapDirectory::record('ldap_connect', [$uri]);

    return FakeLdapDirectory::$handle ??= 'ldap://fake';
}

function ldap_bind(mixed $link, ?string $dn = null, ?string $password = null): bool
{
    FakeLdapDirectory::record('ldap_bind', [$dn]);

    return FakeLdapDirectory::$succeeds['ldap_bind'] ?? true;
}

function ldap_unbind(mixed $link): bool
{
    return true;
}

function ldap_search(mixed $link, string $baseDn, string $filter, array $attributes = []): mixed
{
    FakeLdapDirectory::record('ldap_search', [$baseDn, $filter]);

    if (! (FakeLdapDirectory::$succeeds['ldap_search'] ?? true)) {
        return false;
    }

    $sam = FakeLdapDirectory::samAccountNameInFilter($filter);

    if ($sam === null || ! array_key_exists($sam, FakeLdapDirectory::$users)) {
        // A result handle with zero entries — not false. The service
        // distinguishes "found nobody" from "the directory errored", and a
        // search that matched everything would report every login as taken.
        FakeLdapDirectory::$lastEntries = ['count' => 0];

        return 'ldap-result';
    }

    $dn = FakeLdapDirectory::$currentDn[$sam] ?? FakeLdapDirectory::defaultDn($sam);
    $uac = (string) FakeLdapDirectory::$users[$sam];

    FakeLdapDirectory::$lastEntries = [
        'count' => 1,
        // The service reads $entries[0]['dn'] and $entries[0]['useraccountcontrol'][0],
        // so index 0 needs the lowercased attribute names the real extension
        // returns — not just the numbered indices.
        0 => ['count' => 2, 'dn' => $dn, 'useraccountcontrol' => [$uac]],
        'dn' => [$dn],
        'useraccountcontrol' => [$uac],
    ];

    return 'ldap-result';
}

function ldap_read(mixed $link, string $dn, string $filter, array $attributes = []): mixed
{
    return ldap_search($link, $dn, $filter, $attributes);
}

function ldap_get_entries(mixed $link, mixed $result): array
{
    return FakeLdapDirectory::$lastEntries;
}

function ldap_add(mixed $link, string $dn, array $entry): bool
{
    FakeLdapDirectory::record('ldap_add', [$dn, $entry]);

    return FakeLdapDirectory::$succeeds['ldap_add'] ?? true;
}

function ldap_mod_replace(mixed $link, string $dn, array $entry): bool
{
    FakeLdapDirectory::record('ldap_mod_replace', [$dn, $entry]);

    return FakeLdapDirectory::$succeeds['ldap_mod_replace'] ?? true;
}

function ldap_delete(mixed $link, string $dn): bool
{
    FakeLdapDirectory::record('ldap_delete', [$dn]);

    return FakeLdapDirectory::$succeeds['ldap_delete'] ?? true;
}

function ldap_rename(mixed $link, string $fromDn, string $toDn, ?string $password = null, bool $deleteOldRdn = true): bool
{
    FakeLdapDirectory::record('ldap_rename', [$fromDn, $toDn]);
    FakeLdapDirectory::$renames[] = [$fromDn, $toDn];

    if (! (FakeLdapDirectory::$succeeds['ldap_rename'] ?? true)) {
        return false;
    }

    foreach (array_keys(FakeLdapDirectory::$users) as $sam) {
        if ((FakeLdapDirectory::$currentDn[$sam] ?? FakeLdapDirectory::defaultDn($sam)) === $fromDn) {
            FakeLdapDirectory::$currentDn[$sam] = $toDn;
        }
    }

    return true;
}

function ldap_errno(mixed $link): int
{
    return 1;
}

function ldap_error(mixed $link): string
{
    return 'LDAP fake error';
}

function ldap_get_dn(mixed $link, mixed $entry): string
{
    return FakeLdapDirectory::$lastEntries[0][1] ?? '';
}

function ldap_escape(string $value, string $ignore = '', int $flags = 0): string
{
    return $value;
}
