<?php
/**
 * Load – schreibt die Jahresstatistik der Falter mit PDO in MySQL.
 *
 * Die Tabellen legt diese Datei bei Bedarf selbst an. Sie muss einmal
 * über die eigene Domain aufgerufen werden.
 *
 * Diese Datei ist der letzte Schritt des ETL-Prozesses:
 *
 *   transform.php -> PHP-Arrays -> vorbereitete INSERTs -> MySQL
 *
 * Alle Schreiboperationen laufen in einer Transaktion. Entweder wird der
 * komplette neue Datenstand gespeichert oder bei einem Fehler gar nichts.
 */

// Aktiviert strikte Typprüfung für Funktionsaufrufe in dieser Datei.
declare(strict_types=1);

// Zeigt PHP-Fehler direkt im Browser an. Auf vielen Servern sind sie sonst
// ausgeschaltet und man sieht nur eine leere Seite. Nach der Entwicklung
// diese zwei Zeilen wieder entfernen.
ini_set('display_errors', '1');
error_reporting(E_ALL);

// load.php ist ein administratives Werkzeug und keine gestaltete Webseite.
// Die Fortschrittsmeldungen werden deshalb als gut lesbarer Klartext gesendet.
header('Content-Type: text/plain; charset=utf-8');

// Die Zugangsdaten liegen ausserhalb des ETL-Unterordners im Projektstamm.
// __DIR__ zeigt auf etl; /.. wechselt genau eine Ebene nach oben.
$configPath = __DIR__ . '/../config.php';

// Ohne Konfiguration ist keine Datenbankverbindung möglich. HTTP 503 bedeutet
// "Service Unavailable" und signalisiert einen Konfigurationsfehler, nicht
// einen Fehler in den Falterdaten.
if (!is_file($configPath)) {
    http_response_code(503);
    exit("config.php fehlt im Hauptordner des Kurs-Repositories.\n");
}

// require bricht im Gegensatz zu include ab, falls die benötigte Datei trotz
// der vorangehenden Prüfung nicht geladen werden kann. Danach stehen $dsn,
// $username, $password und $options zur Verfügung.
require $configPath;

// Diese Ausgabe dient als einfache Kontrolle, welche Konfigurationsdatei auf
// dem Server tatsächlich verwendet wird. Sie steht bewusst NACH der Prüfung:
// Nach einer Ausgabe lässt sich der HTTP-Statuscode nicht mehr setzen.
echo $configPath . "\n";

