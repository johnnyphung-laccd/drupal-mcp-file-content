<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Service;

use Drupal\mcp_file_content\Service\AltTextRequirementChecker;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\AltTextRequirementChecker
 * @group mcp_file_content
 */
class AltTextRequirementCheckerTest extends UnitTestCase {

  protected AltTextRequirementChecker $checker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->checker = new AltTextRequirementChecker();
  }

  /**
   * Tests missing alt attribute.
   */
  public function testMissingAlt(): void {
    $html = '<img src="photo.jpg">';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);

    $this->assertNotEmpty($result['errors']);
    $this->assertEquals('1.1.1', $result['errors'][0]['criterion']);
    $this->assertStringContainsString('missing the alt', $result['errors'][0]['description']);
  }

  /**
   * Tests proper alt text passes.
   */
  public function testProperAltText(): void {
    $html = '<img src="photo.jpg" alt="A student studying in the library">';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);

    $this->assertEmpty($result['errors']);
  }

  /**
   * Tests empty alt on decorative image.
   */
  public function testDecorativeImage(): void {
    $html = '<img src="border.png" alt="" role="presentation">';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);

    $this->assertEmpty($result['errors']);
    $this->assertEmpty($result['warnings']);
  }

  /**
   * Tests empty alt without decorative role.
   */
  public function testEmptyAltNotDecorative(): void {
    $html = '<img src="photo.jpg" alt="">';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);

    $this->assertNotEmpty($result['warnings']);
    $this->assertStringContainsString('not marked as decorative', $result['warnings'][0]['description']);
  }

  /**
   * Tests alt text that is just a filename.
   */
  public function testFilenameAsAlt(): void {
    $html = '<img src="photo.jpg" alt="IMG_1234.jpg">';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);

    $this->assertNotEmpty($result['errors']);
    $this->assertStringContainsString('filename', $result['errors'][0]['description']);
  }

  /**
   * Tests aria-hidden decorative image.
   */
  public function testAriaHiddenImage(): void {
    $html = '<img src="decoration.png" alt="" aria-hidden="true">';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);

    $this->assertEmpty($result['errors']);
    $this->assertEmpty($result['warnings']);
  }

  /**
   * Helper to load HTML into DOMDocument.
   */
  protected function loadHtml(string $html): \DOMDocument {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    return $dom;
  }

}
