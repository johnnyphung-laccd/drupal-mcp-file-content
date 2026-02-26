<?php

declare(strict_types=1);

namespace Drupal\mcp_file_content\Plugin\Mcp;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Resource;
use Drupal\mcp\ServerFeatures\ResourceTemplate;
use Drupal\mcp_file_content\Service\FileExtractorManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides MCP resources for Drupal media entities.
 */
#[Mcp(
  id: 'file_content_resources',
  name: new TranslatableMarkup('File Content Resources'),
  description: new TranslatableMarkup('Exposes Drupal media files (PDF, DOCX, PPTX, images, text) as MCP resources for AI discovery and content extraction.'),
)]
class FileContentResources extends McpPluginBase {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileExtractorManager $extractorManager;
  protected FileSystemInterface $fileSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->extractorManager = $container->get('mcp_file_content.file_extractor_manager');
    $instance->fileSystem = $container->get('file_system');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getResources(): array {
    $resources = [];

    try {
      $mediaStorage = $this->entityTypeManager->getStorage('media');
      $query = $mediaStorage->getQuery()
        ->accessCheck(TRUE)
        ->range(0, 200)
        ->sort('created', 'DESC');
      $ids = $query->execute();

      if (empty($ids)) {
        return $resources;
      }

      $mediaEntities = $mediaStorage->loadMultiple($ids);
      $supportedTypes = $this->extractorManager->getSupportedMimeTypes();

      foreach ($mediaEntities as $media) {
        $file = $this->extractorManager->getFileFromMedia($media);
        if (!$file) {
          continue;
        }

        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $supportedTypes, TRUE)) {
          continue;
        }

        $resources[] = new Resource(
          uri: 'drupal://media/' . $media->bundle() . '/' . $media->id(),
          name: $media->getName(),
          description: sprintf(
            '%s (%s, %s)',
            $file->getFilename(),
            $mimeType,
            $this->formatBytes((int) $file->getSize())
          ),
          mimeType: $mimeType,
          text: NULL,
        );
      }
    }
    catch (\Exception $e) {
      // Return empty resources on error.
    }

    return $resources;
  }

  /**
   * {@inheritdoc}
   */
  public function getResourceTemplates(): array {
    return [
      new ResourceTemplate(
        uriTemplate: 'drupal://media/{type}/{id}',
        name: 'Media Entity',
        description: 'Access any media entity by type and ID.',
        mimeType: NULL,
      ),
      new ResourceTemplate(
        uriTemplate: 'drupal://media/documents',
        name: 'All Documents',
        description: 'List all document media (PDF, DOCX, DOC).',
        mimeType: NULL,
      ),
      new ResourceTemplate(
        uriTemplate: 'drupal://media/images',
        name: 'All Images',
        description: 'List all image media.',
        mimeType: NULL,
      ),
      new ResourceTemplate(
        uriTemplate: 'drupal://media/presentations',
        name: 'All Presentations',
        description: 'List all presentation media (PPTX, PPT).',
        mimeType: NULL,
      ),
      new ResourceTemplate(
        uriTemplate: 'drupal://media/all',
        name: 'All Media',
        description: 'List all supported media files.',
        mimeType: NULL,
      ),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function readResource(string $resourceId): array {
    // Parse the resource URI.
    $parsed = $this->parseResourceUri($resourceId);
    if (!$parsed) {
      return [
        'content' => [
          ['type' => 'text', 'text' => 'Invalid resource URI: ' . $resourceId],
        ],
      ];
    }

    // Collection URIs.
    if (in_array($parsed['type'], ['documents', 'images', 'presentations', 'all'])) {
      return $this->readCollection($parsed['type']);
    }

    // Individual media.
    return $this->readMediaEntity((int) $parsed['id']);
  }

  /**
   * Reads a collection of media entities.
   */
  protected function readCollection(string $type): array {
    $bundleMap = [
      'documents' => ['document'],
      'images' => ['image'],
      'presentations' => [],
      'all' => [],
    ];

    $resources = $this->getResources();
    $filtered = [];

    foreach ($resources as $resource) {
      $parts = explode('/', $resource->uri);
      $bundle = $parts[3] ?? '';

      $include = match ($type) {
        'documents' => in_array($bundle, ['document']) || str_contains($resource->mimeType ?? '', 'pdf') || str_contains($resource->mimeType ?? '', 'word'),
        'images' => str_starts_with($resource->mimeType ?? '', 'image/'),
        'presentations' => str_contains($resource->mimeType ?? '', 'presentation') || str_contains($resource->mimeType ?? '', 'powerpoint'),
        default => TRUE,
      };

      if ($include) {
        $filtered[] = [
          'uri' => $resource->uri,
          'name' => $resource->name,
          'description' => $resource->description,
          'mimeType' => $resource->mimeType,
        ];
      }
    }

    return [
      'content' => [
        ['type' => 'text', 'text' => json_encode($filtered, JSON_PRETTY_PRINT)],
      ],
    ];
  }

  /**
   * Reads an individual media entity.
   */
  protected function readMediaEntity(int $mediaId): array {
    try {
      $media = $this->entityTypeManager->getStorage('media')->load($mediaId);
      if (!$media) {
        return [
          'content' => [
            ['type' => 'text', 'text' => "Media entity {$mediaId} not found."],
          ],
        ];
      }

      $file = $this->extractorManager->getFileFromMedia($media);
      if (!$file) {
        return [
          'content' => [
            ['type' => 'text', 'text' => "No file found for media entity {$mediaId}."],
          ],
        ];
      }

      $mimeType = $file->getMimeType();

      // Images: return base64.
      if (str_starts_with($mimeType, 'image/')) {
        $realPath = $this->fileSystem->realpath($file->getFileUri());
        if ($realPath && file_exists($realPath)) {
          $data = base64_encode(file_get_contents($realPath));
          return [
            'content' => [
              [
                'type' => 'image',
                'data' => $data,
                'mimeType' => $mimeType,
              ],
            ],
          ];
        }
      }

      // Documents: extract content.
      $extracted = $this->extractorManager->extractFromMedia($media);
      return [
        'content' => [
          ['type' => 'text', 'text' => $extracted['content']],
        ],
      ];
    }
    catch (\Exception $e) {
      return [
        'content' => [
          ['type' => 'text', 'text' => 'Error reading resource: ' . $e->getMessage()],
        ],
      ];
    }
  }

  /**
   * Parses a resource URI.
   */
  protected function parseResourceUri(string $uri): ?array {
    // drupal://media/{type}/{id} or drupal://media/{collection}
    $cleaned = str_replace('drupal://media/', '', $uri);
    $parts = explode('/', $cleaned);

    if (count($parts) === 1) {
      return ['type' => $parts[0], 'id' => NULL];
    }

    if (count($parts) === 2) {
      return ['type' => $parts[0], 'id' => $parts[1]];
    }

    return NULL;
  }

  /**
   * Formats bytes to human-readable string.
   */
  protected function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) {
      return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
      return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' bytes';
  }

}
