<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_file_content\Service\AccessibilityReportGenerator;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates accessibility reports for existing content nodes.
 */
#[Tool(
  id: 'mcp_file_content_generate_accessibility_report',
  label: new TranslatableMarkup('Generate Accessibility Report'),
  description: new TranslatableMarkup('Generates WCAG 2.1 AA accessibility reports for existing content nodes. Supports JSON, HTML, or CSV output formats.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'node_ids' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Node IDs'),
      description: new TranslatableMarkup('Comma-separated list of node IDs to audit. Leave empty to audit all of a content type.'),
      required: FALSE,
    ),
    'content_type' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content Type'),
      description: new TranslatableMarkup('Content type to audit (used when node_ids is empty).'),
      required: FALSE,
    ),
    'output_format' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Output Format'),
      description: new TranslatableMarkup('Report format: json, html, or csv.'),
      required: FALSE,
      default_value: 'json',
    ),
    'include_remediation' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Include Remediation'),
      description: new TranslatableMarkup('Include remediation suggestions in the report.'),
      required: FALSE,
      default_value: TRUE,
    ),
  ],
)]
final class GenerateAccessibilityReportTool extends ToolBase {

  protected AccessibilityReportGenerator $reportGenerator;
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->reportGenerator = $container->get('mcp_file_content.accessibility_report_generator');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $nodeIdsStr = $values['node_ids'] ?? '';
    $contentType = $values['content_type'] ?? NULL;
    $format = $values['output_format'] ?? 'json';

    if (!in_array($format, ['json', 'html', 'csv'])) {
      $format = 'json';
    }

    try {
      $nodes = [];

      if (!empty($nodeIdsStr)) {
        $nodeIds = array_map('intval', array_filter(explode(',', $nodeIdsStr)));
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nodeIds);
      }
      elseif (!empty($contentType)) {
        $query = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', $contentType)
          ->range(0, 100)
          ->accessCheck(TRUE);
        $ids = $query->execute();
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
      }
      else {
        return ExecutableResult::failure(new TranslatableMarkup('Provide either node_ids or content_type.'));
      }

      if (empty($nodes)) {
        return ExecutableResult::failure(new TranslatableMarkup('No nodes found.'));
      }

      $items = [];
      foreach ($nodes as $node) {
        $body = '';
        if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
          $body = $node->get('body')->value;
        }
        $items[] = [
          'title' => $node->getTitle(),
          'html' => $body,
        ];
      }

      $report = $this->reportGenerator->generateBatchReport($items, $format);
      return ExecutableResult::success(new TranslatableMarkup('@output', ['@output' => $report]));
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(new TranslatableMarkup('Report generation failed: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $result = AccessResult::allowedIfHasPermission($account, 'generate accessibility report via mcp');
    return $return_as_object ? $result : $result->isAllowed();
  }

}
