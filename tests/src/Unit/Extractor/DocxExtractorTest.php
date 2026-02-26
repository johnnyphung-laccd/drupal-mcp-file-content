<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Extractor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\mcp_file_content\Exception\ExtractionException;
use Drupal\mcp_file_content\Service\Extractor\DocxExtractor;
use Drupal\Tests\UnitTestCase;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\Extractor\DocxExtractor
 * @group mcp_file_content
 */
class DocxExtractorTest extends UnitTestCase {

  protected DocxExtractor $extractor;
  protected string $tmpDir;

  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['extraction.libreoffice_path', '/usr/bin/soffice'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $this->extractor = new DocxExtractor($configFactory);
    $this->tmpDir = sys_get_temp_dir() . '/mcp_docx_test_' . uniqid();
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
    $this->assertTrue($this->extractor->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    $this->assertTrue($this->extractor->supports('application/msword'));
    $this->assertFalse($this->extractor->supports('application/pdf'));
  }

  public function testFileNotFoundThrows(): void {
    $this->expectException(ExtractionException::class);
    $this->extractor->extract('/nonexistent/file.docx');
  }

  public function testDocxExtraction(): void {
    // Create a test DOCX file with proper title styles.
    $phpWord = new PhpWord();
    $phpWord->addTitleStyle(1, ['bold' => TRUE, 'size' => 24]);
    $phpWord->addTitleStyle(2, ['bold' => TRUE, 'size' => 18]);
    $section = $phpWord->addSection();
    $section->addTitle('Test Document Title', 1);
    $section->addText('This is a paragraph of text.');
    $section->addTitle('Section Two', 2);
    $section->addText('More content here.');

    $file = $this->tmpDir . '/test.docx';
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($file);

    $result = $this->extractor->extract($file);

    $this->assertNotEmpty($result['content']);
    $this->assertStringContainsString('Test Document Title', $result['content']);
    $this->assertStringContainsString('Section Two', $result['content']);
    $this->assertStringContainsString('paragraph of text', $result['content']);
    $this->assertNotEmpty($result['structure']['headings']);
  }

  public function testDocxTableExtraction(): void {
    $phpWord = new PhpWord();
    $section = $phpWord->addSection();
    $table = $section->addTable();
    $table->addRow();
    $table->addCell()->addText('Name');
    $table->addCell()->addText('Value');
    $table->addRow();
    $table->addCell()->addText('Alice');
    $table->addCell()->addText('100');

    $file = $this->tmpDir . '/table.docx';
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($file);

    $result = $this->extractor->extract($file);

    $this->assertStringContainsString('<table>', $result['content']);
    $this->assertStringContainsString('<th scope="col">', $result['content']);
    $this->assertStringContainsString('Alice', $result['content']);
  }

}
