<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Creates Drupal content nodes from extracted content.
 */
class NodeCreator {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccessibilityValidator $validator,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * Creates a node from content.
   *
   * @param array $params
   *   Parameters including:
   *   - title: (string) Node title.
   *   - body: (string) HTML body content.
   *   - content_type: (string) Node bundle.
   *   - body_format: (string) Text format.
   *   - status: (bool) Publish status.
   *   - source_media_id: (int) Source media entity ID.
   *   - images: (array) Images to create as media.
   *   - taxonomy_terms: (array) Taxonomy term references.
   *   - additional_fields: (array) Additional field values.
   *   - language: (string) Language code.
   *   - enforce_accessibility: (bool) Validate before creating.
   *   - accessibility_report: (bool) Attach report.
   *
   * @return array
   *   Result with node_id, url, status, etc.
   */
  public function createNode(array $params): array {
    $config = $this->configFactory->get('mcp_file_content.settings');

    $title = $params['title'] ?? '';
    $body = $params['body'] ?? '';
    $contentType = $params['content_type'] ?? $config->get('content_defaults.content_type') ?? 'page';
    $bodyFormat = $params['body_format'] ?? 'full_html';
    $status = $params['status'] ?? $config->get('content_defaults.publish_status') ?? FALSE;
    $enforceAccessibility = $params['enforce_accessibility'] ?? $config->get('accessibility.enforcement_enabled');
    $generateReport = $params['accessibility_report'] ?? $config->get('accessibility.attach_report');

    // Validate content type exists.
    $nodeType = $this->entityTypeManager->getStorage('node_type')->load($contentType);
    if (!$nodeType) {
      return [
        'error' => TRUE,
        'message' => "Content type '{$contentType}' does not exist.",
        'type' => 'invalid_content_type',
      ];
    }

    // Accessibility validation.
    $accessibilityResult = NULL;
    if ($enforceAccessibility && !$this->currentUser->hasPermission('bypass accessibility enforcement')) {
      $accessibilityResult = $this->validator->validate($body);

      if (!$accessibilityResult['valid']) {
        return [
          'error' => TRUE,
          'message' => 'Content does not meet accessibility requirements.',
          'type' => 'accessibility_failure',
          'accessibility_score' => $accessibilityResult['score'],
          'accessibility_issues' => $accessibilityResult['errors'],
          'remediation_actions' => $accessibilityResult['remediation_actions'],
          'compliance_status' => 'non-compliant',
        ];
      }
    }

    // Run validation for reporting even if not enforcing.
    if ($accessibilityResult === NULL && $generateReport) {
      $accessibilityResult = $this->validator->validate($body);
    }

    // Create the node.
    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $nodeData = [
        'type' => $contentType,
        'title' => $title,
        'body' => [
          'value' => $body,
          'format' => $bodyFormat,
        ],
        'status' => $status ? 1 : 0,
        'uid' => $this->currentUser->id(),
      ];

      // Set language.
      if (!empty($params['language'])) {
        $nodeData['langcode'] = $params['language'];
      }

      $node = $nodeStorage->create($nodeData);

      // Set additional fields.
      if (!empty($params['additional_fields'])) {
        foreach ($params['additional_fields'] as $fieldName => $fieldValue) {
          if ($node->hasField($fieldName)) {
            $node->set($fieldName, $fieldValue);
          }
        }
      }

      // Set taxonomy terms.
      if (!empty($params['taxonomy_terms'])) {
        foreach ($params['taxonomy_terms'] as $fieldName => $terms) {
          if ($node->hasField($fieldName)) {
            $node->set($fieldName, $terms);
          }
        }
      }

      $node->save();

      // Handle images - create file/media entities.
      $mediaCreated = [];
      if (!empty($params['images'])) {
        $mediaCreated = $this->createMediaFromImages($params['images']);
      }

      $result = [
        'node_id' => $node->id(),
        'url' => $node->toUrl()->setAbsolute()->toString(),
        'status' => $status ? 'published' : 'unpublished',
        'media_created' => $mediaCreated,
      ];

      if ($accessibilityResult) {
        $result['accessibility_score'] = $accessibilityResult['score'];
        $result['accessibility_issues'] = array_merge($accessibilityResult['errors'], $accessibilityResult['warnings']);
        $result['compliance_status'] = $accessibilityResult['valid'] ? 'compliant' : 'non-compliant';
      }

      return $result;
    }
    catch (\Exception $e) {
      return [
        'error' => TRUE,
        'message' => 'Failed to create node: ' . $e->getMessage(),
        'type' => 'creation_error',
      ];
    }
  }

  /**
   * Creates media entities from image data.
   */
  protected function createMediaFromImages(array $images): array {
    $created = [];

    foreach ($images as $image) {
      try {
        $data = base64_decode($image['data'] ?? '');
        if (empty($data)) {
          continue;
        }

        $filename = $image['filename'] ?? 'image_' . uniqid() . '.png';
        $mimeType = $image['mime_type'] ?? 'image/png';
        $alt = $image['alt'] ?? '';

        // Save the file.
        $directory = 'public://mcp_file_content/images';
        $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
        $uri = $directory . '/' . $filename;
        $this->fileSystem->saveData($data, $uri, FileSystemInterface::EXISTS_RENAME);

        // Create file entity.
        $fileStorage = $this->entityTypeManager->getStorage('file');
        $file = $fileStorage->create([
          'uri' => $uri,
          'filename' => $filename,
          'filemime' => $mimeType,
          'status' => 1,
        ]);
        $file->save();

        // Create media entity.
        $mediaStorage = $this->entityTypeManager->getStorage('media');
        $media = $mediaStorage->create([
          'bundle' => 'image',
          'name' => $alt ?: $filename,
          'field_media_image' => [
            'target_id' => $file->id(),
            'alt' => $alt,
          ],
        ]);
        $media->save();

        $created[] = [
          'media_id' => $media->id(),
          'file_id' => $file->id(),
          'filename' => $filename,
        ];
      }
      catch (\Exception $e) {
        // Non-fatal: continue with other images.
      }
    }

    return $created;
  }

}
