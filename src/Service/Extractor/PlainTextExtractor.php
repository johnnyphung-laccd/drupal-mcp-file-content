<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service\Extractor;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\mcp_file_content\Exception\ExtractionException;

/**
 * Extracts content from plain text, CSV, Markdown, and HTML files.
 */
class PlainTextExtractor implements ExtractorInterface {

  /**
   * {@inheritdoc}
   */
  public function getSupportedMimeTypes(): array {
    return [
      'text/plain',
      'text/csv',
      'text/markdown',
      'text/html',
      'text/x-markdown',
      'application/csv',
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

    $content = file_get_contents($filePath);
    if ($content === FALSE) {
      throw new ExtractionException("Failed to read file: {$filePath}");
    }

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $title = pathinfo($filePath, PATHINFO_FILENAME);
    $metadata = [
      'file_size' => filesize($filePath),
      'extension' => $extension,
    ];

    switch ($extension) {
      case 'csv':
        $html = $this->csvToHtml($content);
        break;

      case 'md':
      case 'markdown':
        $html = $this->markdownToHtml($content);
        break;

      case 'html':
      case 'htm':
        $html = $this->sanitizeHtml($content);
        $extractedTitle = $this->extractHtmlTitle($content);
        if ($extractedTitle) {
          $title = $extractedTitle;
        }
        break;

      default:
        $html = '<pre>' . Html::escape($content) . '</pre>';
        break;
    }

    return [
      'content' => $html,
      'title' => $title,
      'metadata' => $metadata,
      'images' => [],
      'structure' => $this->extractStructure($html),
    ];
  }

  /**
   * Converts CSV content to an accessible HTML table.
   */
  protected function csvToHtml(string $csv): string {
    $rows = array_filter(str_getcsv($csv, "\n"));
    if (empty($rows)) {
      return '<p>(Empty CSV file)</p>';
    }

    $html = '<table>';

    foreach ($rows as $index => $row) {
      $cells = str_getcsv($row);
      $html .= '<tr>';
      foreach ($cells as $cell) {
        $escaped = Html::escape(trim($cell));
        if ($index === 0) {
          $html .= '<th scope="col">' . $escaped . '</th>';
        }
        else {
          $html .= '<td>' . $escaped . '</td>';
        }
      }
      $html .= '</tr>';
    }

    $html .= '</table>';
    return $html;
  }

  /**
   * Converts basic Markdown to HTML.
   */
  protected function markdownToHtml(string $markdown): string {
    $lines = explode("\n", $markdown);
    $html = '';
    $inList = FALSE;
    $listType = '';

    foreach ($lines as $line) {
      $trimmed = rtrim($line);

      // Close list if not a list item.
      if ($inList && !preg_match('/^(\s*[-*+]|\s*\d+\.)\s/', $trimmed)) {
        $html .= "</{$listType}>";
        $inList = FALSE;
      }

      // Headings.
      if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches)) {
        $level = strlen($matches[1]);
        $text = Html::escape($matches[2]);
        $html .= "<h{$level}>{$text}</h{$level}>\n";
        continue;
      }

      // Unordered list items.
      if (preg_match('/^\s*[-*+]\s+(.+)$/', $trimmed, $matches)) {
        if (!$inList || $listType !== 'ul') {
          if ($inList) {
            $html .= "</{$listType}>";
          }
          $html .= '<ul>';
          $inList = TRUE;
          $listType = 'ul';
        }
        $html .= '<li>' . Html::escape($matches[1]) . '</li>';
        continue;
      }

      // Ordered list items.
      if (preg_match('/^\s*\d+\.\s+(.+)$/', $trimmed, $matches)) {
        if (!$inList || $listType !== 'ol') {
          if ($inList) {
            $html .= "</{$listType}>";
          }
          $html .= '<ol>';
          $inList = TRUE;
          $listType = 'ol';
        }
        $html .= '<li>' . Html::escape($matches[1]) . '</li>';
        continue;
      }

      // Empty line.
      if (trim($trimmed) === '') {
        continue;
      }

      // Regular paragraph.
      // Apply inline formatting.
      $text = Html::escape($trimmed);
      $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
      $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
      $html .= "<p>{$text}</p>\n";
    }

    if ($inList) {
      $html .= "</{$listType}>";
    }

    return $html;
  }

  /**
   * Sanitizes HTML content.
   */
  protected function sanitizeHtml(string $html): string {
    return Xss::filterAdmin($html);
  }

  /**
   * Extracts the title from HTML content.
   */
  protected function extractHtmlTitle(string $html): ?string {
    if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
      return trim($matches[1]);
    }
    if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $matches)) {
      return trim($matches[1]);
    }
    return NULL;
  }

  /**
   * Extracts document structure from HTML.
   */
  protected function extractStructure(string $html): array {
    $headings = [];
    if (preg_match_all('/<h(\d)[^>]*>(.*?)<\/h\d>/i', $html, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $headings[] = [
          'level' => (int) $match[1],
          'text' => strip_tags($match[2]),
        ];
      }
    }
    return ['headings' => $headings];
  }

}
