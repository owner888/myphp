<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * TMDB's search (api.themoviedb.org/3), as agc_player's TMDBUtil.tmdbInfo
 * looks a film or a show up: its first result's id for a name and a
 * year. It goes as Https::request does and throws what fails.
 *
 * @internal UI\MoviePlayerState
 */
final class Tmdb
{
    /** myphp's API key of TMDB (v3), reversed, as OpenSubtitles::KEY: an app built has it, where anyone can read it. */
    private const KEY = '8640e3e3d506bf132fbac85745bb090f';
    private readonly string $key;

    /** TMDB of API key $key, myphp's own (KEY) where null. */
    public function __construct(?string $key = null)
    {
        $this->key = $key ?? strrev(self::KEY);
    }

    /**
     * The id of the film (a show, $tv) named $name of year $year (its first
     * air date's for a show) TMDB finds first, null for none. A show of
     * none that year is looked up of any: the year of a season's file is
     * often its own.
     */
    public function find(string $name, ?int $year, bool $tv): ?int
    {
        $id = $this->first($name, $year, $tv);
        return $id === null && $tv && $year !== null ? $this->first($name, null, true) : $id;
    }

    private function first(string $name, ?int $year, bool $tv): ?int
    {
        $params = ['api_key' => $this->key, 'query' => $name] + ($year === null ? [] : [$tv ? 'first_air_date_year' : 'year' => $year]);
        $url = 'https://api.themoviedb.org/3/search/' . ($tv ? 'tv' : 'movie') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        [$status, $data] = Https::request('GET', $url, ['Accept' => 'application/json'], null, 'TMDB');
        $r = json_decode($data, true);
        if ($status !== 200 || !is_array($r)) {
            $why = is_array($r) ? (string)($r['status_message'] ?? '') : '';
            throw new RuntimeException("TMDB: HTTP $status" . ($why !== '' ? ": $why" : ''));
        }
        $id = $r['results'][0]['id'] ?? null;
        return is_int($id) ? $id : null;
    }
}
