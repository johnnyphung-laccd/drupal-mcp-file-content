<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_file_content\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\mcp_file_content\Service\AccessibilityRemediator;
use Drupal\mcp_file_content\Service\HeadingHierarchyAnalyzer;
use Drupal\mcp_file_content\Service\ListMarkupChecker;
use Drupal\mcp_file_content\Service\TableAccessibilityChecker;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mcp_file_content\Service\AccessibilityRemediator
 * @group mcp_file_content
 */
class AccessibilityRemediatorTest extends UnitTestCase {

  protected AccessibilityRemediator $remediator;

  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['accessibility.auto_remediate_headings', TRUE],
      ['accessibility.auto_remediate_tables', TRUE],
      ['accessibility.auto_remediate_lists', TRUE],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('mcp_file_content.settings')->willReturn($config);

    $this->remediator = new AccessibilityRemediator(
      new HeadingHierarchyAnalyzer(),
      new TableAccessibilityChecker(),
      new ListMarkupChecker(),
      $configFactory,
    );
  }

  public function testFixHeadingHierarchy(): void {
    $html = '<h1>Title</h1><h3>Should be H2</h3>';
    $result = $this->remediator->remediate($html);
    $this->assertStringContainsString('<h2>', $result['content']);
    $this->assertNotEmpty($result['fixes_applied']);
    $this->assertGreaterThan(0, $result['fix_count']);
  }

  public function testFixTableHeaders(): void {
    $html = '<table><tr><td>Name</td><td>Value</td></tr><tr><td>A</td><td>1</td></tr></table>';
    $result = $this->remediator->remediate($html);
    $this->assertStringContainsString('<th', $result['content']);
    $this->assertStringContainsString('scope=', $result['content']);
  }

  public function testFixPseudoLists(): void {
    $html = '<p>- Item one</p><p>- Item two</p><p>- Item three</p>';
    $result = $this->remediator->remediate($html);
    $this->assertStringContainsString('<ul>', $result['content']);
    $this->assertStringContainsString('<li>', $result['content']);
  }

  public function testBoldParagraphToHeading(): void {
    $html = '<p><strong>This is a fake heading</strong></p><p>Regular content.</p>';
    $result = $this->remediator->remediate($html);
    $this->assertStringContainsString('<h2>', $result['content']);
  }

  public function testNoChangesWhenCompliant(): void {
    $html = '<h1>Title</h1><h2>Section</h2><p>Content.</p>';
    $result = $this->remediator->remediate($html);
    $this->assertEquals(0, $result['fix_count']);
  }

}
