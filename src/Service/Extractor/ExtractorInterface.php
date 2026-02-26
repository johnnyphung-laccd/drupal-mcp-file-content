<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service\Extractor;

/**
 * Interface for file content extractors.
 */
interface ExtractorInterface {

  /**
   * Returns MIME types supported by this extractor.
   *
   * @return string[]
   */
  public function getSupportedMimeTypes(): array;

  /**
   * Checks if this extractor supports the given MIME type.
   *
   * @param string $mimeType
   *   The MIME type to check.
   *
   * @return bool
   */
  public function supports(string $mimeType): bool;

  /**
   * Extracts content from a file.
   *
   * @param string $filePath
   *   Absolute path to the file.
   * @param array $options
   *   Additional extraction options.
   *
   * @return array
   *   Associative array with keys:
   *   - content: (string) The extracted HTML content.
   *   - title: (string) Extracted or inferred title.
   *   - metadata: (array) File metadata (author, pages, etc).
   *   - images: (array) Extracted images as base64 data.
   *   - structure: (array) Document structure info (headings, sections).
   *
   * @throws \Drupal\mcp_file_content\Exception\ExtractionException
   */
  public function extract(string $filePath, array $options = []): array;

}
