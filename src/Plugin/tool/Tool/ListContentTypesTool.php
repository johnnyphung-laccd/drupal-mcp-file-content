<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists available content types and their fields.
 */
#[Tool(
  id: 'mcp_file_content_list_content_types',
  label: new TranslatableMarkup('List Content Types'),
  description: new TranslatableMarkup('Lists available Drupal content types and their fields, useful for knowing which content type to target when creating nodes.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'content_type' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content Type'),
      description: new TranslatableMarkup('Optional: filter to a specific content type machine name.'),
      required: FALSE,
    ),
  ],
)]
final class ListContentTypesTool extends ToolBase {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      $filter = $values['content_type'] ?? NULL;
      $nodeTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

      $result = [];
      foreach ($nodeTypes as $type) {
        $machineName = $type->id();

        if ($filter && $machineName !== $filter) {
          continue;
        }

        $fields = $this->entityFieldManager->getFieldDefinitions('node', $machineName);
        $fieldInfo = [];

        foreach ($fields as $fieldName => $fieldDef) {
          // Skip base fields that aren't useful.
          if (in_array($fieldName, ['nid', 'uuid', 'vid', 'revision_timestamp', 'revision_uid', 'revision_log', 'revision_default', 'default_langcode', 'revision_translation_affected'])) {
            continue;
          }

          $fieldInfo[] = [
            'name' => $fieldName,
            'label' => (string) $fieldDef->getLabel(),
            'type' => $fieldDef->getType(),
            'required' => $fieldDef->isRequired(),
            'description' => (string) $fieldDef->getDescription(),
          ];
        }

        $result[] = [
          'machine_name' => $machineName,
          'label' => $type->label(),
          'description' => $type->getDescription(),
          'fields' => $fieldInfo,
        ];
      }

      $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      return ExecutableResult::success(new TranslatableMarkup('@output', ['@output' => $output]));
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(new TranslatableMarkup('Failed to list content types: @message', ['@message' => $e->getMessage()]));
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
