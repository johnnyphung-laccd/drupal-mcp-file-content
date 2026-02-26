<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for MCP File Content settings.
 */
class SettingsForm extends ConfigFormBase {

  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mcp_file_content_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['mcp_file_content.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('mcp_file_content.settings');

    // Enabled File Types.
    $form['enabled_file_types'] = [
      '#type' => 'details',
      '#title' => $this->t('Enabled File Types'),
      '#open' => TRUE,
    ];

    $fileTypes = [
      'pdf' => 'PDF (.pdf)',
      'docx' => 'DOCX (.docx)',
      'doc' => 'DOC (.doc) — requires LibreOffice',
      'pptx' => 'PPTX (.pptx)',
      'ppt' => 'PPT (.ppt) — requires LibreOffice',
      'images' => 'Images (JPEG, PNG, TIFF, GIF, WebP)',
      'text' => 'Text (TXT, CSV, Markdown, HTML)',
    ];

    foreach ($fileTypes as $key => $label) {
      $form['enabled_file_types'][$key] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => $config->get("enabled_file_types.{$key}") ?? TRUE,
      ];
    }

    // Content Defaults.
    $form['content_defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Defaults'),
      '#open' => TRUE,
    ];

    $nodeTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $typeOptions = [];
    foreach ($nodeTypes as $type) {
      $typeOptions[$type->id()] = $type->label();
    }

    $form['content_defaults']['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Default content type'),
      '#options' => $typeOptions,
      '#default_value' => $config->get('content_defaults.content_type') ?? 'page',
      '#description' => $this->t('Content type to use when creating nodes from extracted content.'),
    ];

    $form['content_defaults']['publish_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish nodes by default'),
      '#default_value' => $config->get('content_defaults.publish_status') ?? FALSE,
    ];

    // OCR Settings.
    $form['ocr'] = [
      '#type' => 'details',
      '#title' => $this->t('OCR Settings'),
      '#open' => FALSE,
    ];

    $form['ocr']['auto_detect'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-detect scanned documents'),
      '#default_value' => $config->get('ocr.auto_detect') ?? TRUE,
      '#description' => $this->t('Automatically apply OCR when a PDF has little or no extractable text.'),
    ];

    $form['ocr']['language'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OCR language'),
      '#default_value' => $config->get('ocr.language') ?? 'eng',
      '#size' => 10,
      '#description' => $this->t('Tesseract language code (e.g., eng, spa, fra). Multiple: eng+spa.'),
    ];

    $form['ocr']['tesseract_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tesseract binary path'),
      '#default_value' => $config->get('ocr.tesseract_path') ?? '/usr/bin/tesseract',
      '#description' => $this->t('Full path to the Tesseract OCR binary.'),
    ];

    // Extraction Settings.
    $form['extraction'] = [
      '#type' => 'details',
      '#title' => $this->t('Extraction Settings'),
      '#open' => FALSE,
    ];

    $form['extraction']['max_file_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum file size (bytes)'),
      '#default_value' => $config->get('extraction.max_file_size') ?? 52428800,
      '#min' => 1048576,
      '#description' => $this->t('Maximum file size in bytes. Default: 50 MB (52428800).'),
    ];

    $form['extraction']['extract_images'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Extract embedded images'),
      '#default_value' => $config->get('extraction.extract_images') ?? TRUE,
    ];

    $form['extraction']['libreoffice_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('LibreOffice binary path'),
      '#default_value' => $config->get('extraction.libreoffice_path') ?? '/usr/bin/soffice',
      '#description' => $this->t('Full path to the LibreOffice soffice binary. Required for DOC/PPT conversion.'),
    ];

    // Accessibility Enforcement.
    $form['accessibility'] = [
      '#type' => 'details',
      '#title' => $this->t('Accessibility Enforcement'),
      '#open' => TRUE,
    ];

    $form['accessibility']['enforcement_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable accessibility enforcement'),
      '#default_value' => $config->get('accessibility.enforcement_enabled') ?? TRUE,
      '#description' => $this->t('Reject content that fails WCAG 2.1 AA validation when creating nodes.'),
    ];

    $form['accessibility']['strictness'] = [
      '#type' => 'radios',
      '#title' => $this->t('Strictness level'),
      '#options' => [
        'standard' => $this->t('Standard (score >= 70 required)'),
        'strict' => $this->t('Strict (score >= 95 required)'),
      ],
      '#default_value' => $config->get('accessibility.strictness') ?? 'standard',
    ];

    $form['accessibility']['contrast_threshold'] = [
      '#type' => 'radios',
      '#title' => $this->t('Contrast threshold'),
      '#options' => [
        'aa' => $this->t('AA (4.5:1 normal, 3:1 large)'),
        'aaa' => $this->t('AAA (7:1 normal, 4.5:1 large)'),
      ],
      '#default_value' => $config->get('accessibility.contrast_threshold') ?? 'aa',
    ];

    $form['accessibility']['auto_remediate_headings'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-remediate heading hierarchy'),
      '#default_value' => $config->get('accessibility.auto_remediate_headings') ?? TRUE,
    ];

    $form['accessibility']['auto_remediate_tables'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-remediate table headers'),
      '#default_value' => $config->get('accessibility.auto_remediate_tables') ?? TRUE,
    ];

    $form['accessibility']['auto_remediate_lists'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-remediate pseudo-lists'),
      '#default_value' => $config->get('accessibility.auto_remediate_lists') ?? TRUE,
    ];

    $form['accessibility']['attach_report'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Attach accessibility report to responses'),
      '#default_value' => $config->get('accessibility.attach_report') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('mcp_file_content.settings');

    // Enabled file types.
    foreach (['pdf', 'docx', 'doc', 'pptx', 'ppt', 'images', 'text'] as $type) {
      $config->set("enabled_file_types.{$type}", (bool) $form_state->getValue($type));
    }

    // Content defaults.
    $config->set('content_defaults.content_type', $form_state->getValue('content_type'));
    $config->set('content_defaults.publish_status', (bool) $form_state->getValue('publish_status'));

    // OCR settings.
    $config->set('ocr.auto_detect', (bool) $form_state->getValue('auto_detect'));
    $config->set('ocr.language', $form_state->getValue('language'));
    $config->set('ocr.tesseract_path', $form_state->getValue('tesseract_path'));

    // Extraction settings.
    $config->set('extraction.max_file_size', (int) $form_state->getValue('max_file_size'));
    $config->set('extraction.extract_images', (bool) $form_state->getValue('extract_images'));
    $config->set('extraction.libreoffice_path', $form_state->getValue('libreoffice_path'));

    // Accessibility settings.
    $config->set('accessibility.enforcement_enabled', (bool) $form_state->getValue('enforcement_enabled'));
    $config->set('accessibility.strictness', $form_state->getValue('strictness'));
    $config->set('accessibility.contrast_threshold', $form_state->getValue('contrast_threshold'));
    $config->set('accessibility.auto_remediate_headings', (bool) $form_state->getValue('auto_remediate_headings'));
    $config->set('accessibility.auto_remediate_tables', (bool) $form_state->getValue('auto_remediate_tables'));
    $config->set('accessibility.auto_remediate_lists', (bool) $form_state->getValue('auto_remediate_lists'));
    $config->set('accessibility.attach_report', (bool) $form_state->getValue('attach_report'));

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
