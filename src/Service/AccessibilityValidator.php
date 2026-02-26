<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Orchestrates accessibility validation across all checker services.
 */
class AccessibilityValidator {

  public function __construct(
    protected ColorContrastChecker $contrastChecker,
    protected HeadingHierarchyAnalyzer $headingAnalyzer,
    protected AltTextRequirementChecker $altTextChecker,
    protected LinkTextChecker $linkTextChecker,
    protected ListMarkupChecker $listMarkupChecker,
    protected TableAccessibilityChecker $tableChecker,
    protected LanguageChecker $languageChecker,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Validates HTML content for accessibility issues.
   *
   * @param string $html
   *   The HTML content to validate.
   * @param array $options
   *   Validation options (which checks to enable).
   *
   * @return array
   *   Validation results with keys:
   *   - valid: (bool) Whether content passes validation.
   *   - score: (int) Accessibility score 0-100.
   *   - errors: (array) List of errors.
   *   - warnings: (array) List of warnings.
   *   - summary: (array) Summary by criterion.
   *   - remediation_actions: (array) Suggested fixes.
   */
  public function validate(string $html, array $options = []): array {
    $dom = $this->loadHtml($html);
    $allErrors = [];
    $allWarnings = [];

    $checkers = [
      'check_contrast' => [$this->contrastChecker, 'check'],
      'check_headings' => [$this->headingAnalyzer, 'check'],
      'check_images' => [$this->altTextChecker, 'check'],
      'check_links' => [$this->linkTextChecker, 'check'],
      'check_lists' => [$this->listMarkupChecker, 'check'],
      'check_tables' => [$this->tableChecker, 'check'],
      'check_language' => [$this->languageChecker, 'check'],
    ];

    foreach ($checkers as $optionKey => $checker) {
      if (isset($options[$optionKey]) && $options[$optionKey] === FALSE) {
        continue;
      }

      $result = call_user_func($checker, $dom);
      $allErrors = array_merge($allErrors, $result['errors'] ?? []);
      $allWarnings = array_merge($allWarnings, $result['warnings'] ?? []);
    }

    $score = $this->calculateScore($allErrors, $allWarnings);
    $config = $this->configFactory->get('mcp_file_content.settings');
    $strictness = $options['strict_mode'] ?? ($config->get('accessibility.strictness') === 'strict');
    $passThreshold = $strictness ? 95 : 70;

    return [
      'valid' => $score >= $passThreshold && empty($allErrors),
      'score' => $score,
      'errors' => $allErrors,
      'warnings' => $allWarnings,
      'summary' => $this->buildSummary($allErrors, $allWarnings),
      'remediation_actions' => $this->buildRemediationActions($allErrors, $allWarnings),
    ];
  }

  /**
   * Loads HTML into a DOMDocument with proper handling of fragments.
   */
  public function loadHtml(string $html): \DOMDocument {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(TRUE);

    // Wrap in a div to handle fragments, use UTF-8 meta.
    $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div>' . $html . '</div></body></html>';
    $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    libxml_clear_errors();
    return $dom;
  }

  /**
   * Extracts inner HTML from a DOMDocument back to a string.
   */
  public function getInnerHtml(\DOMDocument $dom): string {
    $xpath = new \DOMXPath($dom);
    $wrapper = $xpath->query('//body/div');

    if ($wrapper && $wrapper->length > 0) {
      $html = '';
      foreach ($wrapper->item(0)->childNodes as $child) {
        $html .= $dom->saveHTML($child);
      }
      return $html;
    }

    $body = $xpath->query('//body');
    if ($body && $body->length > 0) {
      $html = '';
      foreach ($body->item(0)->childNodes as $child) {
        $html .= $dom->saveHTML($child);
      }
      return $html;
    }

    return $dom->saveHTML();
  }

  /**
   * Calculates accessibility score.
   */
  protected function calculateScore(array $errors, array $warnings): int {
    $score = 100;

    $penaltyMap = [
      '1.1.1' => -5,
      '1.3.1' => -3,
      '1.4.3' => -3,
      '2.4.4' => -2,
      '2.4.6' => -2,
      '2.4.10' => -5,
      '3.1.1' => -2,
      '3.1.2' => -2,
      '4.1.1' => -2,
    ];

    foreach ($errors as $error) {
      $criterion = $error['criterion'] ?? '';
      $penalty = $penaltyMap[$criterion] ?? -3;
      $score += $penalty;
    }

    foreach ($warnings as $warning) {
      $score -= 1;
    }

    return max(0, min(100, $score));
  }

  /**
   * Builds a summary grouped by WCAG criterion.
   */
  protected function buildSummary(array $errors, array $warnings): array {
    $summary = [];

    foreach (array_merge($errors, $warnings) as $issue) {
      $criterion = $issue['criterion'] ?? 'unknown';
      if (!isset($summary[$criterion])) {
        $summary[$criterion] = [
          'criterion' => $criterion,
          'error_count' => 0,
          'warning_count' => 0,
        ];
      }

      if ($issue['severity'] === 'error') {
        $summary[$criterion]['error_count']++;
      }
      else {
        $summary[$criterion]['warning_count']++;
      }
    }

    return array_values($summary);
  }

  /**
   * Builds a list of remediation actions.
   */
  protected function buildRemediationActions(array $errors, array $warnings): array {
    $actions = [];
    $seen = [];

    foreach (array_merge($errors, $warnings) as $issue) {
      $suggestion = $issue['suggestion'] ?? '';
      if (!empty($suggestion) && !in_array($suggestion, $seen, TRUE)) {
        $seen[] = $suggestion;
        $actions[] = [
          'criterion' => $issue['criterion'] ?? '',
          'severity' => $issue['severity'] ?? 'warning',
          'action' => $suggestion,
        ];
      }
    }

    return $actions;
  }

}
