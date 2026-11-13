<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
require_once dirname(__DIR__) . '/src/Ai/DocumentExplainer.php';
require_once dirname(__DIR__) . '/src/Ai/OpenAiDocumentExplainer.php';
require_once dirname(__DIR__) . '/src/Ai/GeminiDocumentExplainer.php';
require_once dirname(__DIR__) . '/src/Ai/GroundedQuestionPolicy.php';
require_once dirname(__DIR__) . '/src/Ai/DocumentQuestionAnswerer.php';
require_once dirname(__DIR__) . '/src/Ai/OpenAiDocumentQuestionAnswerer.php';
require_once dirname(__DIR__) . '/src/Ai/GeminiDocumentQuestionAnswerer.php';

use Kavoryn\Ai\OpenAiDocumentQuestionAnswerer;
use Kavoryn\Ai\GeminiDocumentQuestionAnswerer;
use Kavoryn\Ai\OpenAiUnavailableException;
use Kavoryn\Ai\GeminiUnavailableException;
use Kavoryn\Ocr\OcrResult;

function providerExpect(bool $condition,string $message):void
{
    if(!$condition){fwrite(STDERR,$message.PHP_EOL);exit(1);}
}

$ocr=new OcrResult('Payment deadline: 30 November 2026. Total: NGN 15,000.');
$openAiResponse=['status'=>200,'body'=>json_encode([
    'status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode([
        'answer'=>'Ranar biya ita ce 30 November 2026.',
        'source_excerpts'=>['Payment deadline: 30 November 2026.'],
        'insufficient_information'=>false,
    ],JSON_UNESCAPED_UNICODE)]]]],
])];
$openAi=new OpenAiDocumentQuestionAnswerer('test-key','gpt-test',function($url,$headers,$body)use($openAiResponse){
    $payload=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    if(($payload['store']??null)!==false)throw new RuntimeException('OpenAI storage must be disabled.');
    if(!str_contains($payload['instructions'],'untrusted data'))throw new RuntimeException('OCR must be treated as untrusted data.');
    return $openAiResponse;
});
$answer=$openAi->answer($ocr,'What is the deadline?','ha');
providerExpect($answer['source_excerpts'][0]==='Payment deadline: 30 November 2026.','OpenAI exact evidence should pass.');

$geminiResponse=['status'=>200,'body'=>json_encode([
    'status'=>'completed','steps'=>[['type'=>'model_output','content'=>[['type'=>'text','text'=>json_encode([
        'answer'=>'The document does not state the recipient name.',
        'source_excerpts'=>[],
        'insufficient_information'=>true,
    ])]]]],
])];
$gemini=new GeminiDocumentQuestionAnswerer('test-key','gemini-test',function($url,$headers,$body)use($geminiResponse){
    $payload=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    if(($payload['store']??null)!==false)throw new RuntimeException('Gemini storage must be disabled.');
    if(!str_contains($payload['system_instruction'],'untrusted data'))throw new RuntimeException('OCR must be treated as untrusted data.');
    return $geminiResponse;
});
$unknown=$gemini->answer($ocr,'Who is the recipient?','en');
providerExpect($unknown['insufficient_information']===true,'Gemini unsupported answer should pass.');

$badResponse=['status'=>200,'body'=>json_encode([
    'status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode([
        'answer'=>'Payment is due tomorrow.',
        'source_excerpts'=>['Payment is due tomorrow.'],
        'insufficient_information'=>false,
    ])]]]],
])];
$bad=new OpenAiDocumentQuestionAnswerer('test-key','gpt-test',fn()=>$badResponse);
try{$bad->answer($ocr,'When is payment due?','en');throw new RuntimeException('Fabricated quote must fail closed.');}
catch(OpenAiUnavailableException){}

try{$openAi->answer($ocr,str_repeat('x',1001),'en');throw new RuntimeException('Oversized question must fail.');}
catch(InvalidArgumentException){}

echo "Document Q&A provider adapter tests passed.".PHP_EOL;
