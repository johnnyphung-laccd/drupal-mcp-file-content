<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

/**
 * Checks alt text requirements for images (WCAG 1.1.1).
 */
class AltTextRequirementChecker {

  /**
   * Checks alt text issues.
   *
   * @param \DOMDocument $dom
   *   The HTML document.
   *
   * @return array
   *   Array with 'errors' and 'warnings' keys.
   */
  public function check(\DOMDocument $dom): array {
    $errors = [];
    $warnings = [];
    $xpath = new \DOMXPath($dom);

    $images = $xpath->query('//img');
    if (!$images) {
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    foreach ($images as $index => $img) {
      $snippet = $dom->saveHTML($img);
      if (strlen($snippet) > 200) {
        $snippet = substr($snippet, 0, 200) . '...';
      }

      $hasAlt = $img->hasAttribute('alt');
      $altText = $img->getAttribute('alt');
      $isDecorative = $img->getAttribute('role') === 'presentation'
        || $img->getAttribute('aria-hidden') === 'true';

      if (!$hasAlt) {
        $errors[] = [
          'criterion' => '1.1.1',
          'severity' => 'error',
          'element' => $snippet,
          'description' => "Image at index {$index} is missing the alt attribute.",
          'suggestion' => 'Add an alt attribute with descriptive text, or alt="" for decorative images.',
          'image_index' => $index,
        ];
        continue;
      }

      // Empty alt is OK only for decorative images.
      if ($altText === '' && !$isDecorative) {
        $warnings[] = [
          'criterion' => '1.1.1',
          'severity' => 'warning',
          'element' => $snippet,
          'description' => "Image at index {$index} has empty alt text but is not marked as decorative.",
          'suggestion' => 'Add descriptive alt text, or add role="presentation" if the image is decorative.',
          'image_index' => $index,
        ];
        continue;
      }

      // Alt text is just a filename.
      if (!empty($altText) && $this->isFilename($altText)) {
        $errors[] = [
          'criterion' => '1.1.1',
          'severity' => 'error',
          'element' => $snippet,
          'description' => "Image alt text appears to be a filename: \"{$altText}\".",
          'suggestion' => 'Replace the filename with descriptive alt text that conveys the image content.',
          'image_index' => $index,
        ];
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Checks if the alt text looks like a filename.
   */
  protected function isFilename(string $text): bool {
    $text = trim($text);
    // Common image filename patterns.
    return (bool) preg_match('/^(IMG_|DSC_|DCIM_|Photo_|Screenshot_|image)?\w*\.(jpe?g|png|gif|tiff?|webp|bmp|svg)$/i', $text);
  }

}
