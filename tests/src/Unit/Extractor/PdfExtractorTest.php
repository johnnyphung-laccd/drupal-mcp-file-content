<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Extractor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\mcp_file_content\Exception\ExtractionException;
use Drupal\mcp_file_content\Service\Extractor\PdfExtractor;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\Extractor\PdfExtractor
 * @group mcp_file_content
 */
class PdfExtractorTest extends UnitTestCase {

  protected PdfExtractor $extractor;

  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['ocr.auto_detect', TRUE],
      ['ocr.language', 'eng'],
      ['ocr.tesseract_path', '/usr/bin/tesseract'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $this->extractor = new PdfExtractor($configFactory);
  }

  public function testSupportedMimeTypes(): void {
    $this->assertTrue($this->extractor->supports('application/pdf'));
    $this->assertFalse($this->extractor->supports('text/plain'));
  }

  public function testFileNotFoundThrows(): void {
    $this->expectException(ExtractionException::class);
    $this->extractor->extract('/nonexistent/file.pdf');
  }

  public function testCorruptPdfThrows(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'pdf_test_');
    file_put_contents($tmpFile, 'not a real PDF');
    try {
      $this->expectException(ExtractionException::class);
      $this->extractor->extract($tmpFile);
    }
    finally {
      @unlink($tmpFile);
    }
  }

}
