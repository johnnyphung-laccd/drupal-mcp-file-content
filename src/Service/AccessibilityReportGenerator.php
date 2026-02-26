<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

/**
 * Generates accessibility reports in JSON, HTML, or CSV format.
 */
class AccessibilityReportGenerator {

  public function __construct(
    protected AccessibilityValidator $validator,
  ) {}

  /**
   * Generates a report for a single HTML content.
   */
  public function generateReport(string $html, string $format = 'json', string $title = ''): string {
    $results = $this->validator->validate($html);
    $data = $this->buildReportData($results, $title);

    return match ($format) {
      'html' => $this->formatHtml($data),
      'csv' => $this->formatCsv($data),
      default => $this->formatJson($data),
    };
  }

  /**
   * Generates a batch report for multiple content items.
   */
  public function generateBatchReport(array $items, string $format = 'json'): string {
    $reports = [];
    $totalScore = 0;
    $totalErrors = 0;
    $totalWarnings = 0;

    foreach ($items as $item) {
      $results = $this->validator->validate($item['html']);
      $report = $this->buildReportData($results, $item['title'] ?? '');
      $reports[] = $report;
      $totalScore += $report['score'];
      $totalErrors += count($report['errors']);
      $totalWarnings += count($report['warnings']);
    }

    $batchData = [
      'generated_at' => date('c'),
      'wcag_version' => '2.1',
      'conformance_level' => 'AA',
      'total_items' => count($items),
      'average_score' => count($items) > 0 ? round($totalScore / count($items)) : 0,
      'total_errors' => $totalErrors,
      'total_warnings' => $totalWarnings,
      'items' => $reports,
    ];

    return match ($format) {
      'html' => $this->formatBatchHtml($batchData),
      'csv' => $this->formatBatchCsv($batchData),
      default => json_encode($batchData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    };
  }

  /**
   * Builds report data structure from validation results.
   */
  protected function buildReportData(array $results, string $title): array {
    return [
      'title' => $title,
      'generated_at' => date('c'),
      'wcag_version' => '2.1',
      'conformance_level' => 'AA',
      'score' => $results['score'],
      'compliance_status' => $results['valid'] ? 'compliant' : 'non-compliant',
      'errors' => $results['errors'],
      'warnings' => $results['warnings'],
      'summary' => $results['summary'],
      'remediation_actions' => $results['remediation_actions'],
    ];
  }

  /**
   * Formats report as JSON.
   */
  protected function formatJson(array $data): string {
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Formats report as HTML.
   */
  protected function formatHtml(array $data): string {
    $html = '<div class="accessibility-report">';
    $html .= '<h2>Accessibility Report' . (!empty($data['title']) ? ': ' . htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8') : '') . '</h2>';
    $html .= '<p>Generated: ' . $data['generated_at'] . '</p>';
    $html .= '<p>WCAG Version: ' . $data['wcag_version'] . ' Level ' . $data['conformance_level'] . '</p>';
    $html .= '<p>Score: <strong>' . $data['score'] . '/100</strong></p>';
    $html .= '<p>Status: <strong>' . $data['compliance_status'] . '</strong></p>';

    if (!empty($data['errors'])) {
      $html .= '<h3>Errors (' . count($data['errors']) . ')</h3><ul>';
      foreach ($data['errors'] as $error) {
        $html .= '<li><strong>[' . htmlspecialchars($error['criterion'], ENT_QUOTES, 'UTF-8') . ']</strong> ';
        $html .= htmlspecialchars($error['description'], ENT_QUOTES, 'UTF-8') . '</li>';
      }
      $html .= '</ul>';
    }

    if (!empty($data['warnings'])) {
      $html .= '<h3>Warnings (' . count($data['warnings']) . ')</h3><ul>';
      foreach ($data['warnings'] as $warning) {
        $html .= '<li><strong>[' . htmlspecialchars($warning['criterion'], ENT_QUOTES, 'UTF-8') . ']</strong> ';
        $html .= htmlspecialchars($warning['description'], ENT_QUOTES, 'UTF-8') . '</li>';
      }
      $html .= '</ul>';
    }

    if (!empty($data['remediation_actions'])) {
      $html .= '<h3>Remediation Actions</h3><ul>';
      foreach ($data['remediation_actions'] as $action) {
        $html .= '<li>' . htmlspecialchars($action['action'], ENT_QUOTES, 'UTF-8') . '</li>';
      }
      $html .= '</ul>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Formats report as CSV.
   */
  protected function formatCsv(array $data): string {
    $csv = "Criterion,Severity,Description,Suggestion\n";

    foreach ($data['errors'] as $error) {
      $csv .= $this->csvRow($error);
    }
    foreach ($data['warnings'] as $warning) {
      $csv .= $this->csvRow($warning);
    }

    return $csv;
  }

  /**
   * Formats batch report as HTML.
   */
  protected function formatBatchHtml(array $data): string {
    $html = '<div class="batch-accessibility-report">';
    $html .= '<h2>Batch Accessibility Report</h2>';
    $html .= '<p>Generated: ' . $data['generated_at'] . '</p>';
    $html .= '<p>Items: ' . $data['total_items'] . ' | Average Score: ' . $data['average_score'] . '/100</p>';
    $html .= '<p>Total Errors: ' . $data['total_errors'] . ' | Total Warnings: ' . $data['total_warnings'] . '</p>';

    foreach ($data['items'] as $item) {
      $html .= '<hr>';
      $html .= $this->formatHtml($item);
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Formats batch report as CSV.
   */
  protected function formatBatchCsv(array $data): string {
    $csv = "Title,Criterion,Severity,Description,Suggestion\n";

    foreach ($data['items'] as $item) {
      foreach ($item['errors'] as $error) {
        $csv .= '"' . str_replace('"', '""', $item['title']) . '",' . $this->csvRow($error);
      }
      foreach ($item['warnings'] as $warning) {
        $csv .= '"' . str_replace('"', '""', $item['title']) . '",' . $this->csvRow($warning);
      }
    }

    return $csv;
  }

  /**
   * Creates a CSV row from an issue.
   */
  protected function csvRow(array $issue): string {
    return sprintf(
      '"%s","%s","%s","%s"' . "\n",
      str_replace('"', '""', $issue['criterion'] ?? ''),
      str_replace('"', '""', $issue['severity'] ?? ''),
      str_replace('"', '""', $issue['description'] ?? ''),
      str_replace('"', '""', $issue['suggestion'] ?? '')
    );
  }

}
