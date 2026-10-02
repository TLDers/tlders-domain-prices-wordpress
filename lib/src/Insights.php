<?php

namespace TLDers\Sdk;

// Only loaded through autoload.php (WordPress, the TLDers script, or the tests).
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The extras the skins show around raw prices: how much the cheapest registrar
 * saves against the average, which first-year prices jump at renewal, each
 * offer's price relative to the range (for price bars), and a stable colour and
 * initials per registrar (for avatars, without loading anyone's logo).
 */
class Insights
{
    /** Renewal at least this many times the first-year price counts as a jump. */
    const RENEW_JUMP = 1.5;

    const SKINS = ['minimal', 'aurora', 'midnight', 'fresh', 'sunset'];

    /**
     * @param array $offers offers for one TLD (any order)
     * @return array{cheapest:?array,average:?float,savingPct:?int,count:int}
     */
    public static function summary(array $offers, $type = 'register')
    {
        $prices = [];
        $cheapest = null;
        foreach ($offers as $o) {
            if ($o[$type] === null) {
                continue;
            }
            $prices[] = $o[$type];
            if ($cheapest === null || $o[$type] < $cheapest[$type]) {
                $cheapest = $o;
            }
        }
        if (!$prices) {
            return ['cheapest' => null, 'average' => null, 'savingPct' => null, 'count' => 0];
        }
        $average = array_sum($prices) / count($prices);
        $saving = $average > 0 ? (int) round(100 * ($average - $cheapest[$type]) / $average) : 0;
        return [
            'cheapest' => $cheapest,
            'average' => round($average, 2),
            'savingPct' => count($prices) > 1 && $saving > 0 ? $saving : null,
            'count' => count($prices),
        ];
    }

    /** True when the renewal costs much more than the first year (a promo price). */
    public static function renewJump(array $offer)
    {
        return $offer['register'] !== null && $offer['renew'] !== null
            && $offer['register'] > 0 && $offer['renew'] >= $offer['register'] * self::RENEW_JUMP;
    }

    /**
     * Each offer's price as a 0–100 share of the most expensive one, for price bars.
     *
     * @return array<int,int> keyed like $offers
     */
    public static function bars(array $offers, $type = 'register')
    {
        $max = 0;
        foreach ($offers as $o) {
            $max = max($max, (float) $o[$type]);
        }
        $out = [];
        foreach ($offers as $i => $o) {
            $out[$i] = $max > 0 && $o[$type] !== null ? max(4, (int) round(100 * $o[$type] / $max)) : 0;
        }
        return $out;
    }

    /** "Name.com" → "N", "one.com" → "O", "Hosting Ireland" → "HI". */
    public static function initials($name)
    {
        $words = preg_split('/[\s\-]+/', trim(preg_replace('/\.[a-z]{2,}$/i', '', (string) $name)));
        $letters = '';
        foreach (array_slice(array_filter($words, 'strlen'), 0, 2) as $w) {
            $letters .= function_exists('mb_substr') ? mb_substr($w, 0, 1) : substr($w, 0, 1);
        }
        return strtoupper($letters !== '' ? $letters : '?');
    }

    /** A stable hue (0–359) per registrar slug, for its avatar colour. */
    public static function hue($slug)
    {
        return (int) (hexdec(substr(md5((string) $slug), 0, 6)) % 360);
    }

    public static function skin($skin)
    {
        return in_array($skin, self::SKINS, true) ? $skin : 'minimal';
    }
}
