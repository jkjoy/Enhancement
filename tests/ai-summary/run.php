<?php
/** Offline checks for the exact text sent to AI summary generation. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/AiHelper.php';

$plainText = new ReflectionMethod('Enhancement_AiHelper', 'summaryToPlainText');
$sourceText = new ReflectionMethod('Enhancement_AiHelper', 'buildSummarySourceText');
if (PHP_VERSION_ID < 80100) {
    $plainText->setAccessible(true);
    $sourceText->setAccessible(true);
}
$cases = array(
    'ordinary Markdown and HTML' => array('<!--markdown--><p>Public **text** [link](https://example.test)</p>', 'Public **text** link'),
    'mixed public and hidden content' => array('Before [reply]PRIVATE_SENTINEL[/reply] after', 'Before after'),
    'hidden content only' => array('[reply]PRIVATE_SENTINEL[/reply]', ''),
    'nested hidden content' => array('Before [reply]outer [reply]PRIVATE_SENTINEL[/reply] tail[/reply] after', 'Before after'),
    'unclosed hidden content' => array('Before [reply]PRIVATE_SENTINEL', 'Before'),
    'rendered unlocked content' => array('Before <!--enhancement-reply-start--><div class="enhancement-reply">PRIVATE_SENTINEL</div><!--enhancement-reply-end--> after', 'Before after'),
    'unclosed rendered content' => array('Before <!--enhancement-reply-start--><div>PRIVATE_SENTINEL</div>', 'Before'),
    'multiple hidden blocks' => array('Before [REPLY]PRIVATE_SENTINEL[/REPLY] middle [reply]PRIVATE_SENTINEL[/reply] after', 'Before middle after'),
);
$passed = 0;
foreach ($cases as $label => $case) {
    list($input, $expected) = $case;
    $actual = $plainText->invoke(null, $input);
    if ($actual !== $expected || strpos($actual, 'PRIVATE_SENTINEL') !== false) {
        throw new RuntimeException('FAIL ' . $label . ': ' . var_export($actual, true));
    }
    $passed++;
    $source = $sourceText->invoke(null, 'Public title', $input, (object)array());
    $expectedSource = $expected === '' ? '' : "标题：Public title\n\n正文：" . $expected;
    if ($source !== $expectedSource) {
        throw new RuntimeException('FAIL final summary source: ' . $label . ': ' . var_export($source, true));
    }
    $passed++;
}
echo 'AI summary privacy checks passed: ' . $passed . PHP_EOL;
