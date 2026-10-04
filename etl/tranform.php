<?php
/**
 * Transform – Verbindung Aufnahme (Jahr) und Sichtungen (Anzahl Falter).
 *
 * Datenfluss (siehe Skizze):
 *   1. Aufnahme-CSV:  aiD_KD (= observation_id) -> Jahr (yearBU)
 *   2. Sichtung-CSV:  aiD_KD (= observation_id) -> Ind (= count)
 *   3. Über die observation_id wird jede Sichtung einem Jahr zugeordnet
 *   4. Pro Jahr: Aufnahmen zählen, Sichtungen zählen, Ind summieren
 */

declare(strict_types=1);
// PHP soll bei Funktionsaufrufen nicht stillschweigend zwischen unpassenden
// skalaren Typen umwandeln. Aus "3" wird dadurch beispielsweise nicht
// automatisch die Ganzzahl 3, wenn eine Funktion ausdrücklich int erwartet =
// Strenger Modus: PHP meldet einen Fehler, wenn man z.B. Text statt Zahl übergibt


// Leer lassen (null) = alle Jahre. Für nur 2003: const TARGET_YEAR = 2003;
// Eine feste Einstellung. null = "alle Jahre". Wenn 2003 → nur 2003
const TARGET_YEAR = null;

$extracted = include __DIR__ . '/extract.php'; // Führt extract.php aus (im selben Ordner) und holt dessen Ergebnis
$rawRows = $extracted['data'];
// Aus dem Ergebnis nimmt man nur die Rohdaten-Zeilen
// (Aufnahmen UND Sichtungen, gemischt in einer Liste)

$audit = [
    'source_rows'              => count($rawRows),
    'observations_read'        => 0, // gültige Aufnahmen
    'invalid_year_rows'        => 0, // Aufnahmen ohne lesbares/ kaputten Jahr
    'duplicate_observations'   => 0, // gleiche aiD_KD mehrfach in Aufnahme
    'sightings_read'           => 0, // Sichtungen, die einem Jahr zugeordnet wurden
    'invalid_count_rows'       => 0, // Ind leer / keine Zahl / negativ
    'sightings_without_obs'    => 0, // aiD_KD aus Sichtung fehlt in Aufnahme
    'output_years'             => 0, // wie viele Jahre am Ende rauskommen
];

// ---------- Schritt 1: observation_id -> Jahr (das "Telefonbuch") ----------
$yearByObservation = [];
// Leeres "Telefonbuch": später steht da ID → Jahr drin

foreach ($rawRows as $row) {
// Gehe jede Zeile einzeln durch

    if ($row['source'] !== 'aufnahme') {
        continue;
    }
    // Ist die Zeile KEINE Aufnahme (also eine Sichtung)? → überspringen

    $id = trim($row['observation_id']);
    // ID holen, trim() schneidet Leerzeichen vorne/hinten weg

    $year = trim($row['year']);
    // Jahr holen, auch von Leerzeichen befreit

    if (!ctype_digit($year) || strlen($year) !== 4) {
        $audit['invalid_year_rows']++;
        continue;
    }
    // Besteht das Jahr nur aus Ziffern UND hat genau 4 Stellen?
    // Wenn nein: Strich auf der Liste, Zeile überspringen

    if (TARGET_YEAR !== null && (int) $year !== TARGET_YEAR) {
        continue;
    }
    // Wurde ein Wunschjahr eingestellt und ist es ein anderes? → überspringen

    if (isset($yearByObservation[$id])) {
        $audit['duplicate_observations']++;
        continue;
    }
    // Steht die ID schon im Telefonbuch? Dann ist es ein Duplikat → Strich + überspringen

    $yearByObservation[$id] = (int) $year;
    // Alles okay: ID mit dem Jahr (als Zahl) ins Telefonbuch eintragen

    $audit['observations_read']++;
    // Strich bei "gültige Aufnahmen"
}

// ---------- Schritt 2: "Rechnen" Aufnahmen zählen, Sichtungen dem Jahr zuordnen und summieren ----------
$stats = [];
// year => [observations, sightings, total]
// Hier landen die Ergebnisse pro Jahr

// Zuerst alle Aufnahmen pro Jahr zählen
foreach ($yearByObservation as $year) {
// Gehe alle Aufnahmen im Telefonbuch durch (man braucht nur das Jahr)

    $stats[$year] ??= ['observations' => 0, 'sightings' => 0, 'total' => 0];
    // Gibt es für dieses Jahr schon einen Eintrag? Wenn nicht, lege einen
    // mit Startwert 0 an. (??= heißt "nur wenn noch nicht vorhanden")

    $stats[$year]['observations']++;
    // Aufnahmen dieses Jahres +1
}

// Dann die Sichtungen
foreach ($rawRows as $row) {
// Nochmal alle Rohzeilen durchgehen, diesmal für die Sichtungen

    if ($row['source'] !== 'sichtung') {
        continue;
    }
    // Keine Sichtung? → überspringen

    $id = trim($row['observation_id']);
    // Zu welcher Aufnahme gehört die Sichtung?

    $count = trim($row['count']);
    // Wie viele Falter wurden gesehen? (Spalte "Ind")

    if (!ctype_digit($count)) {
        $audit['invalid_count_rows']++;
        continue;
    }
    // Ist die Anzahl keine ganze Zahl (leer, Text, "-3", "2.5")? → Strich + überspringen

    if (!isset($yearByObservation[$id])) {
        $audit['sightings_without_obs']++;
        continue;
    }
    // Gibt es die Aufnahme-ID nicht im Telefonbuch? Dann wissen wir das Jahr nicht
    // → Strich + überspringen

    $year = $yearByObservation[$id];
    // Im Telefonbuch nachschlagen: Welches Jahr hat diese Aufnahme?

    $stats[$year]['sightings']++;
    // Anzahl Sichtungen dieses Jahres +1

    $stats[$year]['total'] += (int) $count;
    // Gesehene Falter zur Jahressumme addieren

    $audit['sightings_read']++;
    // Strich bei "erfolgreich zugeordnet"
}

// ---------- Schritt 3: Zielform für die Datenbank ----------
ksort($stats);
// Nach Jahr sortieren (2003, 2004, ...), damit die Reihenfolge chronologisch ist

$data = [];
// Fertige Liste: eine Zeile pro Jahr, genau so, wie sie in die Datenbank kommt

foreach ($stats as $year => $s) {
// Jedes Jahr einzeln durchgehen, $s enthält observations / sightings / total

    $data[] = [
        'year'                => $year,
        'observations'        => $s['observations'],
        'sightings'           => $s['sightings'],
        'total_butterflies'   => $s['total'],
        'avg_per_observation' => round($s['total'] / $s['observations'], 2),
        // Falter pro Aufnahme, auf 2 Nachkommastellen gerundet
        // (observations ist nie 0, weil ein Jahr nur existiert, wenn es mind. 1 Aufnahme gibt)
    ];
}

$audit['output_years'] = count($data);
// Wie viele Jahre kommen am Ende raus?

// Eine mit include geladene Datei kann einen Wert zurückgeben.
// load.php bekommt so die fertigen Daten, die Regeln und das Audit-Protokoll.
return [
    'question' => 'Wie entwickelt sich die Anzahl Falter pro Jahr?',
    'rules'    => ['target_year' => TARGET_YEAR, 'join_key' => 'aiD_KD = observation_id'],
    'data'     => $data,
    'audit'    => $audit,
];
