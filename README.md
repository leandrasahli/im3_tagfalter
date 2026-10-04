# Datestory Falter – ETL mit PHP und MySQL

## Worum geht es?
Wir untersuchen, wie sich die Anzahl gesichteter Falter über die Jahre entwickelt.
Die Daten stammen aus zwei CSV-Dateien, die wir mit einem ETL-Prozess
(Extract → Transform → Load) in eine MySQL-Datenbank bringen.

**Leitfrage:** Wie entwickelt sich die Anzahl Falter pro Jahr?

## Die Daten
| Datei | Wichtige Spalten | Bedeutung |
|---|---|---|
| `data/aufnahme_falter.csv` | `aiD_KD`, `yearBU` | Eine Aufnahme mit ihrem Jahr |
| `data/sichtung_falter.csv` | `aiD_KD`, `Ind` | Eine Sichtung: Anzahl Falter (`Ind`) |

Verbindung der beiden Dateien: `aiD_KD` (im Code `observation_id`) ist in beiden gleich.
Über diese ID wird jede Sichtung einem Jahr zugeordnet.

## Projektstruktur
```
config.php            Datenbank-Zugangsdaten (NICHT ins Git!)
data/
  aufnahme_falter.csv
  sichtung_falter.csv
etl/
  schema.sql          Tabellen anlegen (einmal in phpMyAdmin)
  extract.php         CSVs einlesen
  transform.php       Daten verbinden und pro Jahr berechnen
  load.php            Ergebnis in MySQL schreiben
  unload.php          Daten aus MySQL als JSON ausgeben (fürs Frontend)
```

## Ablauf (ETL)
1. **Extract** (`extract.php`): Liest beide CSVs und legt alle Zeilen in eine Liste.
   Jede Zeile bekommt ein Feld `source` (`aufnahme` oder `sichtung`).
2. **Transform** (`transform.php`):
   - Schritt 1: Aufbau Verknüpfungen `aiD_KD → Jahr` aus den Aufnahmen bauen
   - Schritt 2: Aufnahmen pro Jahr zählen, Sichtungen über das Telefonbuch dem Jahr zuordnen, `Ind` summieren
   - Schritt 3: Pro Jahr eine saubere Zeile erzeugen (Aufnahmen, Sichtungen, Summe Falter, Falter pro Aufnahme)
   - Ungültige oder nicht zuordenbare Zeilen werden im **Audit** gezählt
3. **Load** (`load.php`): Schreibt alles in einer Transaktion in die Datenbank
   (alte Daten löschen, neue einfügen, Audit speichern, `commit()`).


## Datenbank 


## Einrichtung (Hostpoint)

  
