<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Fixtures;

/**
 * Content of a service account JSON key file with a generated RSA key
 * (one key per name and test run, generating is slow).
 */
final class KeyFile
{
    /** @var array<string, string> */
    private static array $privateKeys = [];

    /**
     * @param array<string, mixed> $overrides
     */
    public static function json(string $name = 'reports', array $overrides = []): string
    {
        return json_encode($overrides + [
            'type' => 'service_account',
            'project_id' => 'project-1',
            'private_key_id' => 'kid-' . $name,
            'private_key' => self::privateKey($name),
            'client_email' => self::email($name),
            'client_id' => '1234567890',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function email(string $name = 'reports'): string
    {
        return $name . '@project-1.iam.gserviceaccount.com';
    }

    public static function privateKey(string $name = 'reports'): string
    {
        if (!isset(self::$privateKeys[$name])) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, $pem);
            self::$privateKeys[$name] = $pem;
        }
        return self::$privateKeys[$name];
    }

    public static function publicKey(string $name = 'reports'): string
    {
        return (string)openssl_pkey_get_details(openssl_pkey_get_private(self::privateKey($name)))['key'];
    }
}