// PDO- und SQL-Fehler werden durch die Optionen aus config.php als Exceptions
// ausgelöst und gemeinsam im catch-Block behandelt.
try {
    // transform.php führt intern zuerst extract.php aus und gibt danach den
    // vollständigen Datenvertrag zurück. Load benötigt daraus zwei Teile:
    // die Jahreszeilen und das Audit-Protokoll. Der Aufruf steht bewusst
    // INNERHALB von try: Fehler beim Einlesen der CSVs (z.B. "Datei nicht
    // gefunden") werden so unten im catch-Block sichtbar gemeldet.
    $result = include __DIR__ . '/transform.php';
    $rows = $result['data'];
    $audit = $result['audit'];

    // Frühe Kontrollausgabe: Schon vor der Verbindung ist sichtbar, wie viele
    // bereinigte Jahreszeilen überhaupt geschrieben werden sollen.
    echo 'Der Transform liefert ' . count($rows) . " Jahre.\n\n";

    // PDO baut die Verbindung zum in $dsn beschriebenen MySQL-Server auf.
    $pdo = new PDO($dsn, $username, $password, $options);
    echo "Verbindung steht.\n\n";

    // Die Tabellen werden bei Bedarf automatisch angelegt. "IF NOT EXISTS"
    // bedeutet: Gibt es die Tabelle schon, passiert nichts. Damit ersetzt
    // dieser Block die separate Datei schema.sql.
    //
    // Wichtig: Das geschieht bewusst VOR beginTransaction(). In MySQL beendet
    // jeder CREATE TABLE eine laufende Transaktion automatisch (Implicit
    // Commit). Innerhalb der Transaktion wäre das Rollback also unzuverlässig.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS falter_stats (
            year                SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
            observations        INT UNSIGNED NOT NULL,
            sightings           INT UNSIGNED NOT NULL,
            total_butterflies   INT UNSIGNED NOT NULL,
            avg_per_observation DECIMAL(10,2) NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS etl_audit (
            metric VARCHAR(60) NOT NULL PRIMARY KEY,
            value  INT NOT NULL
        )'
    );

    // Ab hier bilden alle Änderungen eine Einheit. commit() speichert sie
    // endgültig; rollBack() nimmt sie bei einem Fehler vollständig zurück.
    $pdo->beginTransaction();

    // Die CSVs sind der vollständige Datenstand. Deshalb wird die Faktentabelle
    // bei jedem Lauf ersetzt und nicht Zeile für Zeile ergänzt.
    // DELETE bleibt innerhalb der Transaktion rückgängig zu machen. Das Audit
    // wird ebenfalls ersetzt, damit seine Zahlen genau zu den Jahren passen.
    $deletedRows = $pdo->exec('DELETE FROM falter_stats');
    $pdo->exec('DELETE FROM etl_audit');

    echo $deletedRows . " alte Jahreszeilen gelöscht.\n\n";

    // Das INSERT für die Faktentabelle wird genau einmal vorbereitet. In der
    // folgenden Schleife werden nur die Werte ausgetauscht. Das ist schneller
    // und sicherer als zusammengesetzte SQL-Strings.
    $insertYear = $pdo->prepare(
        'INSERT INTO falter_stats
            (year, observations, sightings, total_butterflies, avg_per_observation)
         VALUES
            (:year, :observations, :sightings, :total_butterflies, :avg_per_observation)'
    );

    // Jede transformierte Zeile wird zu genau einer Datenbankzeile.
    foreach ($rows as $row) {
        $insertYear->execute([
            // Alle Felder entsprechen bereits dem Datenbankschema und
            // werden deshalb ohne weitere Berechnung übernommen.
            'year' => $row['year'],
            'observations' => $row['observations'],
            'sightings' => $row['sightings'],
            'total_butterflies' => $row['total_butterflies'],
            'avg_per_observation' => $row['avg_per_observation'],
        ]);
    }

    // Auch die Kontrollzahlen des Transforms werden gespeichert. Unload kann
    // sie dadurch zusammen mit den Diagrammdaten ans Frontend ausliefern.
    $insertAudit = $pdo->prepare(
        'INSERT INTO etl_audit (metric, value) VALUES (:metric, :value)'
    );

    // Aus dem assoziativen Array wird eine Zeile pro Kennzahl, zum Beispiel
    // metric="invalid_count_rows" und value=12.
    foreach ($audit as $metric => $value) {
        $insertAudit->execute([
            'metric' => $metric,
            'value' => $value,
        ]);
    }

    // Erst jetzt werden alle seit beginTransaction() ausgeführten Änderungen
    // gemeinsam dauerhaft sichtbar.
    $pdo->commit();

    // Zusammenfassung für die Person, die load.php im Browser aufgerufen hat.
    echo count($rows) . " Jahre geschrieben.\n";
    echo count($audit) . " Prüfwerte geschrieben.\n\n";

    // Erste Plausibilitätskontrolle direkt aus der Datenbank: Die Anzahl muss
    // mit count($rows) übereinstimmen.
    $total = $pdo->query('SELECT COUNT(*) FROM falter_stats')->fetchColumn();
    echo "In falter_stats stehen jetzt {$total} Zeilen.\n\n";

    // Eine zweite Kontrolle liest den gespeicherten Datenstand pro Jahr
    // zurück, sortiert nach Jahr.
    $check = $pdo->query(
        'SELECT year, observations, sightings, total_butterflies
         FROM falter_stats
         ORDER BY year'
    );

    // fetchAll() liefert dank PDO::FETCH_ASSOC verständliche Spaltennamen.
    foreach ($check->fetchAll() as $stat) {
        echo $stat['year'] . ': '
            . $stat['observations'] . ' Aufnahmen, '
            . $stat['sightings'] . ' Sichtungen, '
            . $stat['total_butterflies'] . " Falter\n";
    }
} catch (Throwable $error) {
    // Schlägt nach beginTransaction() irgendein Schritt fehl, darf kein halber
    // Datenstand bleiben. inTransaction() verhindert einen ungültigen Rollback,
    // falls bereits der Verbindungsaufbau gescheitert ist.
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Der HTTP-Status macht den Fehlschlag auch für Werkzeuge sichtbar. Die
    // konkrete Exception wird hier ausgegeben, weil load.php ein bewusst
    // aufgerufenes Administrationswerkzeug und kein öffentlicher Endpunkt ist.
    http_response_code(500);
    exit('Load fehlgeschlagen: ' . $error->getMessage() . "\n");
}