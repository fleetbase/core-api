<?php

namespace Fleetbase\Support;

use Illuminate\Support\Str;

class FleetbaseBlog
{
    /**
     * Parse blog posts from an RSS payload.
     */
    public static function parseRss(string $rssXml, int $limit, ?string $blogUrl = null): array
    {
        $limit = max(1, min($limit, 20));
        $posts = [];

        $previousLibxmlState = libxml_use_internal_errors(true);
        $rss                 = simplexml_load_string($rssXml);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);

        if (!$rss || !isset($rss->channel->item)) {
            return [];
        }

        foreach ($rss->channel->item as $item) {
            $publishedAt = self::getSimpleXmlText($item->pubDate);
            $timestamp   = $publishedAt ? strtotime($publishedAt) : false;

            $posts[] = [
                'title'           => self::getSimpleXmlText($item->title),
                'link'            => self::normalizeLink(self::getSimpleXmlText($item->link), $blogUrl),
                'guid'            => self::getSimpleXmlText($item->guid),
                'description'     => self::getSimpleXmlText($item->description),
                'pubDate'         => $publishedAt,
                'published_at'    => $timestamp ? gmdate('c', $timestamp) : null,
                'author'          => self::getSimpleXmlText($item->children('http://purl.org/dc/elements/1.1/')->creator),
                'media_content'   => self::getSimpleXmlAttribute($item, 'http://search.yahoo.com/mrss/', 'content', 'url'),
                'media_thumbnail' => self::getSimpleXmlAttribute($item, 'http://search.yahoo.com/mrss/', 'thumbnail', 'url'),
            ];

            if (count($posts) >= $limit) {
                break;
            }
        }

        return $posts;
    }

    /**
     * Rewrite Ghost publication links to the canonical Fleetbase.io blog URL.
     */
    public static function normalizeLink(?string $link, ?string $blogUrl = null): string
    {
        $link    = trim((string) $link);
        $blogUrl = self::getBlogUrl($blogUrl);

        if (!$link) {
            return $blogUrl;
        }

        $host = parse_url($link, PHP_URL_HOST);
        $path = trim((string) parse_url($link, PHP_URL_PATH), '/');

        if ($host && Str::contains($host, 'ghost.io') && $path) {
            return $blogUrl . '/' . $path;
        }

        return $link;
    }

    /**
     * Get the public Fleetbase blog RSS feed URL.
     */
    public static function getFeedUrl(?string $feedUrl = null): string
    {
        return rtrim($feedUrl ?: getenv('FLEETBASE_BLOG_FEED_URL') ?: 'https://fleetbase.ghost.io/rss/', '/') . '/';
    }

    /**
     * Get the canonical Fleetbase blog URL.
     */
    public static function getBlogUrl(?string $blogUrl = null): string
    {
        return rtrim($blogUrl ?: getenv('FLEETBASE_BLOG_URL') ?: 'https://www.fleetbase.io/blog', '/');
    }

    /**
     * Get trimmed text from a SimpleXML element.
     */
    protected static function getSimpleXmlText($node): string
    {
        return trim((string) $node);
    }

    /**
     * Get an attribute from a namespaced SimpleXML child.
     */
    protected static function getSimpleXmlAttribute($item, string $namespace, string $childName, string $attributeName): string
    {
        $children = $item->children($namespace);

        if (!isset($children->{$childName})) {
            return '';
        }

        $attributes = $children->{$childName}->attributes();

        return isset($attributes->{$attributeName}) ? (string) $attributes->{$attributeName} : '';
    }
}
