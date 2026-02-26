<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\mcp_file_content\Service\AccessibilityReportGenerator;
use Drupal\mcp_file_content\Service\AccessibilityValidator;
use Drupal\mcp_file_content\Service\AltTextRequirementChecker;
use Drupal\mcp_file_content\Service\ColorContrastChecker;
use Drupal\mcp_file_content\Service\HeadingHierarchyAnalyzer;
use Drupal\mcp_file_content\Service\LanguageChecker;
use Drupal\mcp_file_content\Service\LinkTextChecker;
use Drupal\mcp_file_content\Service\ListMarkupChecker;
use Drupal\mcp_file_content\Service\TableAccessibilityChecker;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\AccessibilityReportGenerator
 * @group mcp_file_content
 */
class AccessibilityReportGeneratorTest extends UnitTestCase {

  protected AccessibilityReportGenerator $generator;

  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('standard');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $validator = new AccessibilityValidator(
      new ColorContrastChecker(),
      new HeadingHierarchyAnalyzer(),
      new AltTextRequirementChecker(),
      new LinkTextChecker(),
      new ListMarkupChecker(),
      new TableAccessibilityChecker(),
      new LanguageChecker(),
      $configFactory,
    );

    $this->generator = new AccessibilityReportGenerator($validator);
  }

  public function testJsonReport(): void {
    $report = $this->generator->generateReport('<h1>Title</h1><p>Content</p>', 'json', 'Test Page');
    $data = json_decode($report, TRUE);
    $this->assertIsArray($data);
    $this->assertEquals('Test Page', $data['title']);
    $this->assertArrayHasKey('score', $data);
    $this->assertEquals('2.1', $data['wcag_version']);
  }

  public function testHtmlReport(): void {
    $report = $this->generator->generateReport('<img src="test.jpg">', 'html', 'Test');
    $this->assertStringContainsString('accessibility-report', $report);
    $this->assertStringContainsString('Errors', $report);
  }

  public function testCsvReport(): void {
    $report = $this->generator->generateReport('<img src="test.jpg">', 'csv');
    $this->assertStringContainsString('Criterion,Severity', $report);
    $this->assertStringContainsString('1.1.1', $report);
  }

  public function testBatchReport(): void {
    $items = [
      ['title' => 'Page 1', 'html' => '<h1>Title</h1><p>Good content</p>'],
      ['title' => 'Page 2', 'html' => '<img src="bad.jpg">'],
    ];
    $report = $this->generator->generateBatchReport($items, 'json');
    $data = json_decode($report, TRUE);
    $this->assertEquals(2, $data['total_items']);
    $this->assertArrayHasKey('average_score', $data);
  }

}
