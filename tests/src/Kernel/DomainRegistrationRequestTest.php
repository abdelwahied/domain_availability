<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Kernel;

use Drupal\domain_availability\Entity\DomainRegistrationRequestInterface;
use Drupal\domain_availability\Service\RegistrationSettings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\saudi_id_validator\Support\SaudiIdGenerator;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the registration request entity and settings.
 */
#[RunTestsInSeparateProcesses]
#[Group('domain_availability')]
final class DomainRegistrationRequestTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'domain_availability',
    'options',
    'file',
    'user',
    'system',
  ];

  /**
   * The request storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private $storage;


  /**
   * Mints the identification numbers this test uses.
   *
   * Numbers are generated rather than written out: a literal ten-digit value
   * cannot be checked by eye against the official check digit, and one that
   * silently fails it would make a test pass for the wrong reason.
   */
  private SaudiIdGenerator $ids;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->ids = new SaudiIdGenerator();
    $this->installEntitySchema('domain_registration_request');
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installConfig(['domain_availability']);
    $this->storage = $this->container->get('entity_type.manager')->getStorage('domain_registration_request');
  }

  /**
   * A request stores its fields and defaults status and owner.
   */
  public function testCreateDefaults(): void {
    $request = $this->create('neixora.sa');

    self::assertNotNull($request->id());
    self::assertSame('neixora.sa', $request->getDomain());
    self::assertSame(DomainRegistrationRequestInterface::STATUS_PENDING, $request->getStatus());
    self::assertSame(0, $request->getOwnerId());
    self::assertSame('DRR-' . sprintf('%06d', $request->id()), $request->getReferenceNumber());
    self::assertGreaterThan(0, $request->getCreatedTime());
  }

  /**
   * The status can move through the workflow.
   */
  public function testStatusTransitions(): void {
    $request = $this->create('neixora.sa');

    foreach ([
      DomainRegistrationRequestInterface::STATUS_APPROVED,
      DomainRegistrationRequestInterface::STATUS_REJECTED,
      DomainRegistrationRequestInterface::STATUS_CANCELLED,
    ] as $status) {
      $request->setStatus($status)->save();
      self::assertSame($status, $this->storage->loadUnchanged($request->id())->getStatus());
    }
  }

  /**
   * The reference number is stable and zero-padded.
   */
  public function testReferenceNumberFormat(): void {
    $request = $this->create('neixora.sa');
    self::assertMatchesRegularExpression('/^DRR-\d{6}$/', $request->getReferenceNumber());
  }

  /**
   * Settings default to the shipped values: enabled, .sa only, 24h window.
   */
  public function testSettingsDefaults(): void {
    /** @var \Drupal\domain_availability\Service\RegistrationSettings $settings */
    $settings = $this->container->get('domain_availability.registration_settings');

    self::assertTrue($settings->isEnabled());
    self::assertSame(['sa'], $settings->allowedTlds());
    self::assertSame(24 * 3600, $settings->duplicateWindowSeconds());
    self::assertSame(10 * 1024 * 1024, $settings->maxUploadBytes());
    self::assertSame('pdf', $settings->allowedExtensions());
  }

  /**
   * A domain is allowed only when enabled and its TLD is on the list.
   */
  public function testAllowsDomain(): void {
    $settings = $this->container->get('domain_availability.registration_settings');

    self::assertTrue($settings->allowsDomain('neixora.sa'));
    // .com is not on the default allow-list.
    self::assertFalse($settings->allowsDomain('neixora.com'));

    // Disabling the feature blocks every domain.
    $this->config(RegistrationSettings::CONFIG_NAME)->set('enabled', FALSE)->save();
    self::assertFalse($settings->allowsDomain('neixora.sa'));

    // An empty allow-list means every TLD.
    $this->config(RegistrationSettings::CONFIG_NAME)
      ->set('enabled', TRUE)
      ->set('allowed_tlds', [])
      ->save();
    self::assertTrue($settings->allowsDomain('neixora.com'));
  }

  /**
   * Admin notification emails are parsed and validated.
   */
  public function testAdminEmailsParsing(): void {
    $settings = $this->container->get('domain_availability.registration_settings');
    self::assertSame([], $settings->adminEmails());

    $this->config(RegistrationSettings::CONFIG_NAME)
      ->set('admin_emails', "a@example.com, not-an-email\nb@example.com")
      ->save();

    self::assertSame(['a@example.com', 'b@example.com'], $settings->adminEmails());
  }

  /**
   * Creates a saved request with the required fields.
   *
   * @param string $domain
   *   The domain.
   *
   * @return \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface
   *   The saved request.
   */
  private function create(string $domain): DomainRegistrationRequestInterface {
    /** @var \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $request */
    $request = $this->storage->create([
      'domain' => $domain,
      'company_name_ar' => 'شركة',
      'company_name_en' => 'Company',
      'national_address' => 'ADDR1234',
      'commercial_registration' => '1010101010',
      'mobile' => '0512345678',
      'national_id' => $this->ids->nationalId(),
    ]);
    $request->save();

    return $request;
  }

}
