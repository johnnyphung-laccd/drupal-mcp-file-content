<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Service;

use Drupal\mcp_file_content\Service\TableAccessibilityChecker;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\TableAccessibilityChecker
 * @group mcp_file_content
 */
class TableAccessibilityCheckerTest extends UnitTestCase {

  protected TableAccessibilityChecker $checker;

  protected function setUp(): void {
    parent::setUp();
    $this->checker = new TableAccessibilityChecker();
  }

  public function testMissingTableHeaders(): void {
    $html = '<table><tr><td>Header</td><td>Header 2</td></tr><tr><td>Data</td><td>Data 2</td></tr></table>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertNotEmpty($result['errors']);
    $this->assertEquals('1.3.1', $result['errors'][0]['criterion']);
  }

  public function testMissingScopeAttribute(): void {
    $html = '<table><tr><th>Name</th><th>Value</th></tr><tr><td>A</td><td>1</td></tr></table>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    // Should report missing scope.
    $scopeErrors = array_filter($result['errors'], fn($e) => str_contains($e['description'], 'scope'));
    $this->assertNotEmpty($scopeErrors);
  }

  public function testProperTablePasses(): void {
    $html = '<table><caption>Data Table</caption><tr><th scope="col">Name</th><th scope="col">Value</th></tr><tr><td>A</td><td>1</td></tr></table>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertEmpty($result['errors']);
  }

  public function testAddScopeAttributes(): void {
    $html = '<table><tr><td>Header 1</td><td>Header 2</td></tr><tr><td>Data</td><td>Data 2</td></tr></table>';
    $dom = $this->loadHtml($html);
    $fixed = $this->checker->addScopeAttributes($dom);
    $output = $fixed->saveHTML();
    $this->assertStringContainsString('<th', $output);
    $this->assertStringContainsString('scope="col"', $output);
  }

  public function testMissingCaption(): void {
    $html = '<table><tr><th scope="col">Name</th></tr><tr><td>A</td></tr></table>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $captionWarnings = array_filter($result['warnings'], fn($w) => str_contains($w['description'], 'caption'));
    $this->assertNotEmpty($captionWarnings);
  }

  public function testLayoutTableSkipped(): void {
    $html = '<table role="presentation"><tr><td>Layout content</td></tr></table>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertEmpty($result['errors']);
  }

  protected function loadHtml(string $html): \DOMDocument {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    return $dom;
  }

}
