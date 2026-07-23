<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Unit;

use Drupal\domain_availability\Exception\ValidationException;
use Drupal\domain_availability\Validator\DomainValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the LDH validation rules.
  *
  * @group domain_availability
  *
  * @covers \Drupal\domain_availability\Validator\DomainValidator::validate
 */
#[Group('domain_availability')]
#[CoversMethod(DomainValidator::class, 'validate')]
final class DomainValidatorTest extends UnitTestCase {

  /**
   * The validator under test.
   *
   * @var \Drupal\domain_availability\Validator\DomainValidator
   */
  private DomainValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new DomainValidator();
  }

  /**
   * Tests labels that must be accepted.
   *
   * @param string $label
   *   The label under test.
    *
    * @dataProvider validProvider
   */
  #[DataProvider('validProvider')]
  public function testValid(string $label): void {
    self::assertSame($label, $this->validator->validate($label));
  }

  /**
   * Tests labels that must be refused.
   *
   * @param string $label
   *   The label under test.
    *
    * @dataProvider invalidProvider
   */
  #[DataProvider('invalidProvider')]
  public function testInvalid(string $label): void {
    $this->expectException(ValidationException::class);
    $this->validator->validate($label);
  }

  /**
   * Valid labels.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function validProvider(): array {
    return [
      'simple' => ['neixora'],
      'single char' => ['a'],
      'digits' => ['123'],
      'inner hyphen' => ['my-brand'],
      'max length' => [str_repeat('a', 63)],
      'punycode' => ['xn--mgbaam7a8h'],
    ];
  }

  /**
   * Invalid labels.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function invalidProvider(): array {
    return [
      'empty' => [''],
      'too long' => [str_repeat('a', 64)],
      'underscore' => ['bad_name'],
      'leading hyphen' => ['-lead'],
      'trailing hyphen' => ['trail-'],
      'xss payload' => ['<script>alert(1)</script>'],
      'sql payload' => ["a' OR 1=1--"],
      'space' => ['two words'],
      'dot' => ['example.com'],
      'unicode' => ['نطاق'],
    ];
  }

  /**
   * Tests that the error carries a per-field message the form can show.
   */
  public function testErrorContext(): void {
    try {
      $this->validator->validate('bad_name');
      self::fail('Expected a ValidationException.');
    }
    catch (ValidationException $exception) {
      self::assertSame(422, $exception->statusCode());
      self::assertSame('validation_error', $exception->errorCode());
      self::assertArrayHasKey('domain', $exception->errors());
    }
  }

}
