<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

/**
 * Checks table accessibility (WCAG 1.3.1).
 */
class TableAccessibilityChecker {

  /**
   * Checks table accessibility issues.
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

    $tables = $xpath->query('//table');
    if (!$tables) {
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    foreach ($tables as $tableIndex => $table) {
      $snippet = '<table>...</table> (table ' . ($tableIndex + 1) . ')';

      // Check if this is a data table vs layout table.
      if ($this->isLayoutTable($table, $xpath)) {
        continue;
      }

      // Check for TH elements.
      $ths = $xpath->query('.//th', $table);
      if (!$ths || $ths->length === 0) {
        $errors[] = [
          'criterion' => '1.3.1',
          'severity' => 'error',
          'element' => $snippet,
          'description' => 'Data table has no header cells (<th>).',
          'suggestion' => 'Add <th> elements to identify header cells in the table.',
          'table_index' => $tableIndex,
        ];
      }
      else {
        // Check TH scope attributes.
        foreach ($ths as $th) {
          if (!$th->hasAttribute('scope')) {
            $thText = trim($th->textContent);
            $thSnippet = '<th>' . htmlspecialchars(substr($thText, 0, 30), ENT_QUOTES, 'UTF-8') . '</th>';
            $errors[] = [
              'criterion' => '1.3.1',
              'severity' => 'error',
              'element' => $thSnippet,
              'description' => 'Table header cell missing scope attribute.',
              'suggestion' => 'Add scope="col" for column headers or scope="row" for row headers.',
              'table_index' => $tableIndex,
            ];
          }
        }
      }

      // Check for caption on data tables.
      $captions = $xpath->query('.//caption', $table);
      if (!$captions || $captions->length === 0) {
        $warnings[] = [
          'criterion' => '1.3.1',
          'severity' => 'warning',
          'element' => $snippet,
          'description' => 'Data table has no caption element.',
          'suggestion' => 'Add a <caption> element to describe the table purpose.',
          'table_index' => $tableIndex,
        ];
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Adds scope attributes to table headers.
   *
   * @param \DOMDocument $dom
   *   The HTML document.
   *
   * @return \DOMDocument
   *   The document with scope attributes added.
   */
  public function addScopeAttributes(\DOMDocument $dom): \DOMDocument {
    $xpath = new \DOMXPath($dom);
    $tables = $xpath->query('//table');

    if (!$tables) {
      return $dom;
    }

    foreach ($tables as $table) {
      if ($this->isLayoutTable($table, $xpath)) {
        continue;
      }

      $rows = $xpath->query('.//tr', $table);
      if (!$rows || $rows->length === 0) {
        continue;
      }

      // Convert first row TDs to THs if no THs exist.
      $existingThs = $xpath->query('.//th', $table);
      if (!$existingThs || $existingThs->length === 0) {
        $firstRow = $rows->item(0);
        $cells = $xpath->query('.//td', $firstRow);
        if ($cells) {
          foreach ($cells as $td) {
            $th = $dom->createElement('th');
            while ($td->firstChild) {
              $th->appendChild($td->firstChild);
            }
            foreach ($td->attributes as $attr) {
              $th->setAttribute($attr->name, $attr->value);
            }
            $th->setAttribute('scope', 'col');
            $td->parentNode->replaceChild($th, $td);
          }
        }
      }
      else {
        // Add scope to existing THs.
        $firstRow = $rows->item(0);
        $firstRowThs = $xpath->query('.//th', $firstRow);

        foreach ($existingThs as $th) {
          if (!$th->hasAttribute('scope')) {
            // Determine if column or row header.
            $isInFirstRow = FALSE;
            if ($firstRowThs) {
              foreach ($firstRowThs as $frTh) {
                if ($frTh->isSameNode($th)) {
                  $isInFirstRow = TRUE;
                  break;
                }
              }
            }

            if ($isInFirstRow) {
              $th->setAttribute('scope', 'col');
            }
            else {
              // Check if it's the first cell in its row.
              $parentRow = $th->parentNode;
              $firstCell = $xpath->query('.//td|.//th', $parentRow);
              if ($firstCell && $firstCell->item(0) && $firstCell->item(0)->isSameNode($th)) {
                $th->setAttribute('scope', 'row');
              }
              else {
                $th->setAttribute('scope', 'col');
              }
            }
          }
        }
      }
    }

    return $dom;
  }

  /**
   * Heuristic to detect layout tables (not data tables).
   */
  protected function isLayoutTable(\DOMElement $table, \DOMXPath $xpath): bool {
    // If role="presentation" or role="none", it's a layout table.
    $role = $table->getAttribute('role');
    if ($role === 'presentation' || $role === 'none') {
      return TRUE;
    }

    // If it has a caption or summary, it's a data table.
    $captions = $xpath->query('.//caption', $table);
    if ($captions && $captions->length > 0) {
      return FALSE;
    }

    if ($table->hasAttribute('summary')) {
      return FALSE;
    }

    // If it has TH elements, it's a data table.
    $ths = $xpath->query('.//th', $table);
    if ($ths && $ths->length > 0) {
      return FALSE;
    }

    // Single-cell tables are likely layout tables.
    $rows = $xpath->query('.//tr', $table);
    if ($rows && $rows->length === 1) {
      $cells = $xpath->query('.//td', $rows->item(0));
      if ($cells && $cells->length === 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
