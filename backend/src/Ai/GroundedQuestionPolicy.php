<?php

declare(strict_types=1);

namespace Kavoryn\\Ai;

/** Validates model output before it can be returned to a document owner. */
final class GroundedQuestionPolicy
{
    public const MAX_QUESTION_CHARACTERS = 1000;
    public const MAX_ANSWER_CHARACTERS = 6000;
    public const MAX_EXCERPTS = 5;
    public const MAX_EXCERPT_CHARACTERS = 500;

    /**
     * @param array<string,mixed> $candidate
     * @return array{answer:string,source_excerpts:list<string>,insufficient_information:bool}
     */
    public static function validate(array $candidate, string $ocrText): array
    {
        self::requireText($ocrText, 30000, 'OCR text');
        $answer = $candidate['answer'] ?? null;
        $excerpts = $candidate['source_excerpts'] ?? null;
        $insufficient = $candidate['insufficient_information'] ?? null;

        if (!is_string($answer) || trim($answer) === '' ||
            self::length($answer) > self::MAX_ANSWER_CHARACTERS ||
            !is_array($excerpts) || !array_is_list($excerpts) ||
            count($excerpts) > self::MAX_EXCERPTS || !is_bool($insufficient)) {
            throw new \\InvalidArgumentException('The answer format is invalid.');
        }

        $verified = [];
        foreach ($excerpts as $excerpt) {
            if (!is_string($excerpt) || trim($excerpt) === '' ||
                self::length($excerpt) > self::MAX_EXCERPT_CHARACTERS ||
                !str_contains($ocrText, $excerpt)) {
                throw new \\InvalidArgumentException('An answer source excerpt is not present in the OCR text.');
            }
            $verified[] = $excerpt;
        }

        if ($insufficient && $verified !== []) {
            throw new \\InvalidArgumentException('An insufficient answer cannot claim supporting excerpts.');
        }
        if (!$insufficient && $verified === []) {
            throw new \\InvalidArgumentException('A supported answer must include an exact source excerpt.');
        }

        return [
            'answer' => trim($answer),
            'source_excerpts' => $verified,
            'insufficient_information' => $insufficient,
        ];
    }

    public static function validateQuestion(string $question): string
    {
        $question = trim($question);
        self::requireText($question, self::MAX_QUESTION_CHARACTERS, 'Question');
        return $question;
    }

    private static function requireText(string $text, int $max, string $label): void
    {
        if (trim($text) === '' || preg_match('//u', $text) !== 1 || self::length($text) > $max) {
            throw new \\InvalidArgumentException($label . ' is empty, invalid, or too long.');
        }
    }

    private static function length(string $text): int
    {
        preg_match_all('/./us', $text, $characters);
        return count($characters[0]);
    }
}
