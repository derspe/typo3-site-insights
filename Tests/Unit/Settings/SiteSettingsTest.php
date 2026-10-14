<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Settings;

use Derspe\SiteInsights\Tests\Unit\Fixtures\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SiteSettingsTest extends UnitTestCase
{
    private const TABLE = 'tx_derspesiteinsights_setting';

    #[Test]
    public function valuesAreStoredPerSiteAndDefaultsApply(): void
    {
        $settings = $this->settings();
        $settings->set('alpha', 'matomo_site_id', '3');
        self::assertSame('3', $settings->getString('alpha', 'matomo_site_id'));
        self::assertSame('', $settings->getString('beta', 'matomo_site_id'));
        self::assertSame(100, $settings->getInt('beta', 'gsc_snapshot_top_n'));
    }

    #[Test]
    public function secretsAreEncryptedAtRest(): void
    {
        $this->settings()->set('alpha', 'matomo_token', 'secret-token-123');
        $stored = (string)$this->db->tables[self::TABLE][0]['value'];
        self::assertStringNotContainsString('secret-token-123', $stored);
        self::assertStringNotContainsString(base64_encode('secret-token-123'), $stored);
        self::assertSame('secret-token-123', $this->settings()->getString('alpha', 'matomo_token'));
    }

    #[Test]
    public function plainSettingsAreNotEncrypted(): void
    {
        $this->settings()->set('alpha', 'matomo_url', 'https://matomo.example.com');
        self::assertSame('"https://matomo.example.com"', $this->db->tables[self::TABLE][0]['value']);
    }

    #[Test]
    public function encryptedSecretCannotBeMovedToAnotherSiteOrKey(): void
    {
        $this->settings()->set('alpha', 'matomo_token', 'secret-token-123');
        $ciphertext = $this->db->tables[self::TABLE][0]['value'];
        // Copy the ciphertext to another site and another secret key
        $this->db->tables[self::TABLE][] = ['site' => 'beta', 'name' => 'matomo_token', 'value' => $ciphertext];
        $this->db->tables[self::TABLE][] = ['site' => 'alpha', 'name' => 'gsc_oauth_client_secret', 'value' => $ciphertext];
        $settings = $this->settings();
        self::assertSame('', $settings->getString('beta', 'matomo_token'));
        self::assertSame('', $settings->getString('alpha', 'gsc_oauth_client_secret'));
        self::assertFalse($settings->isSet('beta', 'matomo_token'));
    }

    #[Test]
    public function changedEncryptionKeyMakesSecretsUnreadableInsteadOfFailing(): void
    {
        $this->settings()->set('alpha', 'gsc_refresh_token', 'refresh');
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('x', 96);
        self::assertFalse($this->settings()->isSet('alpha', 'gsc_refresh_token'));
    }

    #[Test]
    public function encryptValueIsBoundToItsContext(): void
    {
        $settings = $this->settings();
        $encrypted = $settings->encryptValue('access-token', 'context-a');
        self::assertStringNotContainsString('access-token', $encrypted);
        self::assertSame('access-token', $settings->decryptValue($encrypted, 'context-a'));
        self::assertNull($settings->decryptValue($encrypted, 'context-b'));
        self::assertNull($settings->decryptValue('garbage', 'context-a'));
    }

    #[Test]
    public function removeDeletesTheValue(): void
    {
        $settings = $this->settings();
        $settings->set('alpha', 'matomo_token', 'x');
        $settings->remove('alpha', 'matomo_token');
        self::assertFalse($settings->isSet('alpha', 'matomo_token'));
        self::assertSame([], $this->db->tables[self::TABLE]);
    }
}
