<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service\Extractor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\mcp_file_content\Exception\ExtractionException;
use Smalot\PdfParser\Parser;

/**
 * Extracts content from PDF files with OCR fallback.
 */
class PdfExtractor implements ExtractorInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSupportedMimeTypes(): array {
    return ['application/pdf'];
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $mimeType): bool {
    return in_array($mimeType, $this->getSupportedMimeTypes(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function extract(string $filePath, array $options = []): array {
    if (!file_exists($filePath) || !is_readable($filePath)) {
      throw new ExtractionException("File not found or not readable: {$filePath}");
    }

    $config = $this->configFactory->get('mcp_file_content.settings');
    $ocrEnabled = $options['ocr_enabled'] ?? $config->get('ocr.auto_detect');

    try {
      $parser = new Parser();
      $pdf = $parser->parseFile($filePath);
      $text = $pdf->getText();
      $details = $pdf->getDetails();
    }
    catch (\Exception $e) {
      throw new ExtractionException("Failed to parse PDF: " . $e->getMessage(), 0, $e);
    }

    $metadata = [
      'pages' => $details['Pages'] ?? NULL,
      'author' => $details['Author'] ?? NULL,
      'creator' => $details['Creator'] ?? NULL,
      'creation_date' => $details['CreationDate'] ?? NULL,
      'title' => $details['Title'] ?? NULL,
    ];

    // OCR fallback for scanned PDFs.
    $textLength = strlen(trim($text));
    $pageCount = $metadata['pages'] ?? 1;
    $avgCharsPerPage = $pageCount > 0 ? $textLength / $pageCount : $textLength;

    if ($ocrEnabled && $avgCharsPerPage < 100) {
      $ocrText = $this->performOcr($filePath, $options);
      if (!empty($ocrText)) {
        $text = $ocrText;
        $metadata['ocr_applied'] = TRUE;
      }
    }

    $title = $metadata['title'] ?? pathinfo($filePath, PATHINFO_FILENAME);
    $html = $this->textToHtml($text);

    return [
      'content' => $html,
      'title' => $title,
      'metadata' => $metadata,
      'images' => [],
      'structure' => $this->extractStructure($text),
    ];
  }

  /**
   * Performs OCR on a scanned PDF using pdftoppm + Tesseract.
   */
  protected function performOcr(string $filePath, array $options): string {
    $config = $this->configFactory->get('mcp_file_content.settings');
    $language = $options['ocr_language'] ?? $config->get('ocr.language') ?? 'eng';
    $tesseractPath = $config->get('ocr.tesseract_path') ?? 'tesseract';

    $tmpDir = sys_get_temp_dir() . '/mcp_ocr_' . uniqid();
    if (!mkdir($tmpDir, 0755, TRUE)) {
      return '';
    }

    try {
      // Convert PDF pages to images.
      $prefix = $tmpDir . '/page';
      $cmd = sprintf(
        'pdftoppm -png -r 300 %s %s 2>&1',
        escapeshellarg($filePath),
        escapeshellarg($prefix)
      );
      exec($cmd, $output, $returnCode);
      if ($returnCode !== 0) {
        return '';
      }

      // OCR each page image.
      $images = glob($tmpDir . '/page-*.png');
      sort($images);
      $fullText = '';

      foreach ($images as $image) {
        $cmd = sprintf(
          '%s %s stdout -l %s 2>/dev/null',
          escapeshellarg($tesseractPath),
          escapeshellarg($image),
          escapeshellarg($language)
        );
        $pageText = shell_exec($cmd);
        if ($pageText) {
          $fullText .= $pageText . "\n\n";
        }
      }

      return trim($fullText);
    }
    finally {
      // Cleanup temp files.
      $files = glob($tmpDir . '/*');
      foreach ($files as $file) {
        @unlink($file);
      }
      @rmdir($tmpDir);
    }
  }

  /**
   * Converts extracted plain text to HTML with basic structure.
   */
  protected function textToHtml(string $text): string {
    $lines = explode("\n", $text);
    $html = '';
    $currentParagraph = '';

    foreach ($lines as $line) {
      $trimmed = trim($line);

      if ($trimmed === '') {
        if ($currentParagraph !== '') {
          $html .= '<p>' . htmlspecialchars($currentParagraph, ENT_QUOTES, 'UTF-8') . '</p>';
          $currentParagraph = '';
        }
        continue;
      }

      // Detect potential headings (all-caps lines or short bold-like lines).
      if (strlen($trimmed) < 80 && $trimmed === mb_strtoupper($trimmed) && preg_match('/[A-Z]/', $trimmed)) {
        if ($currentParagraph !== '') {
          $html .= '<p>' . htmlspecialchars($currentParagraph, ENT_QUOTES, 'UTF-8') . '</p>';
          $currentParagraph = '';
        }
        $html .= '<h2>' . htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8') . '</h2>';
        continue;
      }

      if ($currentParagraph !== '') {
        $currentParagraph .= ' ';
      }
      $currentParagraph .= $trimmed;
    }

    if ($currentParagraph !== '') {
      $html .= '<p>' . htmlspecialchars($currentParagraph, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    return $html;
  }

  /**
   * Extracts document structure from text.
   */
  protected function extractStructure(string $text): array {
    $headings = [];
    $lines = explode("\n", $text);
    foreach ($lines as $line) {
      $trimmed = trim($line);
      if (strlen($trimmed) > 0 && strlen($trimmed) < 80 && $trimmed === mb_strtoupper($trimmed) && preg_match('/[A-Z]/', $trimmed)) {
        $headings[] = ['level' => 2, 'text' => $trimmed];
      }
    }
    return ['headings' => $headings];
  }

}
