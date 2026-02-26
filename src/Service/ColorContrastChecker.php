<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

/**
 * Checks color contrast against WCAG 2.1 requirements (1.4.3).
 */
class ColorContrastChecker {

  /**
   * CSS named colors map.
   */
  protected const NAMED_COLORS = [
    'black' => '#000000', 'white' => '#FFFFFF', 'red' => '#FF0000',
    'green' => '#008000', 'blue' => '#0000FF', 'yellow' => '#FFFF00',
    'cyan' => '#00FFFF', 'magenta' => '#FF00FF', 'silver' => '#C0C0C0',
    'gray' => '#808080', 'grey' => '#808080', 'maroon' => '#800000',
    'olive' => '#808000', 'lime' => '#00FF00', 'aqua' => '#00FFFF',
    'teal' => '#008080', 'navy' => '#000080', 'fuchsia' => '#FF00FF',
    'purple' => '#800080', 'orange' => '#FFA500', 'brown' => '#A52A2A',
    'pink' => '#FFC0CB', 'coral' => '#FF7F50', 'tomato' => '#FF6347',
    'gold' => '#FFD700', 'khaki' => '#F0E68C', 'plum' => '#DDA0DD',
    'violet' => '#EE82EE', 'indigo' => '#4B0082', 'ivory' => '#FFFFF0',
    'linen' => '#FAF0E6', 'beige' => '#F5F5DC', 'wheat' => '#F5DEB3',
    'tan' => '#D2B48C', 'chocolate' => '#D2691E', 'firebrick' => '#B22222',
    'crimson' => '#DC143C', 'salmon' => '#FA8072', 'orangered' => '#FF4500',
    'darkred' => '#8B0000', 'darkgreen' => '#006400', 'darkblue' => '#00008B',
    'darkgray' => '#A9A9A9', 'darkgrey' => '#A9A9A9',
    'lightgray' => '#D3D3D3', 'lightgrey' => '#D3D3D3',
    'dimgray' => '#696969', 'dimgrey' => '#696969',
    'gainsboro' => '#DCDCDC', 'whitesmoke' => '#F5F5F5',
    'lavender' => '#E6E6FA', 'mistyrose' => '#FFE4E1',
    'antiquewhite' => '#FAEBD7', 'papayawhip' => '#FFEFD5',
    'blanchedalmond' => '#FFEBCD', 'bisque' => '#FFE4C4',
    'peachpuff' => '#FFDAB9', 'navajowhite' => '#FFDEAD',
    'moccasin' => '#FFE4B5', 'cornsilk' => '#FFF8DC',
    'lemonchiffon' => '#FFFACD', 'lightyellow' => '#FFFFE0',
    'lightgoldenrodyellow' => '#FAFAD2', 'honeydew' => '#F0FFF0',
    'mintcream' => '#F5FFFA', 'azure' => '#F0FFFF',
    'aliceblue' => '#F0F8FF', 'ghostwhite' => '#F8F8FF',
    'snow' => '#FFFAFA', 'seashell' => '#FFF5EE', 'floralwhite' => '#FFFAF0',
    'oldlace' => '#FDF5E6', 'cornflowerblue' => '#6495ED',
    'royalblue' => '#4169E1', 'steelblue' => '#4682B4',
    'dodgerblue' => '#1E90FF', 'deepskyblue' => '#00BFFF',
    'skyblue' => '#87CEEB', 'lightskyblue' => '#87CEFA',
    'lightblue' => '#ADD8E6', 'powderblue' => '#B0E0E6',
    'cadetblue' => '#5F9EA0', 'darkslategray' => '#2F4F4F',
    'darkslategrey' => '#2F4F4F', 'slategray' => '#708090',
    'slategrey' => '#708090', 'lightslategray' => '#778899',
    'lightslategrey' => '#778899', 'mediumblue' => '#0000CD',
    'midnightblue' => '#191970', 'darkcyan' => '#008B8B',
    'lightcyan' => '#E0FFFF', 'darkturquoise' => '#00CED1',
    'turquoise' => '#40E0D0', 'mediumturquoise' => '#48D1CC',
    'paleturquoise' => '#AFEEEE', 'aquamarine' => '#7FFFD4',
    'mediumaquamarine' => '#66CDAA', 'mediumseagreen' => '#3CB371',
    'seagreen' => '#2E8B57', 'forestgreen' => '#228B22',
    'limegreen' => '#32CD32', 'lightgreen' => '#90EE90',
    'palegreen' => '#98FB98', 'darkseagreen' => '#8FBC8F',
    'springgreen' => '#00FF7F', 'mediumspringgreen' => '#00FA9A',
    'lawngreen' => '#7CFC00', 'chartreuse' => '#7FFF00',
    'greenyellow' => '#ADFF2F', 'yellowgreen' => '#9ACD32',
    'olivedrab' => '#6B8E23', 'darkolivegreen' => '#556B2F',
    'darkkhaki' => '#BDB76B', 'goldenrod' => '#DAA520',
    'darkgoldenrod' => '#B8860B', 'sandybrown' => '#F4A460',
    'peru' => '#CD853F', 'sienna' => '#A0522D', 'saddlebrown' => '#8B4513',
    'rosybrown' => '#BC8F8F', 'indianred' => '#CD5C5C',
    'darksalmon' => '#E9967A', 'lightsalmon' => '#FFA07A',
    'lightcoral' => '#F08080', 'hotpink' => '#FF69B4',
    'deeppink' => '#FF1493', 'mediumvioletred' => '#C71585',
    'palevioletred' => '#DB7093', 'orchid' => '#DA70D6',
    'mediumorchid' => '#BA55D3', 'darkorchid' => '#9932CC',
    'darkviolet' => '#9400D3', 'blueviolet' => '#8A2BE2',
    'mediumpurple' => '#9370DB', 'mediumslateblue' => '#7B68EE',
    'slateblue' => '#6A5ACD', 'darkslateblue' => '#483D8B',
    'rebeccapurple' => '#663399', 'thistle' => '#D8BFD8',
  ];

