<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_file_content\Service\AccessibilityRemediator;
use Drupal\mcp_file_content\Service\AccessibilityValidator;
use Drupal\mcp_file_content\Service\FileExtractorManager;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts content from a media file entity.
 */
#[Tool(
  id: 'mcp_file_content_extract_file_content',
  label: new TranslatableMarkup('Extract File Content'),
  description: new TranslatableMarkup('Extracts text, images, and structure from a Drupal media file (PDF, DOCX, PPTX, images, text). Optionally runs accessibility analysis and auto-remediation.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'media_id' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Media ID'),
      description: new TranslatableMarkup('The Drupal media entity ID to extract content from.'),
      required: TRUE,
    ),
    'extract_images' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Extract Images'),
      description: new TranslatableMarkup('Whether to extract embedded images as base64.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'ocr_enabled' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('OCR Enabled'),
      description: new TranslatableMarkup('Enable OCR for scanned documents and images.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'ocr_language' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('OCR Language'),
      description: new TranslatableMarkup('Tesseract OCR language code (e.g., eng, spa).'),
      required: FALSE,
      default_value: 'eng',
    ),
    'analyze_accessibility' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Analyze Accessibility'),
      description: new TranslatableMarkup('Run WCAG 2.1 AA accessibility analysis on extracted content.'),
      required: FALSE,
      default_value: TRUE,
    ),
  ],
)]
final class ExtractFileContentTool extends ToolBase {

  protected FileExtractorManager $extractorManager;
  protected AccessibilityRemediator $remediator;
  protected AccessibilityValidator $validator;
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->extractorManager = $container->get('mcp_file_content.file_extractor_manager');
    $instance->remediator = $container->get('mcp_file_content.accessibility_remediator');
    $instance->validator = $container->get('mcp_file_content.accessibility_validator');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $mediaId = $values['media_id'] ?? NULL;
    if (!$mediaId) {
      return ExecutableResult::failure(new TranslatableMarkup('media_id is required.'));
    }

    try {
      $media = $this->entityTypeManager->getStorage('media')->load($mediaId);
      if (!$media) {
        return ExecutableResult::failure(new TranslatableMarkup('Media entity @id not found.', ['@id' => $mediaId]));
      }

      $options = [
        'extract_images' => $values['extract_images'] ?? TRUE,
        'ocr_enabled' => $values['ocr_enabled'] ?? TRUE,
        'ocr_language' => $values['ocr_language'] ?? 'eng',
      ];

      $result = $this->extractorManager->extractFromMedia($media, $options);

      // Auto-remediate.
      $remediation = $this->remediator->remediate($result['content']);
      $result['content'] = $remediation['content'];
      $result['auto_remediation'] = [
        'fixes_applied' => $remediation['fixes_applied'],
        'fix_count' => $remediation['fix_count'],
      ];

      // Accessibility analysis.
      if ($values['analyze_accessibility'] ?? TRUE) {
        $result['accessibility_audit'] = $this->validator->validate($result['content']);
      }

      $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      return ExecutableResult::success(new TranslatableMarkup('@output', ['@output' => $output]));
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(new TranslatableMarkup('Extraction failed: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $result = AccessResult::allowedIfHasPermission($account, 'extract file content via mcp');
    return $return_as_object ? $result : $result->isAllowed();
  }

}
