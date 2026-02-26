<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\mcp_file_content\Exception\ExtractionException;
use Drupal\mcp_file_content\Exception\FileTooLargeException;
use Drupal\mcp_file_content\Exception\UnsupportedFileTypeException;
use Drupal\mcp_file_content\Service\Extractor\ExtractorInterface;

/**
 * Manages file content extraction by routing to the appropriate extractor.
 */
class FileExtractorManager {

  /**
   * @var \Drupal\mcp_file_content\Service\Extractor\ExtractorInterface[]
   */
  protected array $extractors;

  public function __construct(
    ExtractorInterface $plainTextExtractor,
    ExtractorInterface $pdfExtractor,
    ExtractorInterface $docxExtractor,
    ExtractorInterface $pptxExtractor,
    ExtractorInterface $imageExtractor,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->extractors = [
      $plainTextExtractor,
      $pdfExtractor,
      $docxExtractor,
      $pptxExtractor,
      $imageExtractor,
    ];
  }

  /**
   * Gets the appropriate extractor for a MIME type.
   *
   * @throws \Drupal\mcp_file_content\Exception\UnsupportedFileTypeException
   */
  public function getExtractor(string $mimeType): ExtractorInterface {
    foreach ($this->extractors as $extractor) {
      if ($extractor->supports($mimeType)) {
        return $extractor;
      }
    }
    throw new UnsupportedFileTypeException("No extractor available for MIME type: {$mimeType}");
  }

  /**
   * Extracts content from a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param array $options
   *   Extraction options.
   *
   * @return array
   *   Extracted content data.
   *
   * @throws \Drupal\mcp_file_content\Exception\ExtractionException
   * @throws \Drupal\mcp_file_content\Exception\FileTooLargeException
   * @throws \Drupal\mcp_file_content\Exception\UnsupportedFileTypeException
   */
  public function extractFromMedia(MediaInterface $media, array $options = []): array {
    $file = $this->getFileFromMedia($media);
    if (!$file) {
      throw new ExtractionException("No file found for media entity {$media->id()}.");
    }

    $filePath = $file->getFileUri();
    $realPath = \Drupal::service('file_system')->realpath($filePath);
    if (!$realPath) {
      throw new ExtractionException("Cannot resolve file path: {$filePath}");
    }

    // Check file size.
    $config = $this->configFactory->get('mcp_file_content.settings');
    $maxSize = $config->get('extraction.max_file_size') ?? 52428800;
    $fileSize = $file->getSize();
    if ($fileSize > $maxSize) {
      throw new FileTooLargeException(
        "File size ({$fileSize} bytes) exceeds maximum allowed ({$maxSize} bytes)."
      );
    }

    // Check enabled file types.
    $mimeType = $file->getMimeType();
    $this->checkFileTypeEnabled($mimeType);

    $extractor = $this->getExtractor($mimeType);
    $result = $extractor->extract($realPath, $options);

    // Add source file metadata.
    $result['source_file'] = [
      'media_id' => $media->id(),
      'filename' => $file->getFilename(),
      'mime_type' => $mimeType,
      'file_size' => $fileSize,
      'uri' => $filePath,
    ];

    return $result;
  }

  /**
   * Gets the file entity from a media entity.
   */
  public function getFileFromMedia(MediaInterface $media): ?\Drupal\file\FileInterface {
    $source = $media->getSource();
    $sourceField = $source->getSourceFieldDefinition($media->bundle->entity);
    if (!$sourceField) {
      return NULL;
    }

    $fieldName = $sourceField->getName();
    $fieldValue = $media->get($fieldName);

    if ($fieldValue->isEmpty()) {
      return NULL;
    }

    $item = $fieldValue->first();
    if (!$item) {
      return NULL;
    }

    // For file/image fields, the entity is the file.
    if (method_exists($item, 'get') && $item->get('entity')) {
      $entity = $item->get('entity')->getValue();
      if ($entity instanceof \Drupal\file\FileInterface) {
        return $entity;
      }
    }

    // Fallback: try target_id.
    $targetId = $item->getValue()['target_id'] ?? NULL;
    if ($targetId) {
      return $this->entityTypeManager->getStorage('file')->load($targetId);
    }

    return NULL;
  }

  /**
   * Checks whether the given MIME type is enabled in config.
   *
   * @throws \Drupal\mcp_file_content\Exception\UnsupportedFileTypeException
   */
  protected function checkFileTypeEnabled(string $mimeType): void {
    $config = $this->configFactory->get('mcp_file_content.settings');
    $enabled = $config->get('enabled_file_types') ?? [];

    $typeMap = [
      'application/pdf' => 'pdf',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
      'application/msword' => 'doc',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
      'application/vnd.ms-powerpoint' => 'ppt',
      'image/jpeg' => 'images',
      'image/png' => 'images',
      'image/tiff' => 'images',
      'image/gif' => 'images',
      'image/webp' => 'images',
      'text/plain' => 'text',
      'text/csv' => 'text',
      'text/markdown' => 'text',
      'text/html' => 'text',
      'text/x-markdown' => 'text',
      'application/csv' => 'text',
    ];

    $typeKey = $typeMap[$mimeType] ?? NULL;
    if ($typeKey && !($enabled[$typeKey] ?? TRUE)) {
      throw new UnsupportedFileTypeException("File type '{$typeKey}' is disabled in configuration.");
    }
  }

  /**
   * Returns all supported MIME types across all extractors.
   */
  public function getSupportedMimeTypes(): array {
    $types = [];
    foreach ($this->extractors as $extractor) {
      $types = array_merge($types, $extractor->getSupportedMimeTypes());
    }
    return array_unique($types);
  }

}