  /**
   * Checks color contrast issues in HTML content.
   *
   * @param \DOMDocument $dom
   *   The HTML document to check.
   *
   * @return array
   *   Array with 'errors' and 'warnings' keys.
   */
  public function check(\DOMDocument $dom): array {
    $errors = [];
    $warnings = [];
    $xpath = new \DOMXPath($dom);

    $elements = $xpath->query('//*[@style]');
    if (!$elements) {
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    foreach ($elements as $element) {
      $style = $element->getAttribute('style');
      $fgColor = $this->extractStyleColor($style, 'color');
      $bgColor = $this->extractStyleColor($style, 'background-color')
        ?? $this->extractStyleColor($style, 'background');

      if ($fgColor === NULL || $bgColor === NULL) {
        continue;
      }

      $fgRgb = $this->parseColor($fgColor);
      $bgRgb = $this->parseColor($bgColor);

      if ($fgRgb === NULL || $bgRgb === NULL) {
        continue;
      }

      $ratio = $this->getContrastRatio($fgRgb, $bgRgb);
      $isLargeText = $this->isLargeText($element);
      $requiredRatio = $isLargeText ? 3.0 : 4.5;

      if ($ratio < $requiredRatio) {
        $snippet = $this->getElementSnippet($element);
        $errors[] = [
          'criterion' => '1.4.3',
          'severity' => 'error',
          'element' => $snippet,
          'description' => sprintf(
            'Insufficient color contrast ratio %.2f:1 (required %.1f:1 for %s text). Foreground: %s, Background: %s.',
            $ratio,
            $requiredRatio,
            $isLargeText ? 'large' : 'normal',
            $fgColor,
            $bgColor
          ),
          'suggestion' => 'Increase the contrast between text and background colors to meet WCAG 2.1 AA requirements.',
          'contrast_ratio' => round($ratio, 2),
          'required_ratio' => $requiredRatio,
        ];
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Extracts a color value from an inline style string.
   */
  protected function extractStyleColor(string $style, string $property): ?string {
    $pattern = '/' . preg_quote($property, '/') . '\s*:\s*([^;]+)/i';
    if (preg_match($pattern, $style, $matches)) {
      return trim($matches[1]);
    }
    return NULL;
  }

  /**
   * Parses a CSS color string into [r, g, b] (0-255).
   */
  public function parseColor(string $color): ?array {
    $color = trim(strtolower($color));

    // Named colors.
    if (isset(self::NAMED_COLORS[$color])) {
      $color = self::NAMED_COLORS[$color];
    }

    // #RRGGBB.
    if (preg_match('/^#([0-9a-f]{6})$/i', $color, $m)) {
      return [
        hexdec(substr($m[1], 0, 2)),
        hexdec(substr($m[1], 2, 2)),
        hexdec(substr($m[1], 4, 2)),
      ];
    }

    // #RGB.
    if (preg_match('/^#([0-9a-f]{3})$/i', $color, $m)) {
      return [
        hexdec($m[1][0] . $m[1][0]),
        hexdec($m[1][1] . $m[1][1]),
        hexdec($m[1][2] . $m[1][2]),
      ];
    }

    // rgb(r, g, b) or rgba(r, g, b, a).
    if (preg_match('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $color, $m)) {
      return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    return NULL;
  }

  /**
   * Calculates the contrast ratio between two colors.
   *
   * @param array $rgb1
   *   First color [r, g, b].
   * @param array $rgb2
   *   Second color [r, g, b].
   *
   * @return float
   *   The contrast ratio (1 to 21).
   */
  public function getContrastRatio(array $rgb1, array $rgb2): float {
    $l1 = $this->getRelativeLuminance($rgb1);
    $l2 = $this->getRelativeLuminance($rgb2);

    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);

    return ($lighter + 0.05) / ($darker + 0.05);
  }

  /**
   * Calculates WCAG relative luminance.
   *
   * @param array $rgb
   *   Color as [r, g, b] (0-255).
   *
   * @return float
   *   Relative luminance (0 to 1).
   */
  public function getRelativeLuminance(array $rgb): float {
    $channels = [];
    foreach ($rgb as $val) {
      $srgb = $val / 255;
      $channels[] = $srgb <= 0.04045
        ? $srgb / 12.92
        : pow(($srgb + 0.055) / 1.055, 2.4);
    }

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
  }

  /**
   * Determines if text is "large" per WCAG (18pt or 14pt bold).
   */
  protected function isLargeText(\DOMElement $element): bool {
    $style = $element->getAttribute('style');
    $fontSize = NULL;
    $isBold = FALSE;

    if (preg_match('/font-size\s*:\s*([\d.]+)\s*(px|pt|em|rem)/i', $style, $m)) {
      $size = (float) $m[1];
      $unit = strtolower($m[2]);
      $fontSize = match ($unit) {
        'pt' => $size,
        'px' => $size * 0.75,
        'em', 'rem' => $size * 12,
        default => NULL,
      };
    }

    if (preg_match('/font-weight\s*:\s*(bold|[7-9]\d{2})/i', $style)) {
      $isBold = TRUE;
    }

    $tagName = strtolower($element->tagName);
    if (in_array($tagName, ['h1', 'h2', 'h3', 'strong', 'b'])) {
      $isBold = TRUE;
    }

    if ($fontSize === NULL) {
      return FALSE;
    }

    return $fontSize >= 18 || ($fontSize >= 14 && $isBold);
  }

  /**
   * Gets a short HTML snippet of an element.
   */
  protected function getElementSnippet(\DOMElement $element): string {
    $clone = $element->cloneNode(FALSE);
    $text = $element->ownerDocument->saveHTML($clone);
    $textContent = $element->textContent;
    if (strlen($textContent) > 50) {
      $textContent = substr($textContent, 0, 50) . '...';
    }
    return trim($text) . htmlspecialchars($textContent, ENT_QUOTES, 'UTF-8');
  }

}
