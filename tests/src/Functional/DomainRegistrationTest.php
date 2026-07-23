<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Functional;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\domain_availability\Entity\DomainRegistrationRequestInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\saudi_id_validator\Support\SaudiIdGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the domain registration request feature end to end.
 *
 * Uses the stub provider, so which domains are "available" is deterministic and
 * no registry is contacted.
  *
  * @group domain_availability
  *
  * @runTestsInSeparateProcesses
 */
#[Group('domain_availability')]
#[RunTestsInSeparateProcesses]
final class DomainRegistrationTest extends BrowserTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['domain_availability', 'domain_availability_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';


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

    // Only the stub answers, and only .sa accepts registration (the default).
    $this->config('domain_availability.settings')
      ->set('tlds', ['com', 'sa'])
      ->set('rate_limit_min_interval', 0)
      ->set('rdap_enabled', FALSE)
      ->set('whois_enabled', FALSE)
      ->set('dns_fallback_enabled', FALSE)
      ->save();

    $this->config('domain_availability.registration')
      ->set('admin_emails', 'admin@example.com')
      ->save();

  }

  /**
   * The register button appears only on an available, allowed-TLD card.
   */
  public function testButtonVisibility(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    // free.sa is available and .sa is allowed → button. free.com is available
    // but .com is not allowed → no button.
    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'free'], 'Search');

    $this->assertSession()->pageTextContains('free.sa');
    $this->assertSession()->elementExists('css', '#da-register-free-sa');
    $this->assertSession()->elementNotExists('css', '#da-register-free-com');

    // A registered .sa never shows the button.
    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'taken'], 'Search');
    $this->assertSession()->pageTextContains('taken.sa');
    $this->assertSession()->elementNotExists('css', '#da-register-taken-sa');
  }

  /**
   * The register route is gated by the feature toggle.
   */
  public function testRegisterRouteToggle(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-availability/register/free.sa');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('free.sa');
    $this->assertSession()->pageTextContains('Nafath');

    $this->config('domain_availability.registration')->set('enabled', FALSE)->save();
    $this->drupalGet('/domain-availability/register/free.sa');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A valid submission stores a request and sends both emails.
   */
  public function testSuccessfulSubmission(): void {
    $user = $this->drupalCreateUser(['access domain availability search']);
    $this->drupalLogin($user);

    $this->submitValidRequest('free.sa');

    $requests = $this->storage()->loadByProperties(['domain' => 'free.sa']);
    self::assertCount(1, $requests);

    /** @var \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $request */
    $request = reset($requests);
    self::assertSame(DomainRegistrationRequestInterface::STATUS_PENDING, $request->getStatus());
    self::assertSame('Test Co', $request->get('company_name_en')->value);
    self::assertSame(3, (int) $request->get('registration_years')->value);
    self::assertNotNull($request->getCertificateFile());
    self::assertTrue($request->getCertificateFile()->isPermanent());

    // A confirmation to the customer and a notification to the admin.
    $mails = $this->getMails();
    self::assertCount(2, $mails);
    $recipients = array_column($mails, 'to');
    self::assertContains($user->getEmail(), $recipients);
    self::assertContains('admin@example.com', $recipients);
    self::assertStringContainsString($request->getReferenceNumber(), $mails[0]['subject'] . $mails[0]['body']);
  }

  /**
   * Invalid input is rejected and nothing is stored.
   */
  public function testValidation(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-availability/register/free.sa');
    $this->uploadCertificate();
    $this->submitForm([
      'company_name_ar' => 'شركة',
      'company_name_en' => 'Co',
      'national_address' => 'ADDR',
      'commercial_registration' => '1010101010',
      'mobile' => '12345',
      'national_id' => $this->ids->wrongLength(6),
    ], 'Submit request');

    // Every mistake is reported together, so one submission tells the user
    // everything that is wrong rather than one field at a time.
    $this->assertSession()->pageTextContains('valid Saudi mobile number');
    $this->assertSession()->pageTextContains('10 digits starting with 7');
    $this->assertSession()->pageTextContains('must contain exactly 10 digits');
    self::assertCount(0, $this->storage()->loadByProperties(['domain' => 'free.sa']));
  }

  /**
   * A number of the right shape but with a bad check digit is refused.
   *
   * The point of the checksum: this value passes every test a regular
   * expression could make of it, and is still not a real number.
   */
  public function testNationalIdChecksumIsEnforced(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-availability/register/free.sa');
    $this->submitForm([
      'applicant_type' => DomainRegistrationRequestInterface::APPLICANT_INDIVIDUAL,
      'mobile' => '0512345678',
      'national_id' => $this->ids->wrongChecksum(),
    ], 'Submit request');

    $this->assertSession()->pageTextContains('Checksum validation failed');
    self::assertCount(0, $this->storage()->loadByProperties(['domain' => 'free.sa']));
  }

  /**
   * A Saudi individual may submit with only a mobile and a National ID.
   */
  public function testIndividualSubmission(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-availability/register/free.sa');
    $this->submitForm([
      'applicant_type' => DomainRegistrationRequestInterface::APPLICANT_INDIVIDUAL,
      'registration_years' => '2',
      'mobile' => '0512345678',
      'national_id' => $this->ids->nationalId(),
    ], 'Submit request');

    $requests = $this->storage()->loadByProperties(['domain' => 'free.sa']);
    self::assertCount(1, $requests);

    /** @var \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $request */
    $request = reset($requests);
    self::assertTrue($request->isIndividual());
    self::assertSame(2, (int) $request->get('registration_years')->value);
    self::assertNull($request->getCertificateFile());
  }

  /**
   * An Iqama holder cannot take a .sa domain as an individual.
   */
  public function testIndividualSaudiDomainRequiresCitizenId(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-availability/register/free.sa');
    $this->submitForm([
      'applicant_type' => DomainRegistrationRequestInterface::APPLICANT_INDIVIDUAL,
      'mobile' => '0512345678',
      'national_id' => $this->ids->iqama(),
    ], 'Submit request');

    $this->assertSession()->pageTextContains('must start with 1');
    self::assertCount(0, $this->storage()->loadByProperties(['domain' => 'free.sa']));
  }

  /**
   * Outside .sa an individual is not held to the citizenship rule.
   */
  public function testIndividualIqamaAllowedOutsideSaudiDomain(): void {
    $this->config('domain_availability.registration')
      ->set('allowed_tlds', ['sa', 'com'])
      ->save();

    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-availability/register/free.com');
    $this->submitForm([
      'applicant_type' => DomainRegistrationRequestInterface::APPLICANT_INDIVIDUAL,
      'mobile' => '0512345678',
      'national_id' => $this->ids->iqama(),
    ], 'Submit request');

    self::assertCount(1, $this->storage()->loadByProperties(['domain' => 'free.com']));
  }

  /**
   * A company must supply its documents; an individual's form is not enough.
   */
  public function testCompanyDocumentsAreRequired(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-availability/register/free.sa');
    $this->submitForm([
      'applicant_type' => DomainRegistrationRequestInterface::APPLICANT_COMPANY,
      'mobile' => '0512345678',
    ], 'Submit request');

    $this->assertSession()->pageTextContains('is required for a company applicant');
    self::assertCount(0, $this->storage()->loadByProperties(['domain' => 'free.sa']));
  }

  /**
   * A second request for the same domain inside the window is refused.
   */
  public function testDuplicatePrevention(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->submitValidRequest('free.sa');
    self::assertCount(1, $this->storage()->loadByProperties(['domain' => 'free.sa']));

    $this->drupalGet('/domain-availability/register/free.sa');
    $this->uploadCertificate();
    $this->submitForm($this->validValues(), 'Submit request');

    $this->assertSession()->pageTextContains('already submitted recently');
    self::assertCount(1, $this->storage()->loadByProperties(['domain' => 'free.sa']));
  }

  /**
   * The admin listing, detail, status change and delete are permission-gated.
   */
  public function testAdminWorkflow(): void {
    // Seed one request as an anonymous submission.
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));
    $this->submitValidRequest('free.sa');
    $requests = $this->storage()->loadByProperties(['domain' => 'free.sa']);
    $request = reset($requests);

    // No listing access without the permission.
    $this->drupalGet('/admin/config/system/domain-availability/registration-requests');
    $this->assertSession()->statusCodeEquals(403);

    // A viewer sees the list and the detail, but cannot change status.
    $this->drupalLogin($this->drupalCreateUser(['view domain registration requests']));
    $this->drupalGet('/admin/config/system/domain-availability/registration-requests');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('free.sa');
    $this->assertSession()->pageTextContains($request->getReferenceNumber());

    $this->drupalGet($request->toUrl('canonical')->toString());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Test Co');

    $this->drupalGet('/admin/config/system/domain-availability/registration-requests/' . $request->id() . '/status');
    $this->assertSession()->statusCodeEquals(403);

    // A manager approves it.
    $this->drupalLogin($this->drupalCreateUser(['manage domain registration requests']));
    $this->drupalGet('/admin/config/system/domain-availability/registration-requests/' . $request->id() . '/status');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'status' => DomainRegistrationRequestInterface::STATUS_APPROVED,
      'notes' => 'Verified.',
    ], 'Save');

    $updated = $this->storage()->loadUnchanged($request->id());
    self::assertSame(DomainRegistrationRequestInterface::STATUS_APPROVED, $updated->getStatus());
    self::assertSame('Verified.', $updated->get('notes')->value);

    // Only a deleter can delete.
    $this->drupalGet($request->toUrl('delete-form')->toString());
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['delete domain registration requests']));
    $this->drupalGet($request->toUrl('delete-form')->toString());
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Delete');
    self::assertCount(0, $this->storage()->loadByProperties(['domain' => 'free.sa']));
  }

  /**
   * Bulk actions apply to the selected requests, access-checked per entity.
   */
  public function testBulkOperations(): void {
    // Seed two requests directly: the bulk form acts on stored entities
    // regardless of how they were created.
    $this->createRequest('alpha.sa');
    $this->createRequest('beta.sa');
    self::assertCount(2, $this->storage()->loadMultiple());

    // A manager approves both in one action. Managing implies viewing the list.
    $this->drupalLogin($this->drupalCreateUser([
      'manage domain registration requests',
      'view domain registration requests',
    ]));
    $this->drupalGet('/admin/config/system/domain-availability/registration-requests');

    $ids = array_keys($this->storage()->loadMultiple());
    $edit = ['action' => 'approve'];
    foreach ($ids as $id) {
      $edit['requests[' . $id . '][select]'] = TRUE;
    }
    $this->submitForm($edit, 'Apply to selected');

    // The action persisted on the site under test; reset the runner's static
    // entity cache so the reload sees it (Drupal 10 does not clear it for us).
    $this->storage()->resetCache($ids);
    foreach ($this->storage()->loadMultiple($ids) as $request) {
      self::assertSame(DomainRegistrationRequestInterface::STATUS_APPROVED, $request->getStatus());
    }

    // A manager cannot delete (no delete permission): the entities survive.
    $this->drupalGet('/admin/config/system/domain-availability/registration-requests');
    $edit = ['action' => 'delete'];
    foreach ($ids as $id) {
      $edit['requests[' . $id . '][select]'] = TRUE;
    }
    $this->submitForm($edit, 'Apply to selected');
    $this->storage()->resetCache();
    self::assertCount(2, $this->storage()->loadMultiple());

    // A deleter removes them.
    $this->drupalLogin($this->drupalCreateUser([
      'delete domain registration requests',
      'view domain registration requests',
    ]));
    $this->drupalGet('/admin/config/system/domain-availability/registration-requests');
    $edit = ['action' => 'delete'];
    foreach ($ids as $id) {
      $edit['requests[' . $id . '][select]'] = TRUE;
    }
    $this->submitForm($edit, 'Apply to selected');
    $this->storage()->resetCache();
    self::assertCount(0, $this->storage()->loadMultiple());
  }

  /**
   * The JSON API is untouched: no registration data leaks into it.
   */
  public function testApiUnchanged(): void {
    $this->drupalLogin($this->drupalCreateUser(['use domain availability api']));

    $this->drupalGet('/domain-check', ['query' => ['domain' => 'free']]);
    $this->assertSession()->statusCodeEquals(200);

    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    self::assertTrue($payload['success']);
    self::assertArrayNotHasKey('register', $payload);

    foreach ($payload['results'] as $result) {
      self::assertSame(['domain', 'extension', 'available', 'status', 'provider'], array_keys($result));
    }
  }

  /**
   * Submits a complete, valid request for a domain.
   *
   * @param string $domain
   *   The domain.
   */
  private function submitValidRequest(string $domain): void {
    $this->drupalGet('/domain-availability/register/' . $domain);
    $this->uploadCertificate();
    $this->submitForm($this->validValues(), 'Submit request');
  }

  /**
   * Attaches a PDF to the certificate field and presses its upload button.
   */
  private function uploadCertificate(): void {
    $path = $this->container->get('file_system')->getTempDirectory() . '/cert-' . uniqid() . '.pdf';
    file_put_contents($path, "%PDF-1.4\nTest certificate\n%%EOF");
    $this->submitForm(['files[certificate]' => $path], 'Upload');
  }

  /**
   * Creates a stored request directly, bypassing the form.
   *
   * @param string $domain
   *   The domain.
   */
  private function createRequest(string $domain): void {
    $this->storage()->create([
      'domain' => $domain,
      'company_name_ar' => 'شركة',
      'company_name_en' => 'Seeded Co',
      'national_address' => 'ADDR-1',
      'commercial_registration' => '1010101010',
      'mobile' => '0512345678',
      'national_id' => $this->ids->nationalId(),
    ])->save();
  }

  /**
   * The valid, non-file field values.
   *
   * @return array<string, string>
   *   The values.
   */
  private function validValues(): array {
    return [
      'applicant_type' => DomainRegistrationRequestInterface::APPLICANT_COMPANY,
      'registration_years' => '3',
      'company_name_ar' => 'شركة الاختبار',
      'company_name_en' => 'Test Co',
      'national_address' => 'ADDR-1234',
      'commercial_registration' => '7012345678',
      'mobile' => '0512345678',
      'national_id' => $this->ids->nationalId(),
    ];
  }

  /**
   * The request storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage.
   */
  private function storage(): object {
    return $this->container->get('entity_type.manager')->getStorage('domain_registration_request');
  }

}
