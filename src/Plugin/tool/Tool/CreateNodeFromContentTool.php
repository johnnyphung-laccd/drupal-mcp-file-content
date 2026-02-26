<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_file_content\Service\NodeCreator;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a Drupal content node from provided HTML content.
 */
#[Tool(
  id: 'mcp_file_content_create_node_from_content',
  label: new TranslatableMarkup('Create Node from Content'),
  description: new TranslatableMarkup('Creates a Drupal content node from HTML content. Enforces WCAG 2.1 AA accessibility standards by default. Non-compliant content is rejected with remediation instructions.'),
  operation: ToolOperation::Write,
  destructive: FALSE,
  input_definitions: [
    'title' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Title'),
      description: new TranslatableMarkup('The title for the new content node.'),
      required: TRUE,
    ),
    'body' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Body'),
      description: new TranslatableMarkup('The HTML body content for the node.'),
      required: TRUE,
    ),
    'content_type' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content Type'),
      description: new TranslatableMarkup('The machine name of the content type (defaults to configured type).'),
      required: FALSE,
      default_value: 'page',
    ),
    'body_format' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Body Format'),
      description: new TranslatableMarkup('The text format for the body field.'),
      required: FALSE,
      default_value: 'full_html',
    ),
    'status' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Published'),
      description: new TranslatableMarkup('Whether to publish the node immediately.'),
      required: FALSE,
      default_value: FALSE,
    ),
    'source_media_id' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Source Media ID'),
      description: new TranslatableMarkup('The media entity ID the content was extracted from.'),
      required: FALSE,
    ),
    'language' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Language'),
      description: new TranslatableMarkup('Language code (e.g., en, es).'),
      required: FALSE,
      default_value: 'en',
    ),
    'enforce_accessibility' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Enforce Accessibility'),
      description: new TranslatableMarkup('Reject content that fails WCAG 2.1 AA validation.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'accessibility_report' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Accessibility Report'),
      description: new TranslatableMarkup('Include accessibility report in the response.'),
      required: FALSE,
      default_value: TRUE,
    ),
  ],
)]
final class CreateNodeFromContentTool extends ToolBase {

  protected NodeCreator $nodeCreator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->nodeCreator = $container->get('mcp_file_content.node_creator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $title = $values['title'] ?? '';
    $body = $values['body'] ?? '';

    if (empty($title) || empty($body)) {
      return ExecutableResult::failure(new TranslatableMarkup('Both title and body are required.'));
    }

    try {
      $result = $this->nodeCreator->createNode([
        'title' => $title,
        'body' => $body,
        'content_type' => $values['content_type'] ?? 'page',
        'body_format' => $values['body_format'] ?? 'full_html',
        'status' => $values['status'] ?? FALSE,
        'source_media_id' => $values['source_media_id'] ?? NULL,
        'language' => $values['language'] ?? 'en',
        'enforce_accessibility' => $values['enforce_accessibility'] ?? TRUE,
        'accessibility_report' => $values['accessibility_report'] ?? TRUE,
      ]);

      if (!empty($result['error'])) {
        $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return ExecutableResult::failure(new TranslatableMarkup('@output', ['@output' => $output]));
      }

      $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      return ExecutableResult::success(new TranslatableMarkup('@output', ['@output' => $output]));
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(new TranslatableMarkup('Node creation failed: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $result = AccessResult::allowedIfHasPermission($account, 'create content via mcp');
    return $return_as_object ? $result : $result->isAllowed();
  }

}
