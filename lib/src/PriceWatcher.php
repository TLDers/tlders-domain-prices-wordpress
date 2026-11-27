<?php

namespace TLDers\Sdk;

// Only loaded through autoload.php (WordPress, the TLDers script, or the tests).
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Notices when an extension's cheapest price drops and tells the people watching it.
 *
 * Run it from a daily (or twice daily) job. It keeps one snapshot of the last prices it saw. The first run only records
 * them, so turning it on never sends a burst of notifications. A drop goes to the topic "tld_<extension>", which the apps
 * subscribe to when someone saves that extension and allows alerts: the server never needs to know who they are.
 */
class PriceWatcher
{
    const STATE_KEY = 'watch:snapshot';
    /** The same extension is announced at most once in this many seconds. */
    const COOLDOWN = 20 * 3600;

    /** @var Client */
    private $client;
    /** @var Cache */
    private $state;
    /** @var callable function ($topic, $title, $body, array $data): array{0:bool,1:string} */
    private $notify;
    /** @var Currency */
    private $currency;
    /** @var float */
    private $minDropPct;
    /** @var int */
    private $maxPerRun;

    /**
     * @param callable $notify  sends one notification, returns [sent, message] (Fcm::sendToTopic fits)
     * @param array    $options minDropPct (default 5), maxPerRun (default 30)
     */
    public function __construct(Client $client, Cache $state, callable $notify, Currency $currency, array $options = [])
    {
        $this->client = $client;
        $this->state = $state;
        $this->notify = $notify;
        $this->currency = $currency;
        $this->minDropPct = isset($options['minDropPct']) ? max(0.5, (float) $options['minDropPct']) : 5.0;
        $this->maxPerRun = isset($options['maxPerRun']) ? max(1, (int) $options['maxPerRun']) : 30;
    }

    /**
     * @param string[]|null $tlds extensions to check; null = everything the TLDers key can see
     * @param int|null      $now  for tests
     * @return array{checked:int,seeded:int,drops:int,sent:int,failed:int,deferred:int,errors:string[]}
     */
    public function run(?array $tlds = null, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $report = ['checked' => 0, 'seeded' => 0, 'drops' => 0, 'sent' => 0, 'failed' => 0, 'deferred' => 0, 'errors' => []];
        $tlds = $tlds === null ? $this->client->tlds() : Client::normalizeList($tlds);

        $snapshot = $this->state->get(self::STATE_KEY, true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $next = $snapshot;
        $sent = 0;

        foreach ($tlds as $tld) {
            $best = $this->client->cheapest($tld);
            if ($best === null || $best['register'] === null) {
                continue;
            }
            $report['checked']++;
            $price = (float) $best['register'];
            $was = isset($snapshot[$tld]) ? $snapshot[$tld] : null;

            if ($was === null) {
                $next[$tld] = ['price' => $price, 'slug' => $best['slug'], 'sentAt' => 0];
                $report['seeded']++;
                continue;
            }

            $dropPct = $was['price'] > 0 ? (($was['price'] - $price) / $was['price']) * 100 : 0;
            $isDrop = $price < $was['price'] && $dropPct >= $this->minDropPct && ($was['price'] - $price) >= 0.01;
            if (!$isDrop) {
                // A rise or a small move just becomes the new baseline.
                $next[$tld] = ['price' => $price, 'slug' => $best['slug'], 'sentAt' => $was['sentAt']];
                continue;
            }

            $report['drops']++;
            if ($now - $was['sentAt'] < self::COOLDOWN) {
                $next[$tld] = ['price' => $price, 'slug' => $best['slug'], 'sentAt' => $was['sentAt']];
                continue;
            }
            if ($sent >= $this->maxPerRun) {
                $report['deferred']++; // snapshot left as it was, so the next run picks it up
                continue;
            }

            $title = '.' . $tld . ' is now ' . $this->currency->format($price);
            $body = sprintf(
                'Cheapest at %s, down %d%% from %s.',
                $best['name'],
                (int) round($dropPct),
                $this->currency->format($was['price'])
            );
            $result = call_user_func($this->notify, Fcm::tldTopic($tld), $title, $body, ['type' => 'price_drop', 'tld' => $tld]);
            if (is_array($result) && !empty($result[0])) {
                $sent++;
                $report['sent']++;
                $next[$tld] = ['price' => $price, 'slug' => $best['slug'], 'sentAt' => $now];
            } else {
                $report['failed']++;
                $report['errors'][] = '.' . $tld . ': ' . (is_array($result) && isset($result[1]) ? $result[1] : 'not sent');
                // snapshot left as it was: a failed send is retried on the next run
            }
        }

        $this->state->set(self::STATE_KEY, $next, 400 * 86400);
        return $report;
    }
}
