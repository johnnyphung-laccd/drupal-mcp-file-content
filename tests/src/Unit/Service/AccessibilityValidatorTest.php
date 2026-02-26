<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
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
 * @coversDefaultClass \Drupal\mcp_file_content\Service\AccessibilityValidator
 * @group mcp_file_content
 */
class AccessibilityValidatorTest extends UnitTestCase {

  protected AccessibilityValidator $validator;

  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['accessibility.strictness', 'standard'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('mcp_file_content.settings')->willReturn($config);

    $this->validator = new AccessibilityValidator(
      new ColorContrastChecker(),
      new HeadingHierarchyAnalyzer(),
      new AltTextRequirementChecker(),
      new LinkTextChecker(),
      new ListMarkupChecker(),
      new TableAccessibilityChecker(),
      new LanguageChecker(),
      $configFactory,
    );
  }

  public function testCompliantContentPasses(): void {
    $html = '<h1>Title</h1><h2>Section</h2><p>Content with <a href="/page">descriptive link</a>.</p>';
    $result = $this->validator->validate($html);
    $this->assertGreaterThanOrEqual(70, $result['score']);
  }

  public function testNonCompliantContentFails(): void {
    $html = '<img src="photo.jpg"><h1>Title</h1><h3>Skipped</h3><a href="/page">click here</a>';
    $result = $this->validator->validate($html);
    $this->assertNotEmpty($result['errors']);
    $this->assertLessThan(100, $result['score']);
  }

  public function testScoreCalculation(): void {
    // Multiple errors should reduce score.
    $html = '<img src="a.jpg"><img src="b.jpg"><img src="c.jpg">';
    $result = $this->validator->validate($html);
    // 3 missing alt = -15 points.
    $this->assertLessThanOrEqual(85, $result['score']);
  }

  public function testSelectiveChecks(): void {
    $html = '<img src="photo.jpg"><h1>Title</h1><h3>Skipped</h3>';
    $result = $this->validator->validate($html, [
      'check_images' => FALSE,
      'check_headings' => TRUE,
    ]);
    // Alt text errors should not appear.
    $altErrors = array_filter($result['errors'], fn($e) => $e['criterion'] === '1.1.1');
    $this->assertEmpty($altErrors);
    // Heading errors should appear.
    $headingErrors = array_filter($result['errors'], fn($e) => $e['criterion'] === '1.3.1');
    $this->assertNotEmpty($headingErrors);
  }

  public function testRemediationActions(): void {
    $html = '<img src="photo.jpg">';
    $result = $this->validator->validate($html);
    $this->assertNotEmpty($result['remediation_actions']);
  }

  public function testSummaryGroupedByCriterion(): void {
    $html = '<img src="a.jpg"><img src="b.jpg"><h1>Title</h1><h3>Skip</h3>';
    $result = $this->validator->validate($html);
    $this->assertNotEmpty($result['summary']);
    $criteria = array_column($result['summary'], 'criterion');
    $this->assertContains('1.1.1', $criteria);
  }

}
