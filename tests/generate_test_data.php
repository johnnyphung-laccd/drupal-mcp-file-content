<?php

/**
 * @file
 * Generates synthetic test data for MCP File Content module.
 */

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$dir = '/tmp/test-files';
if (!is_dir($dir)) {
  mkdir($dir, 0755, TRUE);
}

// Clean existing files.
array_map('unlink', glob("$dir/*"));

// --- SYNTHETIC DOCX FILES ---
$files = [
  'Bio3_Lab' => ['title' => 'Biology 3 Laboratory Manual', 'sections' => [
    'Introduction' => 'This laboratory manual covers basic biology experiments for LAMC students.',
    'Lab Safety' => 'All students must wear safety goggles and gloves in the laboratory.',
    'Experiment 1: Cell Structure' => 'Observe cell structures under a microscope. Document your findings.',
  ]],
  'What_is_Business_Administration' => ['title' => 'What is Business Administration?', 'sections' => [
    'Overview' => 'Business Administration prepares students for careers in management and commerce.',
    'Career Paths' => 'Graduates may pursue roles in marketing, finance, human resources, or operations.',
    'Transfer Requirements' => 'Complete all major prerequisite courses with a grade of C or better.',
  ]],
  'Nutrition_and_Dietetics' => ['title' => 'Nutrition and Dietetics Program', 'sections' => [
    'Program Description' => 'The Nutrition and Dietetics program provides foundational knowledge in food science.',
    'Course Requirements' => 'Students must complete 60 units including biology, chemistry, and nutrition courses.',
    'Career Opportunities' => 'Graduates can work as nutritionists, dietitians, or food service managers.',
  ]],
  'What_is_Chicano_Studies' => ['title' => 'What is Chicano Studies?', 'sections' => [
    'Department Overview' => 'Chicano Studies explores the history, culture, and contributions of Mexican Americans.',
    'Courses Offered' => 'Introductory courses cover Chicano history, literature, and political movements.',
  ]],
  'What_is_Multimedia_Studies' => ['title' => 'What is Multimedia Studies?', 'sections' => [
    'Program Description' => 'Multimedia Studies combines graphic design, video production, and web development.',
    'Technical Skills' => 'Students learn Adobe Creative Suite, HTML/CSS, and video editing.',
    'Student Projects' => 'Complete a capstone portfolio demonstrating multimedia production skills.',
  ]],
];

foreach ($files as $name => $data) {
  $phpWord = new \PhpOffice\PhpWord\PhpWord();
  $phpWord->addTitleStyle(1, ['bold' => TRUE, 'size' => 24]);
  $phpWord->addTitleStyle(2, ['bold' => TRUE, 'size' => 18]);
  $section = $phpWord->addSection();
  $section->addTitle($data['title'], 1);
  foreach ($data['sections'] as $heading => $text) {
    $section->addTitle($heading, 2);
    $section->addText($text);
  }
  $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
  $writer->save("$dir/$name.docx");
}
echo "Created " . count($files) . " DOCX files\n";

// --- SYNTHETIC BROKEN FILES ---

// 1. DOCX with skipped headings (H1->H3->H5)
$phpWord = new \PhpOffice\PhpWord\PhpWord();
$phpWord->addTitleStyle(1, ['bold' => TRUE, 'size' => 24]);
$phpWord->addTitleStyle(3, ['bold' => TRUE, 'size' => 14]);
$phpWord->addTitleStyle(5, ['bold' => TRUE, 'size' => 10]);
$section = $phpWord->addSection();
$section->addTitle('Main Title', 1);
$section->addText('Some content under the main title.');
$section->addTitle('Skipped to H3', 3);
$section->addText('This heading skipped H2.');
$section->addTitle('Skipped to H5', 5);
$section->addText('This heading skipped H4.');
$writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$writer->save("$dir/broken_skipped_headings.docx");
echo "Created broken_skipped_headings.docx\n";

// 2. DOCX with tables missing TH/scope
$phpWord = new \PhpOffice\PhpWord\PhpWord();
$section = $phpWord->addSection();
$section->addText('Course Schedule');
$table = $section->addTable();
$table->addRow();
$table->addCell(2000)->addText('Course');
$table->addCell(2000)->addText('Units');
$table->addCell(2000)->addText('Grade');
$table->addRow();
$table->addCell(2000)->addText('Math 101');
$table->addCell(2000)->addText('3');
$table->addCell(2000)->addText('A');
$table->addRow();
$table->addCell(2000)->addText('Eng 101');
$table->addCell(2000)->addText('3');
$table->addCell(2000)->addText('B');
$writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$writer->save("$dir/broken_table_no_headers.docx");
echo "Created broken_table_no_headers.docx\n";

// 3. HTML with missing alt text
$html = '<h1>Document with Missing Alt Text</h1>';
$html .= '<p>This page has images without alt text:</p>';
$html .= '<img src="campus.jpg">';
$html .= '<img src="students.jpg">';
$html .= '<img src="IMG_1234.jpg" alt="IMG_1234.jpg">';
file_put_contents("$dir/broken_missing_alt.html", $html);
echo "Created broken_missing_alt.html\n";

// 4. HTML with low-contrast text
$html = '<h1>Low Contrast Page</h1>';
$html .= '<p style="color:#999;background-color:#FFF">This text has very low contrast (about 2.85:1 ratio).</p>';
$html .= '<p style="color:#666;background-color:#EEE">Another low contrast paragraph.</p>';
$html .= '<p>Normal paragraph without inline styles.</p>';
file_put_contents("$dir/broken_low_contrast.html", $html);
echo "Created broken_low_contrast.html\n";

// 5. HTML with pseudo-lists
$html = '<h1>Document with Pseudo Lists</h1>';
$html .= '<p>Required materials:</p>';
$html .= '<p>- Textbook (latest edition)</p>';
$html .= '<p>- Scientific calculator</p>';
$html .= '<p>- Lab notebook</p>';
$html .= '<p>- Safety goggles</p>';
$html .= '<p>Course schedule:</p>';
$html .= '<p>1. Introduction to Biology</p>';
$html .= '<p>2. Cell Structure and Function</p>';
$html .= '<p>3. Genetics and Heredity</p>';
$html .= '<p>4. Ecology and Environment</p>';
file_put_contents("$dir/broken_pseudo_lists.html", $html);
echo "Created broken_pseudo_lists.html\n";

// 6. TXT file (simple)
file_put_contents("$dir/sample_text.txt", "Los Angeles Mission College\n\nCampus Information\n\nThe campus is located in Sylmar, California.\nWe offer associate degrees and transfer programs.\nVisit our website for more information.");
echo "Created sample_text.txt\n";

// 7. CSV file
file_put_contents("$dir/course_schedule.csv", "Course,Units,Days,Time,Room\nMath 227,5,MWF,8:00-9:50,CMS 202\nEnglish 101,3,TTh,10:00-11:15,INST 1003\nBiology 3,4,MW,1:00-2:50,CMS 118");
echo "Created course_schedule.csv\n";

echo "\n=== All test files ===\n";
foreach (glob("$dir/*") as $f) {
  printf("%-40s %s bytes\n", basename($f), filesize($f));
}
