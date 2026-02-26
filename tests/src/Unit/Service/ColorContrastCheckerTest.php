<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Service;

use Drupal\mcp_file_content\Service\ColorContrastChecker;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\ColorContrastChecker
 * @group mcp_file_content
 */
class ColorContrastCheckerTest extends UnitTestCase {

  protected ColorContrastChecker $checker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->checker = new ColorContrastChecker();
  }

  /**
   * Tests relative luminance calculation.
   */
  public function testRelativeLuminance(): void {
    // Black = 0.
    $this->assertEqualsWithDelta(0.0, $this->checker->getRelativeLuminance([0, 0, 0]), 0.001);
    // White = 1.
    $this->assertEqualsWithDelta(1.0, $this->checker->getRelativeLuminance([255, 255, 255]), 0.001);
  }

  /**
   * Tests contrast ratio calculation.
   */
  public function testContrastRatio(): void {
    // Black on white = 21:1.
    $ratio = $this->checker->getContrastRatio([0, 0, 0], [255, 255, 255]);
    $this->assertEqualsWithDelta(21.0, $ratio, 0.1);

    // White on white = 1:1.
    $ratio = $this->checker->getContrastRatio([255, 255, 255], [255, 255, 255]);
    $this->assertEqualsWithDelta(1.0, $ratio, 0.1);

    // #999 on #FFF ≈ 2.85:1 (fail AA).
    $ratio = $this->checker->getContrastRatio([153, 153, 153], [255, 255, 255]);
    $this->assertGreaterThan(2.5, $ratio);
    $this->assertLessThan(3.0, $ratio);
  }

  /**
   * Tests color parsing.
   */
  public function testParseColor(): void {
    $this->assertEquals([255, 0, 0], $this->checker->parseColor('#FF0000'));
    $this->assertEquals([255, 0, 0], $this->checker->parseColor('#F00'));
    $this->assertEquals([100, 200, 50], $this->checker->parseColor('rgb(100, 200, 50)'));
    $this->assertEquals([100, 200, 50], $this->checker->parseColor('rgba(100, 200, 50, 0.5)'));
    $this->assertEquals([255, 255, 255], $this->checker->parseColor('white'));
    $this->assertEquals([0, 0, 0], $this->checker->parseColor('black'));
    $this->assertNull($this->checker->parseColor('invalid'));
  }

  /**
   * Tests low contrast detection.
   */
  public function testLowContrastDetection(): void {
    $html = '<span style="color:#999;background-color:#FFF">Low contrast text</span>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);

    $this->assertNotEmpty($result['errors']);
    $this->assertEquals('1.4.3', $result['errors'][0]['criterion']);
  }

  /**
   * Tests good contrast passes.
   */
  public function testGoodContrastPasses(): void {
    $html = '<span style="color:#000;background-color:#FFF">Good contrast text</span>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);

    $this->assertEmpty($result['errors']);
  }

  /**
   * Tests content without inline styles.
   */
  public function testNoInlineStyles(): void {
    $html = '<p>No inline styles here</p>';
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
