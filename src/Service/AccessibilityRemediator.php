<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Applies deterministic server-side accessibility fixes.
 */
class AccessibilityRemediator {

  public function __construct(
    protected HeadingHierarchyAnalyzer $headingAnalyzer,
    protected TableAccessibilityChecker $tableChecker,
    protected ListMarkupChecker $listMarkupChecker,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Remediates accessibility issues in HTML content.
   *
   * @param string $html
   *   The HTML content.
   * @param array $options
   *   Options to control which fixes to apply.
   *
   * @return array
   *   Array with keys:
   *   - content: (string) The remediated HTML.
   *   - fixes_applied: (string[]) List of fix descriptions.
   *   - fix_count: (int) Number of fixes applied.
   */
  public function remediate(string $html, array $options = []): array {
    $config = $this->configFactory->get('mcp_file_content.settings');
    $fixes = [];

    $dom = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(TRUE);
    $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div>' . $html . '</div></body></html>';
    $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    // Fix heading hierarchy.
    $fixHeadings = $options['fix_headings'] ?? $config->get('accessibility.auto_remediate_headings');
    if ($fixHeadings) {
      $beforeHtml = $dom->saveHTML();
      $dom = $this->headingAnalyzer->fixHierarchy($dom);
      if ($dom->saveHTML() !== $beforeHtml) {
        $fixes[] = 'Fixed heading hierarchy (eliminated skipped levels)';
      }
    }

    // Fix table scope attributes.
    $fixTables = $options['fix_tables'] ?? $config->get('accessibility.auto_remediate_tables');
    if ($fixTables) {
      $beforeHtml = $dom->saveHTML();
      $dom = $this->tableChecker->addScopeAttributes($dom);
      if ($dom->saveHTML() !== $beforeHtml) {
        $fixes[] = 'Added scope attributes to table headers';
      }
    }

    // Fix pseudo-lists.
    $fixLists = $options['fix_lists'] ?? $config->get('accessibility.auto_remediate_lists');
    if ($fixLists) {
      $beforeHtml = $dom->saveHTML();
      $dom = $this->listMarkupChecker->convertPseudoLists($dom);
      if ($dom->saveHTML() !== $beforeHtml) {
        $fixes[] = 'Converted pseudo-lists to proper HTML list markup';
      }
    }

    // Fix bold-only paragraphs (convert to headings).
    if ($fixHeadings) {
      $fixCount = $this->fixBoldParagraphs($dom);
      if ($fixCount > 0) {
        $fixes[] = "Converted {$fixCount} bold-only paragraphs to headings";
      }
    }

    // Extract inner HTML.
    $content = $this->getInnerHtml($dom);

    return [
      'content' => $content,
      'fixes_applied' => $fixes,
      'fix_count' => count($fixes),
    ];
  }

  /**
   * Converts bold-only paragraphs to headings.
   */
  protected function fixBoldParagraphs(\DOMDocument $dom): int {
    $xpath = new \DOMXPath($dom);
    $paragraphs = $xpath->query('//p');
    $count = 0;

    if (!$paragraphs) {
      return 0;
    }

    $toReplace = [];
    foreach ($paragraphs as $p) {
      if ($this->isBoldOnlyParagraph($p)) {
        $toReplace[] = $p;
      }
    }

    foreach ($toReplace as $p) {
      $h2 = $dom->createElement('h2');
      // Copy just text content.
      $h2->textContent = $p->textContent;
      $p->parentNode->replaceChild($h2, $p);
      $count++;
    }

    return $count;
  }

  /**
   * Checks if a paragraph contains only bold text.
   */
  protected function isBoldOnlyParagraph(\DOMElement $p): bool {
    $text = trim($p->textContent);
    if (empty($text) || strlen($text) > 120) {
      return FALSE;
    }

    $children = $p->childNodes;
    $allBold = TRUE;
    $hasContent = FALSE;

    foreach ($children as $child) {
      if ($child->nodeType === XML_TEXT_NODE) {
        if (trim($child->textContent) !== '') {
          $allBold = FALSE;
          break;
        }
        continue;
      }

      if ($child->nodeType === XML_ELEMENT_NODE) {
        $tagName = strtolower($child->nodeName);
        if ($tagName === 'strong' || $tagName === 'b') {
          $hasContent = TRUE;
        }
        else {
          $allBold = FALSE;
          break;
        }
      }
    }

    return $allBold && $hasContent;
  }

  /**
   * Extracts inner HTML from a DOMDocument.
   */
  protected function getInnerHtml(\DOMDocument $dom): string {
    $xpath = new \DOMXPath($dom);
    $wrapper = $xpath->query('//body/div');

    if ($wrapper && $wrapper->length > 0) {
      $html = '';
      foreach ($wrapper->item(0)->childNodes as $child) {
        $html .= $dom->saveHTML($child);
      }
      return $html;
    }

    return $dom->saveHTML();
  }

}
