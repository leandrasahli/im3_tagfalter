<?php
/**
 * Extract – liest die vier CSV-Dateien.
 *
 * Diese Datei verändert noch nichts. Sie liest jede Zeile, ergänzt lediglich
 * den Semestercode aus dem Dateinamen und gibt alles als PHP-Array zurück.
 *
 * Datenfluss:
 *
 *   vier CSV-Dateien -> CSV-Zeilen -> ein gemeinsames PHP-Array
 *
 * transform.php lädt dieses Array anschliessend mit include.
 */

// Strikte Typprüfung gilt für Funktionsaufrufe in dieser Datei. Dadurch fallen
// unpassende Datentypen früh auf, statt unbemerkt umgewandelt zu werden.
declare(strict_types=1);

// Das assoziative Array verbindet den fachlichen Semestercode mit dem Pfad der
// zugehörigen Quelldatei. __DIR__ ist immer der Ordner dieser PHP-Datei. Die
// Pfade funktionieren deshalb unabhängig vom aktuellen Arbeitsverzeichnis.
$sources = [
    'aufnahme' => __DIR__ . '/../data/aufnahme_falter.csv',
    'sichtung' => __DIR__ . '/../data/sichtung_falter.csv',
];

// Hier werden die normalisierten Rohzeilen aller Dateien gesammelt. Das Array
// ist zunächst leer und wächst in der inneren while-Schleife Zeile für Zeile.
$rawRows = [];

// Pro Semester wird genau eine CSV-Datei geöffnet und vollständig gelesen.
// Bei foreach enthält $falter beispielsweise "aufnahme" und $path den Dateipfad.
foreach ($sources as $falter => $path) {
    // "rb" öffnet die Datei nur zum binären Lesen. Der Binärmodus verhindert,
    // dass Zeilenenden auf verschiedenen Betriebssystemen verändert werden.
    $handle = fopen($path, 'rb');

    // fopen() liefert false, wenn die Datei fehlt oder nicht lesbar ist. Der
    // ETL-Prozess wird dann bewusst abgebrochen: Ein unvollständiger Import
    // wäre schwieriger zu erkennen als eine klare Fehlermeldung.
    if ($handle === false) {
        // Fehlermeldung anzeigen
        throw new RuntimeException("Datei nicht gefunden: {$path}");
    }

    // Die erste Zeile enthält die Spaltennamen. Als Trennzeichen verwendet der
    // Export ein Semikolon; Felder können mit doppelten Anführungszeichen
    // umschlossen sein. Ein leerer Escape-Parameter vermeidet Sonderregeln für
    // Backslashes und entspricht dem verwendeten CSV-Format.
    $headers = fgetcsv($handle, 0, ';', '"', '');

    // Ohne Kopfzeile lassen sich die späteren Werte keiner Spalte zuordnen.
    // Vor dem Abbruch wird der offene Datei-Handle sauber geschlossen.
    if ($headers === false) {
        fclose($handle);
        // Fehlermeldung anzeigen
        throw new RuntimeException("Keine Kopfzeile in {$path}");
    }

    // Der genaue Name der ersten Spalte unterscheidet sich zwischen den
    // Exporten. Statt einen festen Namen vorauszusetzen, merken wir uns den
    // tatsächlich gelesenen Spaltennamen als Schlüssel für course_name.
    $courseColumn = $headers[0];

    // fgetcsv() liest jeweils die nächste Datenzeile als numerisches Array.
    // Die Schleife endet automatisch am Dateiende.
    while (($values = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        // Eine Zeile mit zu vielen oder zu wenigen Feldern kann nicht sicher
        // mit den Überschriften kombiniert werden und wird übersprungen.
        if (count($values) !== count($headers)) {
            continue;
        }

        // array_combine() verwendet die Überschriften als Schlüssel und die
        // gelesenen CSV-Felder als Werte. Aus zwei parallelen Listen entsteht
        // damit eine verständlich adressierbare Zeile.
        $row = array_combine($headers, $values);

        // Der Extract legt einen kleinen, für alle vier Dateien identischen
        // Datenvertrag fest. Deutsche Originalspalten werden dabei auf kurze,
        // im Code gut verwendbare Feldnamen abgebildet. Inhaltlich bereinigt
        // wird hier noch nichts; das ist Aufgabe von transform.php.
        // aufnahme_falter.csv
        if ($falter === 'aufnahme') {
            $rawRows[] = [
                'observation_id' => $row[$courseColumn],
                'site_id' => $row['aID_STAO'],
                'year' => $row['yearBu'],
                'date_1' => $row['Date_Bu1'],
                'date_2' => $row['Date_Bu2'],
                'date_3' => $row['Date_Bu3'],
                'date_4' => $row['Date_Bu4'],
                'date_5' => $row['Date_Bu5'],
                'date_6' => $row['Date_Bu6'],
                'date_7' => $row['Date_Bu7'],
                'canton' => $row['Kanton'],
                'region' => $row['BGR_6'],
                'transect_km' => $row['Transektlaenge'],
            ];
        }

        if ($falter === 'sichtung') {
            $rawRows[] = [
                'observation_id' => $row[$courseColumn],
                'species_id' => $row['aID_SP'],
                'extra_species' => $row['Zusatzart'],
                'count_total' => $row['Ind'],
                'count_1' => $row['Ind1'],
                'count_2' => $row['Ind2'],
                'count_3' => $row['Ind3'],
                'count_4' => $row['Ind4'],
                'count_5' => $row['Ind5'],
                'count_6' => $row['Ind6'],
                'count_7' => $row['Ind7'],
                'genus' => $row['Genus'],
                'species' => $row['Species'],
            ];
        }
    }

    // Jede erfolgreich gelesene Datei wird geschlossen, bevor die nächste
    // geöffnet wird. So bleiben keine Betriebssystem-Ressourcen belegt.
    fclose($handle);
}

echo count($rawRows) . " Zeilen eingelesen\n";
print_r($rawRows[0]);
print_r($rawRows[count($rawRows) - 1]);


// include kann den Rückgabewert einer Datei übernehmen. Neben den Rohdaten
// werden die verarbeiteten Semesterkürzel mitgeliefert; das erleichtert eine
// spätere Kontrolle, welche Quellen am Import beteiligt waren.
return [
    'sources' => array_keys($sources),
    'data' => $rawRows,
];