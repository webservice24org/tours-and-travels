<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Validation\ValidationException;

/** Conservative server-side Tiptap HTML allowlist. No scripts, inline styles or remote images. */
final class TourHtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'blockquote',
        'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'code', 'pre', 'a', 'img',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'sub', 'sup',
    ];

    /** @var list<string> */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'svg', 'math', 'link', 'meta', 'head', 'video', 'audio', 'textarea',
    ];

    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        if (mb_strlen($html) > 20000) {
            throw ValidationException::withMessages(['description' => 'Description cannot exceed 20,000 characters.']);
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"?><!DOCTYPE html><html><body><div id="tour-editor-root">'.$html.'</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        $root = $document->getElementById('tour-editor-root');
        if (! $root instanceof DOMElement) {
            throw ValidationException::withMessages(['description' => 'Unable to parse description.']);
        }
        $this->sanitizeChildren($root);
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child) ?: '';
        }
        return trim($output) ?: null;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }
                $this->sanitizeChildren($node);
                if (! in_array($tag, self::ALLOWED, true)) {
                    while ($node->firstChild !== null) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                } else {
                    $this->sanitizeAttributes($node, $tag);
                }
            } elseif (! in_array($node->nodeType, [XML_TEXT_NODE], true)) {
                $parent->removeChild($node);
            }
            $node = $next;
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $href = $tag === 'a' ? trim($element->getAttribute('href')) : '';
        $src = $tag === 'img' ? trim($element->getAttribute('src')) : '';
        $alt = $tag === 'img' ? mb_substr($element->getAttribute('alt'), 0, 200) : '';
        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute === null) break;
            $element->removeAttributeNode($attribute);
        }
        if ($tag === 'a' && preg_match('~^(https?://|mailto:|/|#)~i', $href) === 1 && ! str_starts_with($href, '//')) {
            $element->setAttribute('href', $href);
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
        }
        // Never persist a remote, data: or javascript: image. Only tour-managed images.
        if ($tag === 'img' && preg_match('~^/storage/tours/[0-9]+/[a-f0-9-]{36}\\.(jpg|png|webp)$~', $src) === 1) {
            $element->setAttribute('src', $src);
            $element->setAttribute('alt', $alt);
        } elseif ($tag === 'img') {
            $element->parentNode?->removeChild($element);
        }
    }
}
