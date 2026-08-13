<?php

use App\Domain\DocumentManagement\Services\RichTextSanitizer;

test('rich text keeps permitted formatting and removes executable markup', function () {
    $content = app(RichTextSanitizer::class)->sanitize(
        '<p>Informe <strong>prioritario</strong></p><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">enlace</a>',
    );

    expect($content)
        ->toContain('<strong>prioritario</strong>')
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->not->toContain('javascript:')
        ->toContain('enlace');
});
