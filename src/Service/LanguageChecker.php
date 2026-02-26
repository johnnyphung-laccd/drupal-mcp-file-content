<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

/**
 * Checks language attribute presence (WCAG 3.1.1, 3.1.2).
 */
class LanguageChecker {

  /**
   * Checks language attribute issues.
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

    // Check for lang attribute on root element.
    $html = $xpath->query('//html');
    if ($html && $html->length > 0) {
      $htmlEl = $html->item(0);
      if (!$htmlEl->hasAttribute('lang') && !$htmlEl->hasAttribute('xml:lang')) {
        $warnings[] = [
          'criterion' => '3.1.1',
          'severity' => 'warning',
          'element' => '<html>',
          'description' => 'The HTML element is missing a lang attribute.',
          'suggestion' => 'Add a lang attribute to the <html> element (e.g., lang="en").',
        ];
      }
    }

    // For content fragments, check if the wrapper element has a lang.
    $body = $xpath->query('//body');
    if ($body && $body->length > 0) {
      $bodyEl = $body->item(0);
      // Check first child div for lang.
      $firstDiv = $xpath->query('./div[@lang]', $bodyEl);
      if ((!$html || $html->length === 0) && (!$firstDiv || $firstDiv->length === 0)) {
        $warnings[] = [
          'criterion' => '3.1.1',
          'severity' => 'warning',
          'element' => '',
          'description' => 'Content fragment has no language identifier. The Drupal theme typically sets this at the page level.',
          'suggestion' => 'Ensure the page template includes lang attribute on the <html> element.',
        ];
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

}
