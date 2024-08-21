<?php

/**
 * This file is part of the package netresearch/rte-ckeditor-image.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\RteCKEditorImage\Backend\Preview;

use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Frontend\Preview\TextPreviewRenderer;

/**
 * Renders the preview of TCA "text" elements. This class overrides the
 * default \TYPO3\CMS\Frontend\Preview\TextPreviewRenderer and extends its functionality to
 * include images in the preview.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license https://www.gnu.org/licenses/agpl-3.0.de.html
 * @link    https://www.netresearch.de
 */
class RteImagePreviewRenderer extends TextPreviewRenderer
{
    private bool $reachedLimit = false;

    private int $totalLength = 0;

    /**
     * @var \DOMNode[]
     */
    private array $toRemove = [];

    /**
     * Dedicated method for rendering preview body HTML for the page module only.
     * Receives the GridColumnItem that contains the record for which a preview should be
     * rendered and returned.
     */
    public function renderPageModulePreviewContent(GridColumnItem $gridColumnItem): string
    {
        $row  = $gridColumnItem->getRecord();
        $html = $row['bodytext'] ?? '';

        $html = self::sanitizeHtml($html);

        return $this
            ->linkEditContent(
                $this->renderTextWithHtml($html),
                $row
            )
            . '<br />';
    }

    /**
     * Sanitizes HTML by replacing invalid characters with U+FFFD.
     */
    private static function sanitizeHtml(string $html): string
    {
        // Sanitize HTML: replaces
        // - Invalid control chars: [\x00-\x08\x0B\x0C\x0E-\x1F]
        $controlChars = "[\x00-\x08\x0B\x0C\x0E-\x1F]";
        // - UTF-16 surrogates: \xED[\xA0-\xBF].
        $invalidUtf8Surrogates = "\xED[\xA0-\xBF].";
        // - Non-characters U+FFFE and U+FFFF: \xEF\xBF[\xBE\xBF]
        $invalidUtf8NonChars = "\xEF\xBF[\xBE\xBF]";

        $pattern = '/' . $controlChars . '|' . $invalidUtf8Surrogates . '|' . $invalidUtf8NonChars . '/';

        // with U+FFFD.
        $placeholder = '�';

        return preg_replace($pattern, $placeholder, $html) ?? '';
    }

    /**
     * Processing of larger amounts of text (usually from RTE/bodytext fields) with word wrapping etc.
     *
     * @param  string $input Input string
     * @return string Output string
     */
    private function renderTextWithHtml(string $input): string
    {
        // Allow only <img> and <p>-tags in preview, to prevent possible HTML mismatch
        $input = strip_tags($input, '<img><p>');

        return $this->truncate($input, 1500);
    }

    /**
     * Truncates the given text, but preserves HTML tags.
     *
     * @see https://stackoverflow.com/questions/16583676/shorten-text-without-splitting-words-or-breaking-html-tags
     */
    private function truncate(string $html, int $length): string
    {
        // Set error level
        $internalErrors = libxml_use_internal_errors(true);

        $domDocument = new \DOMDocument();
        $domDocument->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        // Restore error level
        libxml_use_internal_errors($internalErrors);

        $toRemove = $this->walk($domDocument, $length);

        // Remove any nodes that exceed limit
        foreach ($toRemove as $child) {
            $child->parentNode?->removeChild($child);
        }

        $result = $domDocument->saveHTML();

        return $result === false ? '' : $result;
    }

    /**
     * Walk the DOM tree and collect the length of all text nodes.
     *
     * @return \DOMNode[]
     */
    private function walk(\DOMNode $domNode, int $maxLength): array
    {
        if ($this->reachedLimit) {
            $this->toRemove[] = $domNode;
        } else {
            // Only text nodes should have a text, so do the splitting here
            if (($domNode instanceof \DOMText) && ($domNode->nodeValue !== null)) {
                $nodeLen = mb_strlen($domNode->nodeValue);
                $this->totalLength += $nodeLen;

                if ($this->totalLength > $maxLength) {
                    $domNode->nodeValue = mb_substr(
                        $domNode->nodeValue,
                        0,
                        $nodeLen - ($this->totalLength - $maxLength)
                    ) . '...';

                    $this->reachedLimit = true;
                }
            }

            // We need to explizitly check hasChildNodes() to circumvent a bug in PHP < 7.4.4
            // which results in childNodes being NULL https://bugs.php.net/bug.php?id=79271
            if ($domNode->hasChildNodes()) {
                foreach ($domNode->childNodes as $child) {
                    $this->walk($child, $maxLength);
                }
            }
        }

        return $this->toRemove;
    }
}
