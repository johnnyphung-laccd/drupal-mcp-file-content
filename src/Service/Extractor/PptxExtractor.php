<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service\Extractor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\mcp_file_content\Exception\ExtractionException;

/**
 * Extracts content from PPTX and PPT files using ZipArchive XML parsing.
 */
class PptxExtractor implements ExtractorInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSupportedMimeTypes(): array {
    return [
      'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'application/vnd.ms-powerpoint',
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

    // Convert .ppt to .pptx via LibreOffice if needed.
    if ($extension === 'ppt') {
      $filePath = $this->convertPptToPptx($filePath);
    }

    $zip = new \ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
      throw new ExtractionException("Failed to open PPTX as ZIP archive.");
    }

    try {
      $slides = $this->extractSlides($zip);
      $notes = $this->extractNotes($zip);
      $images = $this->extractImages($zip);
      $metadata = $this->extractMetadata($zip);
    }
    finally {
      $zip->close();
    }

    $html = '';
    $headings = [];
    $title = $metadata['title'] ?? pathinfo($filePath, PATHINFO_FILENAME);

    foreach ($slides as $slideNum => $slide) {
      $slideTitle = $slide['title'] ?: "Slide {$slideNum}";
      $headings[] = ['level' => 2, 'text' => $slideTitle];
      $html .= "<h2>Slide {$slideNum}: " . htmlspecialchars($slideTitle, ENT_QUOTES, 'UTF-8') . "</h2>\n";

      foreach ($slide['content'] as $text) {
        if (!empty(trim($text))) {
          $html .= '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "</p>\n";
        }
      }

      if (!empty($notes[$slideNum])) {
        $html .= '<aside><p><strong>Speaker Notes:</strong> ' .
          htmlspecialchars($notes[$slideNum], ENT_QUOTES, 'UTF-8') . "</p></aside>\n";
      }
    }

    return [
      'content' => $html,
      'title' => $title,
      'metadata' => array_merge($metadata, ['slide_count' => count($slides)]),
      'images' => $images,
      'structure' => ['headings' => $headings],
    ];
  }

  /**
   * Extracts slide content from PPTX.
   */
  protected function extractSlides(\ZipArchive $zip): array {
    $slides = [];
    $slideIndex = 1;

    while (($xml = $zip->getFromName("ppt/slides/slide{$slideIndex}.xml")) !== FALSE) {
      $dom = new \DOMDocument();
      $dom->loadXML($xml);
      $xpath = new \DOMXPath($dom);
      $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
      $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');

      $title = '';
      $content = [];

      // Extract title (usually the first sp with type="title" or "ctrTitle").
      $titleNodes = $xpath->query('//p:sp[p:nvSpPr/p:nvPr/p:ph[@type="title" or @type="ctrTitle"]]//a:t');
      if ($titleNodes && $titleNodes->length > 0) {
        $titleParts = [];
        foreach ($titleNodes as $node) {
          $titleParts[] = $node->textContent;
        }
        $title = implode(' ', $titleParts);
      }

      // Extract all text elements.
      $textNodes = $xpath->query('//a:t');
      if ($textNodes) {
        foreach ($textNodes as $node) {
          $text = trim($node->textContent);
          if (!empty($text) && $text !== $title) {
            $content[] = $text;
          }
        }
      }

      $slides[$slideIndex] = [
        'title' => $title,
        'content' => $content,
      ];
      $slideIndex++;
    }

    return $slides;
  }

  /**
   * Extracts speaker notes from PPTX.
   */
  protected function extractNotes(\ZipArchive $zip): array {
    $notes = [];
    $noteIndex = 1;

    while (($xml = $zip->getFromName("ppt/notesSlides/notesSlide{$noteIndex}.xml")) !== FALSE) {
      $dom = new \DOMDocument();
      $dom->loadXML($xml);
      $xpath = new \DOMXPath($dom);
      $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

      $textNodes = $xpath->query('//a:t');
      $noteText = [];
      if ($textNodes) {
        foreach ($textNodes as $node) {
          $text = trim($node->textContent);
          // Skip slide number placeholders.
          if (!empty($text) && !preg_match('/^\d+$/', $text)) {
            $noteText[] = $text;
          }
        }
      }

      if (!empty($noteText)) {
        $notes[$noteIndex] = implode(' ', $noteText);
      }
      $noteIndex++;
    }

    return $notes;
  }

  /**
   * Extracts images from PPTX.
   */
  protected function extractImages(\ZipArchive $zip): array {
    $images = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = $zip->getNameIndex($i);
      if (str_starts_with($name, 'ppt/media/')) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mimeMap = [
          'png' => 'image/png',
          'jpg' => 'image/jpeg',
          'jpeg' => 'image/jpeg',
          'gif' => 'image/gif',
          'tiff' => 'image/tiff',
          'tif' => 'image/tiff',
          'wmf' => 'image/wmf',
          'emf' => 'image/emf',
        ];

        if (isset($mimeMap[$ext])) {
          $data = $zip->getFromName($name);
          $images[] = [
            'data' => base64_encode($data),
            'mime_type' => $mimeMap[$ext],
            'filename' => basename($name),
            'alt' => '',
          ];
        }
      }
    }

    return $images;
  }

  /**
   * Extracts metadata from PPTX.
   */
  protected function extractMetadata(\ZipArchive $zip): array {
    $metadata = [];
    $coreXml = $zip->getFromName('docProps/core.xml');
    if ($coreXml) {
      $dom = new \DOMDocument();
      $dom->loadXML($coreXml);
      $xpath = new \DOMXPath($dom);
      $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
      $xpath->registerNamespace('cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties');

      $titleNode = $xpath->query('//dc:title');
      if ($titleNode && $titleNode->length > 0) {
        $metadata['title'] = $titleNode->item(0)->textContent;
      }

      $creatorNode = $xpath->query('//dc:creator');
      if ($creatorNode && $creatorNode->length > 0) {
        $metadata['author'] = $creatorNode->item(0)->textContent;
      }
    }

    return $metadata;
  }

  /**
   * Converts a .ppt file to .pptx using LibreOffice.
   */
  protected function convertPptToPptx(string $filePath): string {
    $config = $this->configFactory->get('mcp_file_content.settings');
    $sofficePath = $config->get('extraction.libreoffice_path') ?? 'soffice';

    $tmpDir = sys_get_temp_dir() . '/mcp_ppt_' . uniqid();
    if (!mkdir($tmpDir, 0755, TRUE)) {
      throw new ExtractionException("Failed to create temp directory for PPT conversion.");
    }

    $cmd = sprintf(
      '%s --headless --convert-to pptx --outdir %s %s 2>&1',
      escapeshellarg($sofficePath),
      escapeshellarg($tmpDir),
      escapeshellarg($filePath)
    );
    exec($cmd, $output, $returnCode);

    if ($returnCode !== 0) {
      @rmdir($tmpDir);
      throw new ExtractionException("Failed to convert PPT to PPTX: " . implode("\n", $output));
    }

    $basename = pathinfo($filePath, PATHINFO_FILENAME);
    $pptxPath = $tmpDir . '/' . $basename . '.pptx';

    if (!file_exists($pptxPath)) {
      @rmdir($tmpDir);
      throw new ExtractionException("LibreOffice conversion produced no output file.");
    }

    return $pptxPath;
  }

}
