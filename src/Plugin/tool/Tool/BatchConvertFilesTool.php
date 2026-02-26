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
use Drupal\mcp_file_content\Service\NodeCreator;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Batch converts multiple media files to content nodes.
 */
#[Tool(
  id: 'mcp_file_content_batch_convert_files',
  label: new TranslatableMarkup('Batch Convert Files'),
  description: new TranslatableMarkup('Processes multiple media files: extracts content, remediates accessibility issues, validates WCAG compliance, and creates content nodes for each.'),
  operation: ToolOperation::Write,
  destructive: FALSE,
  input_definitions: [
    'media_ids' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Media IDs'),
      description: new TranslatableMarkup('Comma-separated list of media entity IDs to process.'),
      required: TRUE,
    ),
    'content_type' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content Type'),
      description: new TranslatableMarkup('Content type for created nodes.'),
      required: FALSE,
      default_value: 'page',
    ),
    'status' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Published'),
      description: new TranslatableMarkup('Whether to publish created nodes.'),
      required: FALSE,
      default_value: FALSE,
    ),
    'ocr_enabled' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('OCR Enabled'),
      description: new TranslatableMarkup('Enable OCR for scanned documents.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'enforce_accessibility' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Enforce Accessibility'),
      description: new TranslatableMarkup('Reject non-compliant content.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'skip_on_failure' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Skip on Failure'),
      description: new TranslatableMarkup('Continue processing when a file fails (TRUE) or stop (FALSE).'),
      required: FALSE,
      default_value: TRUE,
    ),
  ],
)]
final class BatchConvertFilesTool extends ToolBase {

  protected FileExtractorManager $extractorManager;
  protected AccessibilityRemediator $remediator;
  protected AccessibilityValidator $validator;
  protected NodeCreator $nodeCreator;
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->extractorManager = $container->get('mcp_file_content.file_extractor_manager');
    $instance->remediator = $container->get('mcp_file_content.accessibility_remediator');
    $instance->validator = $container->get('mcp_file_content.accessibility_validator');
    $instance->nodeCreator = $container->get('mcp_file_content.node_creator');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $mediaIdsStr = $values['media_ids'] ?? '';
    if (empty($mediaIdsStr)) {
      return ExecutableResult::failure(new TranslatableMarkup('media_ids is required.'));
    }

    $mediaIds = array_map('intval', array_filter(explode(',', $mediaIdsStr)));
    if (empty($mediaIds)) {
      return ExecutableResult::failure(new TranslatableMarkup('No valid media IDs provided.'));
    }

    $contentType = $values['content_type'] ?? 'page';
    $status = $values['status'] ?? FALSE;
    $ocrEnabled = $values['ocr_enabled'] ?? TRUE;
    $enforceAccessibility = $values['enforce_accessibility'] ?? TRUE;
    $skipOnFailure = $values['skip_on_failure'] ?? TRUE;

    $processed = [];
    $failed = [];

    foreach ($mediaIds as $mediaId) {
      try {
        $media = $this->entityTypeManager->getStorage('media')->load($mediaId);
        if (!$media) {
          $failed[] = ['media_id' => $mediaId, 'error' => 'Media entity not found.'];
          if (!$skipOnFailure) {
            break;
          }
          continue;
        }

        // Extract content.
        $extracted = $this->extractorManager->extractFromMedia($media, [
          'ocr_enabled' => $ocrEnabled,
        ]);

        // Remediate.
        $remediation = $this->remediator->remediate($extracted['content']);
        $content = $remediation['content'];

        // Create node.
        $nodeResult = $this->nodeCreator->createNode([
          'title' => $extracted['title'] ?: $media->getName(),
          'body' => $content,
          'content_type' => $contentType,
          'status' => $status,
          'source_media_id' => $mediaId,
          'enforce_accessibility' => $enforceAccessibility,
          'accessibility_report' => TRUE,
        ]);

        if (!empty($nodeResult['error'])) {
          $failed[] = [
            'media_id' => $mediaId,
            'error' => $nodeResult['message'] ?? 'Unknown error',
            'accessibility_score' => $nodeResult['accessibility_score'] ?? NULL,
          ];
          if (!$skipOnFailure) {
            break;
          }
        }
        else {
          $processed[] = array_merge(['media_id' => $mediaId], $nodeResult);
        }
      }
      catch (\Exception $e) {
        $failed[] = ['media_id' => $mediaId, 'error' => $e->getMessage()];
        if (!$skipOnFailure) {
          break;
        }
      }
    }

    $result = [
      'processed' => $processed,
      'failed' => $failed,
      'summary' => [
        'total' => count($mediaIds),
        'succeeded' => count($processed),
        'failed' => count($failed),
      ],
    ];

    $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return ExecutableResult::success(new TranslatableMarkup('@output', ['@output' => $output]));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $result = AccessResult::allowedIfHasPermission($account, 'batch convert files via mcp');
    return $return_as_object ? $result : $result->isAllowed();
  }

}
