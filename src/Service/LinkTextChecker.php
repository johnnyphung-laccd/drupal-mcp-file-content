<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

/**
 * Checks link text quality (WCAG 2.4.4).
 */
class LinkTextChecker {

  /**
   * Generic link text patterns.
   */
  protected const GENERIC_TEXT = [
    'click here',
    'read more',
    'more',
    'here',
    'link',
    'this',
    'learn more',
    'details',
    'info',
    'continue',
    'go',
  ];

  /**
   * Checks link text issues.
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

    $links = $xpath->query('//a');
    if (!$links) {
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    foreach ($links as $link) {
      $snippet = $dom->saveHTML($link);
      if (strlen($snippet) > 200) {
        $snippet = substr($snippet, 0, 200) . '...';
      }

      $linkText = trim($link->textContent);
      $ariaLabel = $link->getAttribute('aria-label');
      $ariaLabelledBy = $link->getAttribute('aria-labelledby');
      $href = $link->getAttribute('href');

      // Check for accessible name.
      $hasChildImg = $xpath->query('.//img[@alt]', $link);
      $hasAccessibleName = !empty($linkText) || !empty($ariaLabel) || !empty($ariaLabelledBy) || ($hasChildImg && $hasChildImg->length > 0);

      if (!$hasAccessibleName) {
        $errors[] = [
          'criterion' => '2.4.4',
          'severity' => 'error',
          'element' => $snippet,
          'description' => 'Link has no accessible name (no text, aria-label, aria-labelledby, or child image with alt).',
          'suggestion' => 'Add descriptive text to the link or use aria-label to provide an accessible name.',
        ];
        continue;
      }

      // Skip if accessible name comes from aria attributes.
      if (!empty($ariaLabel) || !empty($ariaLabelledBy)) {
        continue;
      }

      // Check for generic text.
      $normalizedText = strtolower(trim($linkText));
      if (in_array($normalizedText, self::GENERIC_TEXT, TRUE)) {
        $warnings[] = [
          'criterion' => '2.4.4',
          'severity' => 'warning',
          'element' => $snippet,
          'description' => "Generic link text: \"{$linkText}\". Link text should describe the destination.",
          'suggestion' => 'Replace with descriptive text that indicates where the link goes or what action it performs.',
        ];
        continue;
      }

      // Check for bare URL as link text.
      if (preg_match('/^https?:\/\//i', $linkText)) {
        $warnings[] = [
          'criterion' => '2.4.4',
          'severity' => 'warning',
          'element' => $snippet,
          'description' => 'Link text is a bare URL. Use descriptive text instead.',
          'suggestion' => 'Replace the URL with descriptive text about the link destination.',
        ];
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

}
