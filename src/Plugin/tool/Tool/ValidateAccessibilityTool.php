<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_file_content\Service\AccessibilityValidator;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Validates HTML content against WCAG 2.1 AA standards.
 */
#[Tool(
  id: 'mcp_file_content_validate_accessibility',
  label: new TranslatableMarkup('Validate Accessibility'),
  description: new TranslatableMarkup('Validates HTML content against WCAG 2.1 AA accessibility standards. Checks images, headings, contrast, tables, links, language, and lists.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'html_content' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('HTML Content'),
      description: new TranslatableMarkup('The HTML content to validate for accessibility.'),
      required: TRUE,
    ),
    'check_images' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Check Images'),
      description: new TranslatableMarkup('Check image alt text requirements.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'check_headings' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Check Headings'),
      description: new TranslatableMarkup('Check heading hierarchy.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'check_contrast' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Check Contrast'),
      description: new TranslatableMarkup('Check color contrast ratios.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'check_tables' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Check Tables'),
      description: new TranslatableMarkup('Check table accessibility.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'check_links' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Check Links'),
      description: new TranslatableMarkup('Check link text quality.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'check_language' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Check Language'),
      description: new TranslatableMarkup('Check language attributes.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'check_lists' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Check Lists'),
      description: new TranslatableMarkup('Check for pseudo-list patterns.'),
      required: FALSE,
      default_value: TRUE,
    ),
    'strict_mode' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Strict Mode'),
      description: new TranslatableMarkup('Use strict validation (higher pass threshold).'),
      required: FALSE,
      default_value: FALSE,
    ),
  ],
)]
final class ValidateAccessibilityTool extends ToolBase {

  protected AccessibilityValidator $validator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->validator = $container->get('mcp_file_content.accessibility_validator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $html = $values['html_content'] ?? '';
    if (empty($html)) {
      return ExecutableResult::failure(new TranslatableMarkup('html_content is required.'));
    }

    $options = [
      'check_images' => $values['check_images'] ?? TRUE,
      'check_headings' => $values['check_headings'] ?? TRUE,
      'check_contrast' => $values['check_contrast'] ?? TRUE,
      'check_tables' => $values['check_tables'] ?? TRUE,
      'check_links' => $values['check_links'] ?? TRUE,
      'check_language' => $values['check_language'] ?? TRUE,
      'check_lists' => $values['check_lists'] ?? TRUE,
      'strict_mode' => $values['strict_mode'] ?? FALSE,
    ];

    try {
      $result = $this->validator->validate($html, $options);
      $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      return ExecutableResult::success(new TranslatableMarkup('@output', ['@output' => $output]));
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(new TranslatableMarkup('Validation failed: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $result = AccessResult::allowedIfHasPermission($account, 'validate accessibility via mcp');
    return $return_as_object ? $result : $result->isAllowed();
  }

}
