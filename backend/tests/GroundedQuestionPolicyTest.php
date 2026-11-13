<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ai/GroundedQuestionPolicy.php';

use Kavoryn\Ai\GroundedQuestionPolicy;

function questionExpect(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$source = 'Payment deadline: 30 November 2026. Total: NGN 15,000.';
$valid = GroundedQuestionPolicy::validate([
    'answer' => 'The payment deadline is 30 November 2026.',
    'source_excerpts' => ['Payment deadline: 30 November 2026.'],
    'insufficient_information' => false,
], $source);
questionExpect($valid['source_excerpts'][0] === 'Payment deadline: 30 November 2026.', 'Exact source excerpt should pass.');

$unknown = GroundedQuestionPolicy::validate([
    'answer' => 'The document does not provide enough information to answer this question.',
    'source_excerpts' => [],
    'insufficient_information' => true,
], $source);
questionExpect($unknown['insufficient_information'] === true, 'Unsupported answer must use the explicit insufficient-information state.');

foreach ([
    [['answer'=>'NGN 99 is due tomorrow.','source_excerpts'=>['NGN 99'],'insufficient_information'=>false], 'fabricated quote'],
    [['answer'=>'x','source_excerpts'=>[],'insufficient_information'=>false], 'missing evidence'],
    [['answer'=>'x','source_excerpts'=>['Payment deadline: 1 January.'],'insufficient_information'=>false], 'non-source quote'],
    [['answer'=>'x','source_excerpts'=>['Payment deadline: 30 November 2026.'],'insufficient_information'=>true], 'contradictory insufficient state'],
] as [$candidate, $label]) {
    try {
        GroundedQuestionPolicy::validate($candidate, $source);
        throw new RuntimeException('Expected rejection: ' . $label);
    } catch (InvalidArgumentException) {
        // Expected fail-closed behavior.
    }
}

questionExpect(
    GroundedQuestionPolicy::validateQuestion('  Wace ce ranar biya?  ') === 'Wace ce ranar biya?',
    'Question should be trimmed.'
);
try {
    GroundedQuestionPolicy::validateQuestion(str_repeat('a', 1001));
    throw new RuntimeException('Oversized question should be rejected.');
} catch (InvalidArgumentException) {
    // Expected.
}

echo "Grounded question policy tests passed." . PHP_EOL;
