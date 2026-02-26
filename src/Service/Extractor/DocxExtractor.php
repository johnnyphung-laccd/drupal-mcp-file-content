<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service\Extractor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\mcp_file_content\Exception\ExtractionException;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;

/**
 * Extracts content from DOCX and DOC files.
 */
class DocxExtractor implements ExtractorInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSupportedMimeTypes(): array {
    return [
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/msword',
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

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    // Convert .doc to .docx via LibreOffice if needed.
    if ($extension === 'doc') {
      $filePath = $this->convertDocToDocx($filePath);
    }

    try {
      $phpWord = IOFactory::load($filePath, 'Word2007');
    }
    catch (\Exception $e) {
      throw new ExtractionException("Failed to parse DOCX: " . $e->getMessage(), 0, $e);
    }

    $html = '';
    $images = [];
    $headings = [];
    $title = '';
    $metadata = [];

    $docInfo = $phpWord->getDocInfo();
    if ($docInfo) {
      $metadata = [
        'author' => $docInfo->getCreator(),
        'title' => $docInfo->getTitle(),
        'description' => $docInfo->getDescription(),
        'created' => $docInfo->getCreated(),
      ];
      $title = $docInfo->getTitle() ?: '';
    }

    foreach ($phpWord->getSections() as $section) {
      foreach ($section->getElements() as $element) {
        $result = $this->processElement($element, $images, $headings);
        $html .= $result;
      }
    }

    if (empty($title)) {
      $title = pathinfo($filePath, PATHINFO_FILENAME);
    }

    return [
      'content' => $html,
      'title' => $title,
      'metadata' => $metadata,
      'images' => $images,
      'structure' => ['headings' => $headings],
    ];
  }

  /**
   * Processes a PHPWord element into HTML.
   */
  protected function processElement($element, array &$images, array &$headings): string {
    if ($element instanceof Title) {
      $level = $element->getDepth();
      $level = max(1, min(6, $level));
      $text = $this->getTextContent($element);
      $headings[] = ['level' => $level, 'text' => $text];
      return "<h{$level}>" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "</h{$level}>\n";
    }

    if ($element instanceof TextRun) {
      $text = $this->getTextRunContent($element);
      $isBoldOnly = $this->isBoldOnlyParagraph($element);
      if ($isBoldOnly && strlen(strip_tags($text)) > 0) {
        $plainText = strip_tags($text);
        $headings[] = ['level' => 2, 'text' => $plainText];
        return "<h2>" . htmlspecialchars($plainText, ENT_QUOTES, 'UTF-8') . "</h2>\n";
      }
      return "<p>{$text}</p>\n";
    }

    if ($element instanceof Text) {
      $text = $element->getText();
      return '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "</p>\n";
    }

    if ($element instanceof Table) {
      return $this->processTable($element);
    }

    if ($element instanceof ListItemRun) {
      $text = $this->getTextRunContent($element);
      return "<li>{$text}</li>\n";
    }

    if ($element instanceof Image) {
      return $this->processImage($element, $images);
    }

    if ($element instanceof TextBreak) {
      return "<br>\n";
    }

    return '';
  }

  /**
   * Gets text content from a Title element.
   */
  protected function getTextContent($element): string {
    if (method_exists($element, 'getText')) {
      $textObj = $element->getText();
      if (is_string($textObj)) {
        return $textObj;
      }
      if ($textObj instanceof TextRun) {
        return strip_tags($this->getTextRunContent($textObj));
      }
    }
    return '';
  }

  /**
   * Gets HTML content from a TextRun element.
   */
  protected function getTextRunContent($textRun): string {
    $html = '';
    foreach ($textRun->getElements() as $element) {
      if ($element instanceof Text) {
        $text = htmlspecialchars($element->getText(), ENT_QUOTES, 'UTF-8');
        $fontStyle = $element->getFontStyle();
        if ($fontStyle) {
          if (method_exists($fontStyle, 'isBold') && $fontStyle->isBold()) {
            $text = "<strong>{$text}</strong>";
          }
          if (method_exists($fontStyle, 'isItalic') && $fontStyle->isItalic()) {
            $text = "<em>{$text}</em>";
          }
          if (method_exists($fontStyle, 'isUnderline') && $fontStyle->isUnderline()) {
            $text = "<u>{$text}</u>";
          }
        }
        $html .= $text;
      }
      elseif ($element instanceof Image) {
        $html .= $this->processImage($element, $images);
      }
    }
    return $html;
  }

