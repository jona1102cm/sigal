<?php

namespace App\Domain\DocumentManagement\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Reduce el HTML del editor al subconjunto permitido antes de persistirlo/mostrarlo.
 *
 * El frontend no es una frontera de confianza: todo informe enriquecido vuelve a
 * sanearse en el backend para evitar etiquetas, atributos y URLs peligrosas.
 */
class RichTextSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'div', 'em', 'h2', 'h3', 'i', 'li', 'ol', 'p', 'strong', 'u', 'ul',
    ];

    private const REMOVED_TAGS = [
        'applet', 'base', 'embed', 'form', 'frame', 'iframe', 'input', 'link', 'meta', 'object', 'script', 'style',
    ];

    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML(
            '<!DOCTYPE html><html><body><section id="content">'.$html.'</section></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        /** @var DOMElement|null $container */
        $container = $document->getElementById('content');

        if ($container === null) {
            return null;
        }

        $this->sanitizeChildren($container);

        $output = '';
        foreach ($container->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output) ?: null;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::REMOVED_TAGS, true)) {
                $parent->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->sanitizeChildren($child);

                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);

                continue;
            }

            $this->sanitizeAttributes($child, $tag);
            $this->sanitizeChildren($child);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute->name;
        }

        foreach ($attributes as $attribute) {
            if ($tag !== 'a' || $attribute !== 'href') {
                $element->removeAttribute($attribute);
            }
        }

        if ($tag !== 'a' || ! $element->hasAttribute('href')) {
            return;
        }

        $href = trim($element->getAttribute('href'));
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        if ($href === '' || ($scheme !== '' && ! in_array($scheme, ['http', 'https', 'mailto'], true))) {
            $element->removeAttribute('href');

            return;
        }

        $element->setAttribute('rel', 'noopener noreferrer');
    }
}
