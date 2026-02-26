<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Service;

use Drupal\mcp_file_content\Service\LinkTextChecker;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\LinkTextChecker
 * @group mcp_file_content
 */
class LinkTextCheckerTest extends UnitTestCase {

  protected LinkTextChecker $checker;

  protected function setUp(): void {
    parent::setUp();
    $this->checker = new LinkTextChecker();
  }

  public function testGenericLinkText(): void {
    $html = '<a href="http://example.com">click here</a>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertNotEmpty($result['warnings']);
    $this->assertEquals('2.4.4', $result['warnings'][0]['criterion']);
  }

  public function testBareUrlAsText(): void {
    $html = '<a href="http://example.com">http://example.com</a>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertNotEmpty($result['warnings']);
  }

  public function testEmptyLink(): void {
    $html = '<a href="http://example.com"></a>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertNotEmpty($result['errors']);
  }

  public function testDescriptiveLinkText(): void {
    $html = '<a href="http://example.com">View the full campus resource guide</a>';
    $dom = $this->loadHtml($html);
    $result = $this->checker->check($dom);
    $this->assertEmpty($result['errors']);
    $this->assertEmpty($result['warnings']);
  }

  public function testLinkWithAriaLabel(): void {
    $html = '<a href="http://example.com" aria-label="View campus guide"></a>';
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
