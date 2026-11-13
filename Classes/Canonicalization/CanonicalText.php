<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Canonicalization;

/**
 * Canonical text and content hash of a published page, as defined in Annex A.2
 * and A.3 of the Content Provenance Specification. Each step is named after
 * its section; the conformance vectors check the result byte for byte.
 */
final class CanonicalText
{
    private const CONTENT_ROOT_ATTRIBUTE = 'data-cps-content';
    private const IGNORE_ATTRIBUTE = 'data-cps-ignore';
    private const LINE_BREAK = "\n";

    /** A.2.2 */
    private const EXCLUDED_ELEMENTS = [
        'head', 'script', 'style', 'template', 'noscript', 'iframe', 'object',
        'embed', 'canvas', 'svg', 'button', 'input', 'select', 'textarea',
    ];

    /** A.2.3 */
    private const BLOCK_ELEMENTS = [
        'address', 'article', 'aside', 'blockquote', 'caption', 'dd', 'details',
        'dialog', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer',
        'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'li',
        'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody',
        'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /**
     * A.2.4 step 1: General_Category Cf as of Unicode 15.1, frozen, plus the C0
     * and C1 controls not handled as whitespace.
     */
    private const INVISIBLE = '/[\x{0000}-\x{0008}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{0600}-\x{0605}'
        . '\x{061C}\x{06DD}\x{070F}\x{0890}-\x{0891}\x{08E2}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}'
        . '\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{FFF9}-\x{FFFB}\x{110BD}\x{110CD}\x{13430}-\x{1343F}'
        . '\x{1BCA0}-\x{1BCA3}\x{1D173}-\x{1D17A}\x{E0001}\x{E0020}-\x{E007F}]/u';

    /** A.2.4 step 3 */
    private const WHITESPACE = '/[\x{0009}\x{000B}\x{000C}\x{000D}\x{00A0}\x{1680}\x{2000}-\x{200A}'
        . '\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u';

    /** A.2: canonical text of the document content */
    public function extract(string $html): string
    {
        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        $raw = '';
        foreach ($this->contentRoots($document) as $root) {
            $raw .= self::LINE_BREAK . $this->collect($root, false) . self::LINE_BREAK;
        }

        return $this->normalise($raw);
    }

    /** A.3: "sha256:" and the lowercase hex SHA-256 of the canonical text */
    public function contentHash(string $html): string
    {
        return 'sha256:' . hash('sha256', $this->extract($html));
    }

    /** A.2.4 */
    public function normalise(string $raw): string
    {
        $withoutInvisible = (string)preg_replace(self::INVISIBLE, '', $raw);
        $composed = (string)\Normalizer::normalize($withoutInvisible, \Normalizer::FORM_C);
        $spaced = (string)preg_replace(self::WHITESPACE, ' ', $composed);

        $lines = [];
        foreach (explode(self::LINE_BREAK, $spaced) as $line) {
            $collapsed = trim((string)preg_replace('/ {2,}/', ' ', $line), ' ');
            if ($collapsed !== '') {
                $lines[] = $collapsed;
            }
        }

        return implode(self::LINE_BREAK, $lines);
    }

    /**
     * A.2.1: marked regions, else the first main element, else body.
     *
     * @return list<\Dom\Element>
     */
    private function contentRoots(\Dom\HTMLDocument $document): array
    {
        $marked = iterator_to_array($document->querySelectorAll('[' . self::CONTENT_ROOT_ATTRIBUTE . ']'), false);
        if ($marked !== []) {
            return array_values(array_filter(
                $marked,
                static fn(\Dom\Element $element): bool => $element->parentElement?->closest('[' . self::CONTENT_ROOT_ATTRIBUTE . ']') === null
            ));
        }

        $main = $document->querySelector('main');
        if ($main !== null) {
            return [$main];
        }

        return $document->body !== null ? [$document->body] : [];
    }

    /**
     * A.2.2 and A.2.3. Line breaks come from structure only; a line feed in a
     * text node is a space, except inside pre.
     */
    private function collect(\Dom\Node $node, bool $inPre): string
    {
        if ($node instanceof \Dom\Text) {
            return $inPre ? $node->data : str_replace(self::LINE_BREAK, ' ', $node->data);
        }
        if (!$node instanceof \Dom\Element || $this->isExcluded($node)) {
            return '';
        }

        $name = $node->localName;
        $insidePre = $inPre || $name === 'pre';
        $isBlock = in_array($name, self::BLOCK_ELEMENTS, true);

        $text = $isBlock ? self::LINE_BREAK : '';
        if ($name === 'br') {
            $text .= self::LINE_BREAK;
        } elseif ($name === 'img') {
            $alt = (string)$node->getAttribute('alt');
            $text .= $insidePre ? $alt : str_replace(self::LINE_BREAK, ' ', $alt);
        }
        foreach ($node->childNodes as $child) {
            $text .= $this->collect($child, $insidePre);
        }

        return $isBlock ? $text . self::LINE_BREAK : $text;
    }

    private function isExcluded(\Dom\Element $element): bool
    {
        return in_array($element->localName, self::EXCLUDED_ELEMENTS, true)
            || $element->hasAttribute('hidden')
            || strtolower((string)$element->getAttribute('aria-hidden')) === 'true'
            || $element->hasAttribute(self::IGNORE_ATTRIBUTE);
    }
}
