<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

/**
 * Analyzes and fixes heading hierarchy (WCAG 1.3.1, 2.4.6, 2.4.10).
 */
class HeadingHierarchyAnalyzer {

  /**
   * Checks heading hierarchy issues.
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

    $headingNodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
    if (!$headingNodes || $headingNodes->length === 0) {
      // Check word count to see if headings are needed.
      $body = $xpath->query('//body');
      $text = $body && $body->length > 0 ? $body->item(0)->textContent : $dom->textContent;
      $wordCount = str_word_count($text);

      if ($wordCount > 500) {
        $errors[] = [
          'criterion' => '2.4.10',
          'severity' => 'error',
          'element' => '',
          'description' => "Content has {$wordCount} words but no headings. Long content should be organized with headings.",
          'suggestion' => 'Add heading elements (h1-h6) to break up and organize the content.',
        ];
      }
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    $headings = [];
    $h1Count = 0;

    foreach ($headingNodes as $node) {
      $level = (int) substr($node->tagName, 1);
      $text = trim($node->textContent);
      $headings[] = ['level' => $level, 'text' => $text, 'node' => $node];

      if ($level === 1) {
        $h1Count++;
      }
    }

    // Check: no H1 present.
    if ($h1Count === 0) {
      $warnings[] = [
        'criterion' => '2.4.6',
        'severity' => 'warning',
        'element' => '',
        'description' => 'No H1 heading found in the content.',
        'suggestion' => 'Add an H1 heading to establish the main topic of the content.',
      ];
    }

    // Check: multiple H1s.
    if ($h1Count > 1) {
      $warnings[] = [
        'criterion' => '2.4.6',
        'severity' => 'warning',
        'element' => '',
        'description' => "Multiple H1 headings found ({$h1Count}). A page should typically have one H1.",
        'suggestion' => 'Consider using only one H1 and converting others to H2 or lower.',
      ];
    }

    // Generic heading text patterns.
    $genericPatterns = [
      '/^introduction$/i',
      '/^conclusion$/i',
      '/^overview$/i',
      '/^summary$/i',
      '/^untitled$/i',
      '/^heading$/i',
      '/^section\s*\d*$/i',
    ];

    $prevLevel = 0;
    foreach ($headings as $heading) {
      $level = $heading['level'];
      $text = $heading['text'];
      $snippet = '<h' . $level . '>' . htmlspecialchars(substr($text, 0, 60), ENT_QUOTES, 'UTF-8') . '</h' . $level . '>';

      // Empty heading.
      if (empty($text)) {
        $errors[] = [
          'criterion' => '2.4.6',
          'severity' => 'error',
          'element' => $snippet,
          'description' => "Empty H{$level} heading found.",
          'suggestion' => 'Add meaningful text to the heading or remove it if not needed.',
        ];
        continue;
      }

      // Skipped levels.
      if ($prevLevel > 0 && $level > $prevLevel + 1) {
        $errors[] = [
          'criterion' => '1.3.1',
          'severity' => 'error',
          'element' => $snippet,
          'description' => "Heading level skipped from H{$prevLevel} to H{$level}.",
          'suggestion' => "Change to H" . ($prevLevel + 1) . " to maintain proper heading hierarchy.",
        ];
      }

      // Generic headings.
      foreach ($genericPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
          $warnings[] = [
            'criterion' => '2.4.6',
            'severity' => 'warning',
            'element' => $snippet,
            'description' => "Generic heading text: \"{$text}\". Headings should be descriptive.",
            'suggestion' => 'Use a more descriptive heading that conveys the section content.',
          ];
          break;
        }
      }

      $prevLevel = $level;
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Fixes heading hierarchy by eliminating skipped levels.
   *
   * @param \DOMDocument $dom
   *   The HTML document to fix.
   *
   * @return \DOMDocument
   *   The fixed document.
   */
  public function fixHierarchy(\DOMDocument $dom): \DOMDocument {
    $xpath = new \DOMXPath($dom);
    $headingNodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');

    if (!$headingNodes || $headingNodes->length === 0) {
      return $dom;
    }

    // Collect all headings with their levels.
    $headings = [];
    foreach ($headingNodes as $node) {
      $level = (int) substr($node->tagName, 1);
      $headings[] = ['level' => $level, 'node' => $node];
    }

    // Calculate correct levels.
    $correctedLevels = $this->calculateCorrectedLevels($headings);

    // Apply corrections.
    foreach ($headings as $i => $heading) {
      $newLevel = $correctedLevels[$i];
      if ($newLevel !== $heading['level']) {
        $newTag = 'h' . $newLevel;
        $oldNode = $heading['node'];
        $newNode = $dom->createElement($newTag);

        // Copy children.
        while ($oldNode->firstChild) {
          $newNode->appendChild($oldNode->firstChild);
        }

        // Copy attributes.
        foreach ($oldNode->attributes as $attr) {
          $newNode->setAttribute($attr->name, $attr->value);
        }

        $oldNode->parentNode->replaceChild($newNode, $oldNode);
      }
    }

    return $dom;
  }

  /**
   * Calculates corrected heading levels.
   */
  protected function calculateCorrectedLevels(array $headings): array {
    $corrected = [];
    $prevCorrected = 0;

    foreach ($headings as $heading) {
      $level = $heading['level'];

      if ($prevCorrected === 0) {
        $corrected[] = $level;
        $prevCorrected = $level;
        continue;
      }

      if ($level <= $prevCorrected) {
        $corrected[] = $level;
        $prevCorrected = $level;
      }
      elseif ($level > $prevCorrected + 1) {
        $newLevel = $prevCorrected + 1;
        $corrected[] = min(6, $newLevel);
        $prevCorrected = min(6, $newLevel);
      }
      else {
        $corrected[] = $level;
        $prevCorrected = $level;
      }
    }

    return $corrected;
  }

}
