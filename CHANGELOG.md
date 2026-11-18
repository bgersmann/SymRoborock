# Changelog

## Version 1.2.1 (2026-11-18)

**Geändert:**

- Die Bibliothek heißt jetzt *Roborock* statt *SymRoborock* (Name in Module Control und im Module Store). Bibliotheks-ID,
  Module, Präfixe und bestehende Instanzen bleiben unverändert, es ist nichts anzupassen.

## Version 1.2 (2026-11-16)

### Kachel für die Kachel-Visualisierung

Der Roborock Saugroboter liefert jetzt eine eigene HTML-Kachel (HTML-SDK, `GetVisualizationTile`), im Design an die
Symcon-Kachelvisualisierung angelehnt. Änderungen werden per `UpdateVisualizationValue` live an offene Visualisierungen
geschickt, die Karte nur, wenn sie sich geändert hat.

**Neu:**

- **Kleine Kachel:** Status mit aktuellem Raum, Akku, gereinigte Fläche, Knöpfe Start/Pause, Station und Stopp. Wenn Platz ist,
  Filter und Sensoren in %.
- **Mittlere Kachel:** zusätzlich Dauer, Finden, Staubbehälter leeren und Mopp waschen (an der Station), Reinigungsprogramme als
  Knöpfe, alle Verschleißteile sowie der Status der Cloud- und der lokalen Verbindung.
- **Große Kachel:** zusätzlich die Karte. Jeder Raum hat in der Karte einen Knopf zum Aus- und Abwählen, darunter
  *n Räume reinigen* und *Auswahl aufheben*. Dazu Reinigungsmodus und Kartenwechsel (Etagen). Breite Kacheln zeigen die Karte
  links, hohe Kacheln oben.
- Auf dem Handy erscheint die Karte auch in schmalen, hohen Kacheln, oben über der Bedienung.
- Passt nicht alles in die Kachel, werden weniger wichtige Teile nacheinander ausgeblendet; Filter und Sensoren bleiben am längsten.
- Fehler des Roboters und der Station werden rot angezeigt.
- Die Raumauswahl der Kachel ist dieselbe wie die der Variablen *… auswählen*.
- Die Karte der Kachel wird ohne aufgemalte Raumnamen gezeichnet; das Medienobjekt *Karte* bleibt unverändert.

### Sicherheit und Robustheit

**Geändert:**

- Verbindungen zur Cloud nur noch per HTTPS und nur zu Servern unter `roborock.com`, auch bei Weiterleitungen. Eine vom Server
  gemeldete Adresse außerhalb davon wird nicht verwendet.
- Lokale Verbindungen nur noch zu privaten IP-Adressen (z. B. 192.168.x.x). Das gilt für die automatisch ermittelte Adresse und
  für die feste IP-Adresse in der Instanz; das Eingabefeld lässt nur private IPv4-Adressen zu.
- Der Kartenparser bricht bei fehlerhaften Blöcken ab statt endlos zu laufen und prüft die Bildgröße gegen die vorhandenen Daten.
  Die Karte wird höchstens auf 8 MB entpackt.
- MQTT-Pakete und der Empfangspuffer sind auf 2 MB begrenzt; unvollständige Pakete werden verworfen.
- Die lokalen Schlüssel der Roboter (`localKey`) werden nicht mehr an den Konfigurator weitergegeben.
- In der Debug-Ausgabe werden IP-Adresse, WLAN-Name (SSID), BSSID und MAC-Adresse unkenntlich gemacht.
- Die Zusammenfassung der Roborock Cloud zeigt die E-Mail-Adresse gekürzt (z. B. `b***@example.com`).

**Neu:**

- Status 202 im Roborock Saugroboter: Die eingetragene IP-Adresse ist keine Adresse des lokalen Netzwerks.
- README: Abschnitt *Datenschutz und Sicherheit*. Er beschreibt, dass die Sitzung im Klartext in der `settings.json` liegt,
  dass das Token bei Roborock auch nach dem Abmelden gültig bleibt (eine Schnittstelle zum Ungültigmachen ist nicht bekannt) und
  dass die MQTT-Verbindung auch bei *Nur lokales Netzwerk* bestehen bleibt.

**Hinweis zum Update:** Steht in einer Saugroboter-Instanz als IP-Adresse ein Hostname oder eine öffentliche Adresse, zeigt die
Instanz nach dem Update den Status 202. Dann die private IP-Adresse eintragen oder das Feld leeren (automatische Ermittlung).

## Version 1.1 (2026-10-25)

**Neu:**

- Lizenz: Die Bibliothek steht unter der Apache License 2.0 (`LICENSE`, `NOTICE`); Hinweise auf die portierten Teile aus
  python-roborock und vacuum-map-parser-roborock in den betroffenen Dateien.
- READMEs für jedes Modul mit Funktionsumfang, Voraussetzungen, Kompatibilität, Konfiguration und PHP-Befehlen.

**Geändert:**

- Store-Richtlinien: Die Roborock Cloud ändert ihren Client Socket nicht mehr selbst. Nach der Anmeldung wird die Schnittstelle
  über *Schnittstelle konfigurieren* geöffnet und dort *Änderungen übernehmen* geklickt (Status 203, solange das fehlt).
  Beim Anlegen sind Port, SSL und Zertifikatsprüfung bereits vorgegeben.

**Behoben:**

- Konfigurator und Saugroboter erschienen in *Instanz hinzufügen* doppelt.

## Version 1.0 (2026-10-13)

Erste Version mit Roborock Cloud (Anmeldung per E-Mail-Code im Assistenten, Cloud-API, MQTT), Roborock Konfigurator und
Roborock Saugroboter (Steuerung, Reinigungsmodus, Raumreinigung, Karte, Routinen, Reinigungsprogramme, lokale Verbindung
mit Cloud als Ersatz).
