<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Support\Http;
use Fleetbase\Types\Country;
use Fleetbase\Types\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LookupController extends Controller
{
    /**
     * Query and search font awesome icons.
     *
     * @return \Illuminate\Http\Response
     */
    public function fontAwesomeIcons(Request $request)
    {
        $query  = $request->input('query');
        $id     = $request->input('id');
        $prefix = $request->input('prefix');
        $limit  = $request->input('limit');

        $content = file_get_contents('https://raw.githubusercontent.com/FortAwesome/Font-Awesome/master/metadata/icons.json');
        $json    = json_decode($content);
        $icons   = [];

        $count = 0;

        if ($query) {
            $query = strtolower($query);
        }

        foreach ($json as $icon => $value) {
            $searchTerms = [...$value->search->terms, strtolower($value->label)];

            if (
                $query && collect($searchTerms)->every(
                    function ($term) use ($query) {
                        return !Str::contains($term, $query);
                    }
                )
            ) {
                continue;
            }

            if ($limit && $count >= $limit) {
                break;
            }

            if ($id && $id !== $icon) {
                continue;
            }

            foreach ($value->styles as $style) {
                $iconPrefix = 'fa' . substr($style, 0, 1);

                if ($prefix && $prefix !== $iconPrefix) {
                    continue;
                }

                $icons[] = [
                    'prefix' => $iconPrefix,
                    'label'  => $value->label,
                    'id'     => $icon,
                ];
            }

            $count++;
        }

        return $icons;
    }

    /**
     * Request IP lookup on user client.
     *
     * @return \Illuminate\Http\Response
     */
    public function whois(Request $request)
    {
        try {
            $info = Http::lookupIp($request);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        return response()->json($info);
    }

    /**
     * Get all countries with search enabled.
     *
     * @return \Illuminate\Http\Response
     */
    public function currencies(Request $request)
    {
        $query = strtolower($request->input('query'));

        $currencies = Currency::filter(
            function ($currency) use ($query) {
                if ($query) {
                    return Str::contains(strtolower($currency->getCode()), $query) || Str::contains(strtolower($currency->getTitle()), $query);
                }

                return true;
            }
        );

        return response()->json($currencies);
    }

    /**
     * Get all countries with search enabled.
     *
     * @return \Illuminate\Http\Response
     */
    public function countries(Request $request)
    {
        $query   = strtolower($request->input('query', null));
        $simple  = $request->boolean('simple');
        $columns = $request->array('columns');
        $only    = array_map(fn ($s) => strtolower($s), $request->array('only'));
        $except  = array_map(fn ($s) => strtolower($s), $request->array('except'));

        $countries = Country::search($query);

        if ($columns) {
            $countries = $countries->map(
                function ($country) use ($columns) {
                    return $country->only($columns);
                }
            );
        }

        if ($simple) {
            $countries = $countries->map(
                function ($country) {
                    return $country->simple();
                }
            );
        }

        if ($only) {
            $countries = $countries->filter(function ($country) use ($only) {
                return in_array(strtolower(data_get($country, 'cca2')), $only);
            });
        }

        if ($except) {
            $countries = $countries->filter(function ($country) use ($except) {
                return !in_array(strtolower(data_get($country, 'cca2')), $except);
            });
        }

        return response()->json($countries->values());
    }

    /**
     * Lookup a country by it's country or currency code.
     *
     * @param string $code
     *
     * @return \Illuminate\Http\Response
     */
    public function country($code, Request $request)
    {
        $simple  = $request->boolean('simple', true);
        $country = Country::getByIso2($code);

        if ($simple && $country) {
            $country = $country->simple();
        }

        return response()->json($country);
    }

    /**
     * Pull the Fleetbase.io blog RSS feed with aggressive caching.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function fleetbaseBlog(Request $request)
    {
        $limit    = $request->integer('limit', 6);
        $cacheKey = "fleetbase_blog_posts_{$limit}";
        $cacheTTL = now()->addDays(4); // 4 days as requested

        // Try to get from cache
        $posts = Cache::remember($cacheKey, $cacheTTL, function () use ($limit) {
            return $this->fetchBlogPosts($limit);
        });

        // If cache failed and we have no posts, try to fetch directly
        if (empty($posts)) {
            Log::warning('[Blog] Cache miss and fetch failed, attempting direct fetch');
            $posts = $this->fetchBlogPosts($limit);
        }

        // Return cached response with HTTP cache headers
        return response()->json($posts)
            ->header('Cache-Control', 'public, max-age=345600') // 4 days in seconds
            ->header('X-Cache-Status', Cache::has($cacheKey) ? 'HIT' : 'MISS');
    }

    /**
     * Fetch blog posts from RSS feed.
     *
     * @param int $limit
     *
     * @return array
     */
    protected function fetchBlogPosts(int $limit): array
    {
        $rssUrl = 'https://www.fleetbase.io/post/rss.xml';
        $posts  = [];

        try {
            // Use HTTP client with timeout instead of simplexml_load_file
            $response = \Illuminate\Support\Facades\Http::timeout(5) // 5 second timeout
                ->retry(2, 100) // Retry twice with 100ms delay
                ->get($rssUrl);

            if (!$response->successful()) {
                Log::error('[Blog] Failed to fetch RSS feed', [
                    'status' => $response->status(),
                    'url'    => $rssUrl,
                ]);

                return [];
            }

            // Parse XML
            $rss = simplexml_load_string($response->body());

            if (!$rss || !isset($rss->channel->item)) {
                Log::error('[Blog] Invalid RSS feed structure');

                return [];
            }

            foreach ($rss->channel->item as $item) {
                $posts[] = [
                    'title'           => (string) $item->title,
                    'link'            => (string) $item->link,
                    'guid'            => (string) $item->guid,
                    'description'     => (string) $item->description,
                    'pubDate'         => (string) $item->pubDate,
                    'media_content'   => (string) data_get($item, 'media:content.url'),
                    'media_thumbnail' => (string) data_get($item, 'media:thumbnail.url'),
                ];

                // Early exit if we have enough
                if (count($posts) >= $limit) {
                    break;
                }
            }

            Log::info('[Blog] Successfully fetched blog posts', ['count' => count($posts)]);
        } catch (\Exception $e) {
            Log::error('[Blog] Exception fetching RSS feed', [
                'error' => $e->getMessage(),
                'url'   => $rssUrl,
            ]);
        }

        return array_slice($posts, 0, $limit);
    }

    /**
     * Manually refresh blog cache (can be called via webhook or admin panel).
     *
     * @return \Illuminate\Http\Response
     */
    public function refreshBlogCache()
    {
        // Clear all blog caches
        Cache::forget('fleetbase_blog_posts_6');
        Cache::forget('fleetbase_blog_posts_10');
        Cache::forget('fleetbase_blog_posts_20');

        // Warm up cache with default limit
        $posts = $this->fetchBlogPosts(6);
        Cache::put('fleetbase_blog_posts_6', $posts, now()->addDays(4));

        return response()->json([
            'status'      => 'success',
            'message'     => 'Blog cache refreshed',
            'posts_count' => count($posts),
        ]);
    }

    /**
     * Get a list of all timezones.
     *
     * @return \Illuminate\Http\Response
     */
    public function timezones()
    {
        $timezones = \DateTimeZone::listIdentifiers();

        return response()->json($timezones);
    }
}
