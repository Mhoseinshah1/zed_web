<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Safe rendering of user/admin message text.
 *
 * The text is HTML-escaped first (so any embedded markup or <script> is shown
 * as inert text, never executed), and only then are bare URLs converted into
 * clickable links that open in a new tab with rel="noopener nofollow".
 */
class MessageFormatter
{
    public static function linkify(?string $text): HtmlString
    {
        $escaped = e((string) $text);

        // Match http/https URLs in the already-escaped text.
        $linked = preg_replace_callback(
            '#(https?://[^\s<]+)#i',
            function (array $m): string {
                $url = $m[1];
                // Trim trailing punctuation that is unlikely to be part of the URL.
                $trail = '';
                while ($url !== '' && in_array(substr($url, -1), ['.', ',', ')', ']', '!', '?', ':', ';'], true)) {
                    $trail = substr($url, -1) . $trail;
                    $url   = substr($url, 0, -1);
                }
                // $url is already HTML-escaped; safe to place in href/text.
                return '<a href="' . $url . '" target="_blank" rel="noopener nofollow" class="underline break-all">'
                    . $url . '</a>' . $trail;
            },
            $escaped,
        );

        // Preserve line breaks.
        $linked = nl2br($linked ?? $escaped, false);

        return new HtmlString($linked);
    }
}
