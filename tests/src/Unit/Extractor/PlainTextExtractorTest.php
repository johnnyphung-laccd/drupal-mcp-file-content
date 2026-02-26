<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Extractor;

use Drupal\mcp_file_content\Exception\ExtractionException;
use Drupal\mcp_file_content\Service\Extractor\PlainTextExtractor;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\Extractor\PlainTextExtractor
 * @group mcp_file_content
 */
class PlainTextExtractorTest extends UnitTestCase {

  protected PlainTextExtractor $extractor;
  protected string $tmpDir;

  protected function setUp(): void {
    parent::setUp();
    $this->extractor = new PlainTextExtractor();
    $this->tmpDir = sys_get_temp_dir() . '/mcp_test_' . uniqid();
    mkdir($this->tmpDir, 0755, TRUE);
  }

  protected function tearDown(): void {
    parent::tearDown();
    $files = glob($this->tmpDir . '/*');
    foreach ($files as $file) {
      @unlink($file);
    }
    @rmdir($this->tmpDir);
  }

  public function testSupportedMimeTypes(): void {
    $this->assertTrue($this->extractor->supports('text/plain'));
    $this->assertTrue($this->extractor->supports('text/csv'));
    $this->assertTrue($this->extractor->supports('text/html'));
    $this->assertFalse($this->extractor->supports('application/pdf'));
  }

  public function testPlainTextExtraction(): void {
    $file = $this->tmpDir . '/test.txt';
    file_put_contents($file, "Hello World\n\nThis is a test.");
    $result = $this->extractor->extract($file);
    $this->assertNotEmpty($result['content']);
    $this->assertEquals('test', $result['title']);
  }

  public function testCsvToTable(): void {
    $file = $this->tmpDir . '/test.csv';
    file_put_contents($file, "Name,Age,City\nAlice,30,LA\nBob,25,NYC");
    $result = $this->extractor->extract($file);
    $this->assertStringContainsString('<table>', $result['content']);
    $this->assertStringContainsString('<th scope="col">', $result['content']);
    $this->assertStringContainsString('Name', $result['content']);
    $this->assertStringContainsString('Alice', $result['content']);
  }

  public function testMarkdownToHtml(): void {
    $file = $this->tmpDir . '/test.md';
    file_put_contents($file, "# Title\n\n## Section\n\nParagraph text.\n\n- Item 1\n- Item 2");
    $result = $this->extractor->extract($file);
    $this->assertStringContainsString('<h1>', $result['content']);
    $this->assertStringContainsString('<h2>', $result['content']);
    $this->assertStringContainsString('<ul>', $result['content']);
    $this->assertStringContainsString('<li>', $result['content']);
  }

  public function testHtmlSanitization(): void {
    $file = $this->tmpDir . '/test.html';
    file_put_contents($file, '<html><head><title>Test Page</title></head><body><h1>Hello</h1><p>Content</p></body></html>');
    $result = $this->extractor->extract($file);
    $this->assertEquals('Test Page', $result['title']);
    $this->assertStringContainsString('Hello', $result['content']);
  }

  public function testFileNotFoundThrows(): void {
    $this->expectException(ExtractionException::class);
    $this->extractor->extract('/nonexistent/file.txt');
  }

  public function testStructureExtraction(): void {
    $file = $this->tmpDir . '/test.md';
    file_put_contents($file, "# Title\n\n## Section One\n\n## Section Two");
    $result = $this->extractor->extract($file);
    $this->assertNotEmpty($result['structure']['headings']);
    $this->assertCount(3, $result['structure']['headings']);
  }

}
