<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Service;

use Drupal\mcp_file_content\Service\HeadingHierarchyAnalyzer;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\HeadingHierarchyAnalyzer
 * @group mcp_file_content
 */
class HeadingHierarchyAnalyzerTest extends UnitTestCase {

  protected HeadingHierarchyAnalyzer $analyzer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->analyzer = new HeadingHierarchyAnalyzer();
  }

  /**
   * Tests skipped heading levels are detected.
   */
  public function testSkippedHeadingLevels(): void {
    $html = '<h1>Title</h1><h3>Skipped H2</h3>';
    $dom = $this->loadHtml($html);
    $result = $this->analyzer->check($dom);

    $this->assertNotEmpty($result['errors']);
    $found = FALSE;
    foreach ($result['errors'] as $error) {
      if ($error['criterion'] === '1.3.1' && str_contains($error['description'], 'skipped')) {
        $found = TRUE;
      }
    }
    $this->assertTrue($found, 'Skipped heading level should be reported.');
  }

  /**
   * Tests proper hierarchy passes.
   */
  public function testProperHierarchy(): void {
    $html = '<h1>Title</h1><h2>Section</h2><h3>Subsection</h3>';
    $dom = $this->loadHtml($html);
    $result = $this->analyzer->check($dom);

    $skipErrors = array_filter($result['errors'], fn($e) => str_contains($e['description'], 'skipped'));
    $this->assertEmpty($skipErrors);
  }

  /**
   * Tests empty heading detection.
   */
  public function testEmptyHeading(): void {
    $html = '<h1>Title</h1><h2></h2>';
    $dom = $this->loadHtml($html);
    $result = $this->analyzer->check($dom);

    $emptyErrors = array_filter($result['errors'], fn($e) => str_contains($e['description'], 'Empty'));
    $this->assertNotEmpty($emptyErrors);
  }

  /**
   * Tests multiple H1 warning.
   */
  public function testMultipleH1Warning(): void {
    $html = '<h1>First</h1><h1>Second</h1>';
    $dom = $this->loadHtml($html);
    $result = $this->analyzer->check($dom);

    $multiH1 = array_filter($result['warnings'], fn($w) => str_contains($w['description'], 'Multiple H1'));
    $this->assertNotEmpty($multiH1);
  }

  /**
   * Tests heading hierarchy fix.
   */
  public function testFixHierarchy(): void {
    $html = '<h1>Title</h1><h3>Should be H2</h3><h5>Should be H3</h5>';
    $dom = $this->loadHtml($html);
    $fixed = $this->analyzer->fixHierarchy($dom);

    $xpath = new \DOMXPath($fixed);
    $h2 = $xpath->query('//h2');
    $h3 = $xpath->query('//h3');

    $this->assertEquals(1, $h2->length, 'H3 should be corrected to H2.');
    $this->assertEquals(1, $h3->length, 'H5 should be corrected to H3.');
  }

  /**
   * Tests content without headings but many words.
   */
  public function testLongContentWithoutHeadings(): void {
    $words = implode(' ', array_fill(0, 600, 'word'));
    $html = '<p>' . $words . '</p>';
    $dom = $this->loadHtml($html);
    $result = $this->analyzer->check($dom);

    $noHeadingErrors = array_filter($result['errors'], fn($e) => $e['criterion'] === '2.4.10');
    $this->assertNotEmpty($noHeadingErrors);
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
