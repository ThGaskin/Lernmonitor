![lernmonitor logo](public/imgs/logo_full.svg)
# Lernmonitor

[![Tests](https://github.com/ThGaskin/Lernmonitor/actions/workflows/tests.yml/badge.svg)](https://github.com/ThGaskin/Lernmonitor/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B%20%7C%20MariaDB%2010.3%2B-4479a1)
[![License: PolyForm Strict 1.0.0](https://img.shields.io/badge/license-PolyForm%20Strict%201.0.0-blue)](LICENSE)

## Übersicht

Der Lernmonitor ist eine schulinterne Web-Anwendung zur Verwaltung von Klassen, Aufgaben und Noten. 
Schülerinnen und Schüler sehen ihre Aufgaben und ihren Fortschritt in einem Dashboard und können ihren Fortschritt eintragen.
Lehrkräfte verwalten Aufgabensets und bewerten Abgaben über eine Klassenmatrix, 
und Admins verwalten Klassen, Fächer, Nutzerkonten und Einstellungen. 

Die App ist in reinem PHP ohne Framework geschrieben und läuft auf jedem Standard-LAMP/LEMP-Server — 
auch auf einfachem Schul-Hosting.

## Aktuelle Version: Beta Release 1.0

Diese erste Version deckt den vollständigen Kernbetrieb ab:

- Login mit Rollen für Admin, Lehrkräfte, Schüler*innen und Eltern
- Schüler-Dashboard mit Aufgabenübersicht, Fortschritt und Notenprojektion
- Lehrkraft-Dashboard mit Klassenmatrix zur Aufgabenverwaltung und Bewertung
- Admin-Bereich: Klassen-, Fach- und Nutzerverwaltung, Datenimport und -export, Schuljahreswechsel, Backup/Restore
- Konfigurierbare Einstellungen (z.B. Notenskala, Aufgaben-Scope, Standardraum)

## Probleme melden

Bitte [ein Issue erstellen](https://github.com/ThGaskin/Lernmonitor/issues/new) mit einer möglichst genauen Beschreibung 
(Rolle, Seite, Schritte zur Reproduktion). Falls möglich sind Screenshots auch 
sehr hilfreich.

## Mitbauen
Wir bauen den Lernmonitor ständig aus und freuen uns über Mitwirkende. Zum Mitwirken, 
bitte folgendes beachten:
1. Bei den Maintainern anfragen, bevor größere Features begonnen werden
2. Einen Branch vom aktuellen `master` erstellen
3. Tests für neue oder geänderte Funktionalität erstellen (`tests/`)
4. Änderung im `CHANGELOG.md` eintragen
5. Pull Request stellen — Merge erst nach Freigabe durch die Maintainer

## Lokal ausführen

**Voraussetzungen:** PHP 8.1+, MySQL 5.7+ oder MariaDB 10.3+

1. Datenbank anlegen:
   ```bash
   bash sql/reset.sh
   ```
   (nutzt Zugangsdaten aus `.env`, falls vorhanden — sonst `root` ohne Passwort)
2. `.env.example` nach `.env` kopieren und Zugangsdaten eintragen
3. Server starten:
   ```bash
   php -S localhost:8080 router.php
   ```
4. Im Browser [http://localhost:8080](http://localhost:8080) öffnen

Für den produktiven Betrieb das Dokument-Root direkt auf `public/` zeigen lassen (Apache mit `mod_rewrite` oder Nginx — `.htaccess` leitet nur um, falls das Root stattdessen auf das Projektverzeichnis zeigt).

## Tests

Die Integrationstests in `tests/` bauen bei jedem Lauf automatisch eine eigene Testdatenbank (`student_database_test`) auf und starten einen eigenen PHP-Server dagegen — es muss nichts manuell vorbereitet werden, solange lokal ein MySQL-Server unter `127.0.0.1:3306` mit dem Nutzer `root` (ohne Passwort) erreichbar ist.

```bash
cd tests
pip install -r requirements.txt
pytest -v
```

Gegen einen bereits laufenden Server testen: `APP_URL=http://localhost:8080 pytest -v` (überspringt den automatischen Datenbank- und Server-Aufbau).

Diese Tests laufen automatisch bei jedem Push und jeder Pull Request über GitHub Actions (siehe `.github/workflows/tests.yml`).

## Lizenz

Lernmonitor steht unter der [PolyForm Strict License 1.0.0](LICENSE): freie Nutzung für jeden nicht-kommerziellen Zweck (u. a. für Schulen und andere Bildungseinrichtungen), aber keine Weiterverbreitung und keine Änderungen ohne ausdrückliche Erlaubnis der Maintainer. Für kommerzielle Nutzung oder Änderungswünsche bitte ein Issue erstellen oder die Maintainer direkt kontaktieren.
