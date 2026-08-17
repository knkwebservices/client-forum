<?php
/**
 * lib/Markdown.php
 * A deliberately small, safe Markdown subset for forum posts — not a
 * full CommonMark implementation. Covers what a hosting-support forum
 * actually needs (bold/italic, inline + block code, links, lists,
 * blockquotes, a few heading sizes) without pulling in a third-party
 * dependency or opening up arbitrary HTML.
 *
 * SECURITY MODEL: the raw input is HTML-escaped FIRST, before any
 * Markdown syntax is turned into tags. Every tag this class emits after
 * that point is one *we* generate from a matched pattern — never from
 * unescaped user text — so there's no path for a user to inject a raw
 * <script> tag or an event handler attribute. Links are restricted to
 * http(s):// URLs only (no javascript:, data:, etc.), and get
 * rel="nofollow noopener noreferrer" + target="_blank".
 *
 * Posts are stored in the DB as raw Markdown (unchanged from before);
 * rendering to HTML happens here, at display time.
 */

namespace ClientForum;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Markdown
{
    public static function toHtml($raw)
    {
        // Step 1: escape everything. Nothing past this point is allowed
        // to introduce raw < > & from user input again.
        $text = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
        $text = str_replace("\r\n", "\n", $text);

        // Step 2: pull out fenced code blocks first so nothing inside
        // them gets touched by the inline rules below (bold/italic/links
        // inside a code block should stay literal).
        $codeBlocks = [];
        $text = preg_replace_callback('/```(?:[a-zA-Z0-9_-]*)\n(.*?)```/s', function ($m) use (&$codeBlocks) {
            $placeholder = "\x00CODEBLOCK" . count($codeBlocks) . "\x00";
            $codeBlocks[] = '<pre><code>' . $m[1] . '</code></pre>';
            return $placeholder;
        }, $text);

        // Step 3: inline code spans, same placeholder trick.
        $inlineCode = [];
        $text = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$inlineCode) {
            $placeholder = "\x00INLINECODE" . count($inlineCode) . "\x00";
            $inlineCode[] = '<code>' . $m[1] . '</code>';
            return $placeholder;
        }, $text);

        // Step 4: links — [text](url), http(s) only.
        $text = preg_replace_callback('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/', function ($m) {
            return '<a href="' . $m[2] . '" target="_blank" rel="nofollow noopener noreferrer">' . $m[1] . '</a>';
        }, $text);

        // Step 5: bold, italic (order matters — bold before italic so
        // **text** doesn't get eaten by the single-* italic rule first).
        $text = preg_replace('/\*\*([^\*\n]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*([^\*\n]+)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<!\w)_([^_\n]+)_(?!\w)/', '<em>$1</em>', $text);

        // Step 6: line-by-line block structure (headings, lists,
        // blockquotes, paragraphs).
        $lines = explode("\n", $text);
        $html = [];
        $inUl = false;
        $inOl = false;
        $inQuote = false;
        $paragraphBuffer = [];

        $flushParagraph = function () use (&$paragraphBuffer, &$html) {
            if (!empty($paragraphBuffer)) {
                $html[] = '<p>' . implode('<br>', $paragraphBuffer) . '</p>';
                $paragraphBuffer = [];
            }
        };

        foreach ($lines as $line) {
            // Headings: #, ##, ### only — capped at h4/h5/h6 so a post
            // heading can never outsize the page's own h2/h3 structure.
            if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
                $flushParagraph();
                if ($inUl) { $html[] = '</ul>'; $inUl = false; }
                if ($inOl) { $html[] = '</ol>'; $inOl = false; }
                if ($inQuote) { $html[] = '</blockquote>'; $inQuote = false; }
                $level = strlen($m[1]) + 3; // # -> h4, ## -> h5, ### -> h6
                $html[] = "<h{$level}>{$m[2]}</h{$level}>";
                continue;
            }

            // Unordered list item
            if (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
                $flushParagraph();
                if ($inOl) { $html[] = '</ol>'; $inOl = false; }
                if ($inQuote) { $html[] = '</blockquote>'; $inQuote = false; }
                if (!$inUl) { $html[] = '<ul>'; $inUl = true; }
                $html[] = "<li>{$m[1]}</li>";
                continue;
            }

            // Ordered list item
            if (preg_match('/^\d+\.\s+(.*)$/', $line, $m)) {
                $flushParagraph();
                if ($inUl) { $html[] = '</ul>'; $inUl = false; }
                if ($inQuote) { $html[] = '</blockquote>'; $inQuote = false; }
                if (!$inOl) { $html[] = '<ol>'; $inOl = true; }
                $html[] = "<li>{$m[1]}</li>";
                continue;
            }

            // Blockquote
            if (preg_match('/^&gt;\s?(.*)$/', $line, $m)) {
                $flushParagraph();
                if ($inUl) { $html[] = '</ul>'; $inUl = false; }
                if ($inOl) { $html[] = '</ol>'; $inOl = false; }
                if (!$inQuote) { $html[] = '<blockquote>'; $inQuote = true; }
                $html[] = "{$m[1]}<br>";
                continue;
            }

            // Blank line: close any open list/quote, flush paragraph.
            if (trim($line) === '') {
                $flushParagraph();
                if ($inUl) { $html[] = '</ul>'; $inUl = false; }
                if ($inOl) { $html[] = '</ol>'; $inOl = false; }
                if ($inQuote) { $html[] = '</blockquote>'; $inQuote = false; }
                continue;
            }

            // A code-block placeholder line stands alone.
            if (preg_match('/^\x00CODEBLOCK\d+\x00$/', trim($line))) {
                $flushParagraph();
                if ($inUl) { $html[] = '</ul>'; $inUl = false; }
                if ($inOl) { $html[] = '</ol>'; $inOl = false; }
                if ($inQuote) { $html[] = '</blockquote>'; $inQuote = false; }
                $html[] = trim($line);
                continue;
            }

            // Otherwise it's regular paragraph text.
            if ($inUl) { $html[] = '</ul>'; $inUl = false; }
            if ($inOl) { $html[] = '</ol>'; $inOl = false; }
            if ($inQuote) { $html[] = '</blockquote>'; $inQuote = false; }
            $paragraphBuffer[] = $line;
        }

        $flushParagraph();
        if ($inUl) $html[] = '</ul>';
        if ($inOl) $html[] = '</ol>';
        if ($inQuote) $html[] = '</blockquote>';

        $result = implode("\n", $html);

        // Step 7: swap the code-block/inline-code placeholders back in.
        foreach ($codeBlocks as $i => $block) {
            $result = str_replace("\x00CODEBLOCK{$i}\x00", $block, $result);
        }
        foreach ($inlineCode as $i => $code) {
            $result = str_replace("\x00INLINECODE{$i}\x00", $code, $result);
        }

        return $result;
    }
}
