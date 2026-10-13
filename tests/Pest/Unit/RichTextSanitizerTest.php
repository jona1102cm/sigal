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

test('rich text preserves UTF-8 institutional text and safe tabular data', function () {
    $content = app(RichTextSanitizer::class)->sanitize(
        '<h2>ACTA DE ENTREGA-RECEPCIÓN</h2><table class="acta"><thead><tr><th>Área</th></tr></thead><tbody><tr><td>Sección de Servicios Informáticos</td></tr></tbody></table>',
    );

    expect($content)
        ->toContain('ACTA DE ENTREGA-RECEPCIÓN')
        ->toContain('<table>')
        ->toContain('<th>Área</th>')
        ->toContain('Sección de Servicios Informáticos')
        ->not->toContain('class=');
});
