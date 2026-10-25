# SymRoborock

Symcon-Bibliothek für Roborock-Saugroboter. Protokoll und Abläufe orientieren sich an der Home-Assistant-Integration
[roborock](https://github.com/home-assistant/core/tree/dev/homeassistant/components/roborock) bzw. der zugrunde liegenden
Bibliothek [python-roborock](https://github.com/Python-roborock/python-roborock).

Modul-URL: `https://github.com/bgersmann/SymRoborock`

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Kompatibilität](#3-kompatibilität)
4. [Module](#4-module)
5. [Einrichtung](#5-einrichtung)
6. [Funktionsweise](#6-funktionsweise)
7. [Lizenz](#7-lizenz)

### 1. Funktionsumfang

- Anmeldung am Roborock-Konto per E-Mail-Bestätigungscode über einen Einrichtungsassistenten
- Konfigurator mit allen Geräten des Roborock-Kontos (auch geteilte Geräte)
- Steuerung: Start, Pause, Stopp, zur Station, Spotreinigung, Finden, Staubbehälter leeren, Mopp waschen
- Reinigungsmodus (Saugen, Saugen und Wischen, Wischen, Benutzerdefiniert, SmartPlan), Saugstärke, Wassermenge, Wischroute
- Raumreinigung einzelner oder mehrerer Räume mit Durchgängen, Wechsel zwischen mehreren Karten (Etagen)
- Reinigungsprogramme: feste Abläufe aus Karte, Räumen und Einstellungen als Startknopf
- Routinen aus der Roborock-App starten
- Karte als Bild mit Räumen, Reinigungsweg, Sperrzonen, Station und Roboter
- Status, Akku, Fehler, Stationsfehler, aktueller Raum, Reinigungsdauer/-fläche, Verschleißteile, Statistik, Verbindungsstatus
- Kommunikation lokal im Netzwerk (TCP 58867), Cloud (MQTT) als Ersatz und für Statusmeldungen

### 2. Voraussetzungen

- Symcon ab Version 9.1
- Roborock-Konto der Roborock-App (Mi-Home-Konten werden nicht unterstützt)
- Internetzugang zur Roborock-Cloud (HTTPS und MQTT über TLS, Port 8883)
- Für die lokale Verbindung: Saugroboter im selben Netzwerk, TCP-Port 58867 erreichbar

### 3. Kompatibilität

- Unterstützt: Saugroboter mit Protokoll `1.0`, also die klassischen Roborock-Sauger wie S5–S8, Q5/Q7/Q8, Q Revo und Saros.
  Getestet mit dem Saros 10R.
- Lokale Verbindung mit den Protokollvarianten `1.0` und `L01` (neuere Firmware), wird automatisch erkannt.
- Nicht unterstützt: Geräte mit Protokoll `A01` (Dyad, Zeo) und `B01` (Q7/Q10-Budget-Serie). Sie werden im Konfigurator angezeigt,
  können aber nicht angelegt werden.

### 4. Module

| Modul | Typ | Beschreibung |
|---|---|---|
| [Roborock Cloud](RoborockCloud/README.md) | Splitter | Anmeldung, Cloud-API, MQTT-Verbindung, lokale Verbindung zu den Geräten |
| [Roborock Konfigurator](RoborockConfigurator/README.md) | Konfigurator | Listet die Geräte des Kontos und legt Instanzen an |
| [Roborock Saugroboter](RoborockVacuum/README.md) | Gerät | Status und Steuerung eines Saugroboters |

### 5. Einrichtung

1. Über den Module Store oder Module Control (URL siehe oben) die Bibliothek installieren.
2. Instanz **Roborock Konfigurator** hinzufügen. Symcon legt dabei die **Roborock Cloud** und einen **Client Socket** an.
3. In der Roborock Cloud öffnet sich der Anmelde-Assistent automatisch:
   E-Mail-Adresse eingeben, den per E-Mail erhaltenen Code eintragen.
4. Im letzten Schritt des Assistenten bzw. in der Roborock Cloud über **Schnittstelle konfigurieren** den Client Socket öffnen
   und dort **Änderungen übernehmen** klicken. Die Adresse des Roborock-Servers ist bereits eingetragen.
5. Im Konfigurator die gewünschten Saugroboter erstellen.

### 6. Funktionsweise

```
Roborock Saugroboter ─┐
Roborock Konfigurator ─┴─ Roborock Cloud ── Client Socket (TLS) ── Roborock MQTT-Broker
                              │
                              ├── HTTPS: Anmeldung, Geräte, Räume, Routinen
                              └── TCP 58867: lokale Befehle an den Saugroboter
```

- Die Anmeldung erzeugt eine Sitzung (Token), die in der Cloud-Instanz gespeichert wird. Ein Passwort wird nicht gespeichert.
- Die Adresse des MQTT-Brokers hängt vom Konto ab. Die Cloud-Instanz gibt sie dem Client Socket vor, übernommen wird sie vom
  Benutzer in der Konfiguration des Client Socket.
- Befehle gehen bevorzugt lokal an den Saugroboter. Die IP-Adresse wird automatisch über die Cloud ermittelt
  (oder in der Geräteinstanz fest eingetragen). Ist der Roboter lokal nicht erreichbar, wird die Cloud genutzt.
- Karten werden von Roborock nur über die Cloud ausgeliefert.

### 7. Lizenz

Diese Bibliothek steht unter der [Apache License 2.0](LICENSE).

Teile des Codes sind PHP-Portierungen folgender Projekte, die ebenfalls unter der Apache License 2.0 stehen:

- [python-roborock](https://github.com/Python-roborock/python-roborock): Anmeldung, Cloud-API, Protokoll, Verschlüsselung, Code-Tabellen
- [vacuum-map-parser-roborock](https://github.com/PiotrMachowski/Python-package-vacuum-map-parser-roborock) von Piotr Machowski: Auslesen der Karte

Die [Home-Assistant-Integration](https://github.com/home-assistant/core/tree/dev/homeassistant/components/roborock) diente als Referenz für das Verhalten.
Details stehen in der Datei [NOTICE](NOTICE).

Roborock ist eine Marke der Beijing Roborock Technology Co., Ltd. Dieses Projekt ist nicht mit Roborock verbunden.