  /**
   * Checks if a TextRun is a bold-only paragraph (fake heading).
   */
  protected function isBoldOnlyParagraph($textRun): bool {
    $elements = $textRun->getElements();
    if (empty($elements)) {
      return FALSE;
    }

    $allBold = TRUE;
    $hasText = FALSE;

    foreach ($elements as $element) {
      if ($element instanceof Text) {
        $text = trim($element->getText());
        if (empty($text)) {
          continue;
        }
        $hasText = TRUE;
        $fontStyle = $element->getFontStyle();
        if (!$fontStyle || !method_exists($fontStyle, 'isBold') || !$fontStyle->isBold()) {
          $allBold = FALSE;
          break;
        }
      }
    }

    return $hasText && $allBold;
  }

  /**
   * Processes a Table element into accessible HTML.
   */
  protected function processTable(Table $table): string {
    $html = '<table>';
    $rows = $table->getRows();

    foreach ($rows as $rowIndex => $row) {
      $html .= '<tr>';
      foreach ($row->getCells() as $cell) {
        $cellContent = '';
        foreach ($cell->getElements() as $element) {
          if ($element instanceof TextRun) {
            $cellContent .= $this->getTextRunContent($element);
          }
          elseif ($element instanceof Text) {
            $cellContent .= htmlspecialchars($element->getText(), ENT_QUOTES, 'UTF-8');
          }
        }

        if ($rowIndex === 0) {
          $html .= '<th scope="col">' . $cellContent . '</th>';
        }
        else {
          $html .= '<td>' . $cellContent . '</td>';
        }
      }
      $html .= '</tr>';
    }

    $html .= '</table>';
    return $html;
  }

  /**
   * Processes an Image element.
   */
  protected function processImage(Image $image, array &$images): string {
    $imageData = $image->getImageStringData(TRUE);
    $mimeType = $image->getImageType() ?? 'image/png';
    $alt = '';
    $index = count($images);

    $imageEntry = [
      'data' => $imageData,
      'mime_type' => $mimeType,
      'alt' => $alt,
      'index' => $index,
    ];
    $images[] = $imageEntry;

    return '<img src="data:' . $mimeType . ';base64,' . $imageData . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">';
  }

  /**
   * Converts a .doc file to .docx using LibreOffice.
   */
  protected function convertDocToDocx(string $filePath): string {
    $config = $this->configFactory->get('mcp_file_content.settings');
    $sofficePath = $config->get('extraction.libreoffice_path') ?? 'soffice';

    $tmpDir = sys_get_temp_dir() . '/mcp_doc_' . uniqid();
    if (!mkdir($tmpDir, 0755, TRUE)) {
      throw new ExtractionException("Failed to create temp directory for DOC conversion.");
    }

    $cmd = sprintf(
      '%s --headless --convert-to docx --outdir %s %s 2>&1',
      escapeshellarg($sofficePath),
      escapeshellarg($tmpDir),
      escapeshellarg($filePath)
    );
    exec($cmd, $output, $returnCode);

    if ($returnCode !== 0) {
      @rmdir($tmpDir);
      throw new ExtractionException("Failed to convert DOC to DOCX via LibreOffice: " . implode("\n", $output));
    }

    $basename = pathinfo($filePath, PATHINFO_FILENAME);
    $docxPath = $tmpDir . '/' . $basename . '.docx';

    if (!file_exists($docxPath)) {
      @rmdir($tmpDir);
      throw new ExtractionException("LibreOffice conversion produced no output file.");
    }

    return $docxPath;
  }

}
