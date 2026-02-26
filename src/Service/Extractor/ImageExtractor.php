<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service\Extractor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\mcp_file_content\Exception\ExtractionException;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Extracts content from images using Tesseract OCR.
 */
class ImageExtractor implements ExtractorInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSupportedMimeTypes(): array {
    return [
      'image/jpeg',
      'image/png',
      'image/tiff',
      'image/gif',
      'image/webp',
    ];
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
    $language = $options['ocr_language'] ?? $config->get('ocr.language') ?? 'eng';
    $ocrEnabled = $options['ocr_enabled'] ?? $config->get('ocr.auto_detect');

    $title = pathinfo($filePath, PATHINFO_FILENAME);
    $imageData = file_get_contents($filePath);
    $base64 = base64_encode($imageData);
    $mimeType = mime_content_type($filePath) ?: 'image/png';
    $ocrText = '';
    $metadata = [
      'file_size' => filesize($filePath),
      'mime_type' => $mimeType,
    ];

    // Get image dimensions.
    $imageInfo = @getimagesize($filePath);
    if ($imageInfo) {
      $metadata['width'] = $imageInfo[0];
      $metadata['height'] = $imageInfo[1];
    }

    // Perform OCR.
    if ($ocrEnabled) {
      try {
        $tesseract = new TesseractOCR($filePath);
        $tesseract->lang($language);

        $tesseractPath = $config->get('ocr.tesseract_path');
        if ($tesseractPath) {
          $tesseract->executable($tesseractPath);
        }

        $ocrText = $tesseract->run();
      }
      catch (\Exception $e) {
        // OCR failure is non-fatal for images.
        $metadata['ocr_error'] = $e->getMessage();
      }
    }

    $isImageOfText = strlen(trim($ocrText)) > 100;
    $metadata['ocr_applied'] = $ocrEnabled;
    $metadata['is_image_of_text'] = $isImageOfText;

    $html = '';
    if (!empty($ocrText)) {
      $html .= '<div class="ocr-content">';
      $paragraphs = preg_split('/\n\s*\n/', trim($ocrText));
      foreach ($paragraphs as $para) {
        $para = trim($para);
        if (!empty($para)) {
          $html .= '<p>' . htmlspecialchars($para, ENT_QUOTES, 'UTF-8') . '</p>';
        }
      }
      $html .= '</div>';
    }

    $images = [
      [
        'data' => $base64,
        'mime_type' => $mimeType,
        'filename' => basename($filePath),
        'alt' => '',
        'is_image_of_text' => $isImageOfText,
      ],
    ];

    return [
      'content' => $html,
      'title' => $title,
      'metadata' => $metadata,
      'images' => $images,
      'structure' => ['headings' => []],
    ];
  }

}
