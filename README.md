# SymRoborock

Symcon-Bibliothek für Roborock-Saugroboter. Protokoll und Abläufe orientieren sich an der Home-Assistant-Integration
[roborock](https://github.com/home-assistant/core/tree/dev/homeassistant/components/roborock) bzw. der zugrunde liegenden
Bibliothek [python-roborock](https://github.com/Python-roborock/python-roborock).

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Module](#3-module)
4. [Einrichtung](#4-einrichtung)
5. [Funktionsweise](#5-funktionsweise)
6. [Lizenz](#6-lizenz)

### 1. Funktionsumfang

- Anmeldung am Roborock-Konto per E-Mail-Bestätigungscode über einen Einrichtungsassistenten (Symcon 9.1 Wizard)
- Konfigurator mit allen Geräten des Roborock-Kontos (auch geteilte Geräte)
- Steuerung: Start, Pause, Stopp, zur Station, Spotreinigung, Finden, Staubbehälter leeren, Mopp waschen
- Saugstärke, Wassermenge und Wischroute einstellen
- Raumreinigung (Räume der aktuellen Karte mit den Namen aus der App)
- Status, Akku, Fehler, Stationsfehler, Reinigungsdauer/-fläche, Verschleißteile, Statistik
- Kommunikation lokal im Netzwerk (TCP 58867, Protokolle `1.0` und `L01`), Cloud (MQTT) als Ersatz
- Statusänderungen werden per MQTT sofort übernommen

### 2. Voraussetzungen

- Symcon ab Version 9.1
- Roborock-Konto der Roborock-App (Mi-Home-Konten werden nicht unterstützt)
- Saugroboter mit Protokoll `1.0` (alle klassischen Roborock-Sauger: S5–S8, Q5/Q7/Q8, Q Revo, Saros …).
  Geräte mit Protokoll `A01` (Dyad, Zeo) und `B01` (Q7/Q10 Budget-Serie) werden im Konfigurator angezeigt, aber noch nicht unterstützt.

### 3. Module

| Modul | Typ | Beschreibung |
|---|---|---|
| [Roborock Cloud](RoborockCloud/README.md) | Splitter | Anmeldung, Cloud-API, MQTT-Verbindung, lokale Verbindung zu den Geräten |
| [Roborock Konfigurator](RoborockConfigurator/README.md) | Konfigurator | Listet die Geräte des Kontos und legt Instanzen an |
| [Roborock Saugroboter](RoborockVacuum/README.md) | Gerät | Status und Steuerung eines Saugroboters |

### 4. Einrichtung

1. Instanz **Roborock Konfigurator** hinzufügen. Symcon legt dabei die **Roborock Cloud** und einen **Client Socket** an.
2. In der Roborock Cloud öffnet sich der Anmelde-Assistent automatisch:
   E-Mail-Adresse eingeben, den per E-Mail erhaltenen Code eintragen, fertig.
3. Im Konfigurator die gewünschten Saugroboter erstellen.

### 5. Funktionsweise

```
Roborock Saugroboter ─┐
Roborock Konfigurator ─┴─ Roborock Cloud ── Client Socket (TLS) ── Roborock MQTT-Broker
                              │
                              ├── HTTPS: Anmeldung, Geräte- und Raumliste
                              └── TCP 58867: lokale Befehle an den Saugroboter
```

- Die Anmeldung erzeugt eine Sitzung (Token), die in der Cloud-Instanz gespeichert wird. Ein Passwort wird nicht gespeichert.
- Die Cloud-Instanz konfiguriert ihren Client Socket selbst mit dem MQTT-Broker des Kontos.
- Befehle gehen bevorzugt lokal an den Saugroboter. Die IP-Adresse wird automatisch über die Cloud ermittelt
  (oder in der Geräteinstanz fest eingetragen). Ist der Roboter lokal nicht erreichbar, wird die Cloud genutzt.

### 6. Lizenz

Diese Bibliothek steht unter der [Apache License 2.0](LICENSE).

Teile des Codes sind PHP-Portierungen folgender Projekte, die ebenfalls unter der Apache License 2.0 stehen:

- [python-roborock](https://github.com/Python-roborock/python-roborock): Anmeldung, Cloud-API, Protokoll, Verschlüsselung, Code-Tabellen
- [vacuum-map-parser-roborock](https://github.com/PiotrMachowski/Python-package-vacuum-map-parser-roborock) von Piotr Machowski: Auslesen der Karte

Die [Home-Assistant-Integration](https://github.com/home-assistant/core/tree/dev/homeassistant/components/roborock) diente als Referenz für das Verhalten.
Details stehen in der Datei [NOTICE](NOTICE).

Roborock ist eine Marke der Beijing Roborock Technology Co., Ltd. Dieses Projekt ist nicht mit Roborock verbunden.
