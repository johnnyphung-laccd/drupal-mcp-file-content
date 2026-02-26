<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Service;

use Drupal\mcp_file_content\Service\ListMarkupChecker;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\ListMarkupChecker
 * @group mcp_file_content
 */
class ListMarkupCheckerTest extends UnitTestCase {

  protected ListMarkupChecker $checker;

  protected function setUp(): void {
    parent::setUp();
    $this->checker = new ListMarkupChecker();
  }

  public function testPseudoListDetection(): void {
    $html = '<p>- Item one</p><p>- Item two</p><p>- Item three</p>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertNotEmpty($result['warnings']);
    $this->assertEquals('1.3.1', $result['warnings'][0]['criterion']);
    $this->assertStringContainsString('pseudo-list', $result['warnings'][0]['description']);
  }

  public function testNumberedPseudoList(): void {
    $html = '<p>1. First item</p><p>2. Second item</p><p>3. Third item</p>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertNotEmpty($result['warnings']);
  }

  public function testTwoItemsNotEnough(): void {
    $html = '<p>- Item one</p><p>- Item two</p>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertEmpty($result['warnings']);
  }

  public function testProperListPasses(): void {
    $html = '<ul><li>Item one</li><li>Item two</li><li>Item three</li></ul>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertEmpty($result['warnings']);
  }

  public function testConvertPseudoLists(): void {
    $html = '<p>- Item one</p><p>- Item two</p><p>- Item three</p>';
    $dom = $this->loadHtml($html);
    $fixed = $this->checker->convertPseudoLists($dom);
    $output = $fixed->saveHTML();
    $this->assertStringContainsString('<ul>', $output);
    $this->assertStringContainsString('<li>', $output);
  }

  protected function loadHtml(string $html): \DOMDocument {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    return $dom;
  }

}
