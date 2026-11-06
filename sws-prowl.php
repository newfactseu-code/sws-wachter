<?php
# SWS-Wächter: prüft den RSS-Feed des Polizeipräsidiums Schwaben Süd/West
# und schickt bei neuen oder geänderten Meldungen eine Prowl-Benachrichtigung
# "Neue Veröffentlichung SWS". Läuft per GitHub Actions alle 5 Minuten.
# Der Prowl-Key kommt aus dem GitHub-Secret PROWL_API_KEY.

// ---------- Einstellungen ----------
const FEED_URL      = 'https://www.polizei.bayern.de/rss/polizeiprasidium-schwaben-sud-west.xml';
define('PROWL_API_KEY', getenv('PROWL_API_KEY') ?: '');
const PROWL_APP     = 'Polizei Bayern';
const PROWL_EVENT   = 'Neue Veröffentlichung SWS';
const PROWL_PRIO    = 0;   // -2 (sehr niedrig) bis 2 (Notfall)
// Woran eine SWS-Meldung erkannt wird (Titel, Teaser oder Link):
const SWS_PATTERN   = '~\bSWS\b|Schwaben\s*S(ü|ue)d\s*/?\s*-?\s*West|schwaben[_-]?s(ue|ü)d[_-]?west|schwaben_sw~iu';
const FILTER_AKTIV  = false; // Feed enthält nur PP SWS – Filter nur für Sammel-Feeds nötig
const STATE_FILE   = __DIR__ . '/sws-prowl-state.json';
const MELDE_AUCH_AENDERUNGEN = true; // Nachträge zur Tagesmeldung ebenfalls melden
// -----------------------------------

function http_get(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'new-facts.eu RSS-Reader',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        throw new RuntimeException("Feed nicht abrufbar (HTTP $code)");
    }
    return $body;
}

function prowl(string $titel, string $link): void {
    $ch = curl_init('https://api.prowlapp.com/publicapi/add');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => http_build_query([
            'apikey'      => PROWL_API_KEY,
            'application' => PROWL_APP,
            'event'       => PROWL_EVENT,
            'description' => $titel,
            'url'         => $link,
            'priority'    => PROWL_PRIO,
        ]),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        throw new RuntimeException("Prowl-Fehler (HTTP $code): $res");
    }
}

/** Liest RSS 2.0 oder Atom und liefert [schluessel, titel, link, text]. */
function parse_feed(string $xml): array {
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($doc === false) throw new RuntimeException('Feed ist kein gültiges XML');

    $items = [];
    $entries = isset($doc->channel->item) ? $doc->channel->item : ($doc->entry ?? []);
    foreach ($entries as $e) {
        $titel = trim((string)$e->title);
        $link  = trim((string)$e->link);
        if ($link === '' && isset($e->link['href'])) $link = (string)$e->link['href'];
        $text  = trim(strip_tags((string)($e->description ?? $e->summary ?? '')));
        $id    = trim((string)($e->guid ?? $e->id ?? '')) ?: $link ?: $titel;
        $datum = trim((string)($e->pubDate ?? $e->updated ?? ''));
        // Mit Datum im Schlüssel lösen auch aktualisierte Meldungen (Nachträge) aus.
        $key   = MELDE_AUCH_AENDERUNGEN ? "$id|$datum|$titel" : $id;
        $items[] = compact('key', 'titel', 'link', 'text');
    }
    return $items;
}

try {
    if (PROWL_API_KEY === '') throw new RuntimeException('PROWL_API_KEY fehlt');
    if (getenv('SWS_TEST') === '1') {
        prowl('Testmeldung: Der SWS-Wächter läuft.', FEED_URL);
        echo "Testmeldung gesendet.\n";
        exit(0);
    }
    $state     = is_file(STATE_FILE) ? json_decode(file_get_contents(STATE_FILE), true) : null;
    $ersterLauf = !is_array($state);
    $gesehen   = $ersterLauf ? [] : $state;

    foreach (parse_feed(http_get(FEED_URL)) as $it) {
        if (FILTER_AKTIV && !preg_match(SWS_PATTERN, $it['titel'] . ' ' . $it['text'] . ' ' . $it['link'])) continue;
        if (isset($gesehen[$it['key']])) continue;

        if (!$ersterLauf) prowl($it['titel'], $it['link']);
        $gesehen[$it['key']] = time();
    }

    // Nur die letzten 30 Tage behalten
    $gesehen = array_filter($gesehen, fn($t) => $t > time() - 30 * 86400);
    file_put_contents(STATE_FILE, json_encode($gesehen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo $ersterLauf ? "Erster Lauf: Ist-Stand gespeichert.\n" : "OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
