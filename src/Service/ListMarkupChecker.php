<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

/**
 * Checks for pseudo-lists that should use proper list markup (WCAG 1.3.1).
 */
class ListMarkupChecker {

  /**
   * Checks for pseudo-list patterns in paragraph elements.
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

    $paragraphs = $xpath->query('//p');
    if (!$paragraphs) {
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    $listPatterns = [
      '/^\s*[-–—•●○]\s+/' => 'unordered',
      '/^\s*\*\s+/' => 'unordered',
      '/^\s*--\s+/' => 'unordered',
      '/^\s*\d+[.)]\s+/' => 'ordered',
      '/^\s*[a-z][.)]\s+/i' => 'ordered',
    ];

    $consecutiveItems = [];
    $currentGroup = [];

    foreach ($paragraphs as $p) {
      $text = $p->textContent;
      $isListItem = FALSE;

      foreach ($listPatterns as $pattern => $type) {
        if (preg_match($pattern, $text)) {
          $isListItem = TRUE;
          $currentGroup[] = [
            'text' => $text,
            'type' => $type,
            'node' => $p,
          ];
          break;
        }
      }

      if (!$isListItem) {
        if (count($currentGroup) >= 3) {
          $consecutiveItems[] = $currentGroup;
        }
        $currentGroup = [];
      }
    }

    // Handle trailing group.
    if (count($currentGroup) >= 3) {
      $consecutiveItems[] = $currentGroup;
    }

    foreach ($consecutiveItems as $group) {
      $count = count($group);
      $type = $group[0]['type'];
      $firstText = $group[0]['text'];
      $snippet = '<p>' . htmlspecialchars(substr($firstText, 0, 60), ENT_QUOTES, 'UTF-8') . '...</p>';

      $warnings[] = [
        'criterion' => '1.3.1',
        'severity' => 'warning',
        'element' => $snippet,
        'description' => "{$count} consecutive paragraphs look like a pseudo-list. Use proper " . ($type === 'unordered' ? '<ul>' : '<ol>') . " markup.",
        'suggestion' => "Convert these {$count} paragraphs to a proper HTML " . ($type === 'unordered' ? 'unordered' : 'ordered') . " list.",
        'pseudo_list_count' => $count,
        'list_type' => $type,
      ];
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Converts pseudo-lists to proper HTML list markup.
   *
   * @param \DOMDocument $dom
   *   The HTML document.
   *
   * @return \DOMDocument
   *   The document with pseudo-lists converted.
   */
  public function convertPseudoLists(\DOMDocument $dom): \DOMDocument {
    $xpath = new \DOMXPath($dom);
    $paragraphs = $xpath->query('//p');
    if (!$paragraphs) {
      return $dom;
    }

    $listPatterns = [
      '/^\s*[-–—•●○]\s+/' => 'ul',
      '/^\s*\*\s+/' => 'ul',
      '/^\s*--\s+/' => 'ul',
      '/^\s*\d+[.)]\s+/' => 'ol',
      '/^\s*[a-z][.)]\s+/i' => 'ol',
    ];

    // Build groups.
    $groups = [];
    $currentGroup = [];
    $currentType = NULL;
    $pArray = [];
    foreach ($paragraphs as $p) {
      $pArray[] = $p;
    }

    foreach ($pArray as $p) {
      $text = $p->textContent;
      $matched = FALSE;

      foreach ($listPatterns as $pattern => $type) {
        if (preg_match($pattern, $text)) {
          $matched = TRUE;
          if ($currentType === $type) {
            $currentGroup[] = ['node' => $p, 'pattern' => $pattern];
          }
          else {
            if (count($currentGroup) >= 3) {
              $groups[] = ['type' => $currentType, 'items' => $currentGroup];
            }
            $currentGroup = [['node' => $p, 'pattern' => $pattern]];
            $currentType = $type;
          }
          break;
        }
      }

      if (!$matched) {
        if (count($currentGroup) >= 3) {
          $groups[] = ['type' => $currentType, 'items' => $currentGroup];
        }
        $currentGroup = [];
        $currentType = NULL;
      }
    }

    if (count($currentGroup) >= 3) {
      $groups[] = ['type' => $currentType, 'items' => $currentGroup];
    }

    // Convert groups to lists.
    foreach ($groups as $group) {
      $listEl = $dom->createElement($group['type']);
      $firstNode = $group['items'][0]['node'];
      $firstNode->parentNode->insertBefore($listEl, $firstNode);

      foreach ($group['items'] as $item) {
        $node = $item['node'];
        $text = preg_replace($item['pattern'], '', $node->textContent);
        $li = $dom->createElement('li', htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8'));
        $listEl->appendChild($li);
        $node->parentNode->removeChild($node);
      }
    }

    return $dom;
  }

}
