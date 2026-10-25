# Roborock Saugroboter

Status und Steuerung eines Roborock-Saugroboters. Die Instanz wird normalerweise über den
[Roborock Konfigurator](../RoborockConfigurator/README.md) angelegt.

Modul-URL: `https://github.com/bgersmann/SymRoborock`

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Kompatibilität](#3-kompatibilität)
4. [Konfigurationsseite](#4-konfigurationsseite)
5. [Reinigungsprogramme](#5-reinigungsprogramme)
6. [Statusvariablen](#6-statusvariablen)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

- Steuerung: Start, Pause, Stopp, zur Station, Spotreinigung, Finden, Staubbehälter leeren, Mopp waschen
- Reinigungsmodus, Saugstärke, Wassermenge und Wischroute
- Raumreinigung einzelner oder mehrerer Räume mit Durchgängen, Wechsel zwischen mehreren Karten
- Reinigungsprogramme mit Startknopf und Routinen aus der Roborock-App
- Karte als Bild und aktueller Raum des Roboters
- Status, Fehler, Verschleißteile, Statistik, Verbindungsstatus

### 2. Voraussetzungen

- Symcon ab Version 9.1
- Eine angemeldete [Roborock Cloud](../RoborockCloud/README.md)

### 3. Kompatibilität

Saugroboter mit Protokoll `1.0` (S5–S8, Q5/Q7/Q8, Q Revo, Saros …), getestet mit dem Saros 10R.
Welche Variablen erscheinen, hängt vom Modell ab: Wassermenge, Wischroute, Reinigungsmodus und Stationsfunktionen
werden nur angelegt, wenn der Roboter sie meldet.

### 4. Konfigurationsseite

| Eigenschaft | Beschreibung |
|---|---|
| Geräte-ID (DUID) | ID des Geräts im Roborock-Konto (wird vom Konfigurator gesetzt) |
| Verbindung | *Lokales Netzwerk, Cloud als Ersatz* (Standard), *Nur Cloud* oder *Nur lokales Netzwerk*. Auch bei *Nur lokales Netzwerk* bleibt die MQTT-Verbindung der [Roborock Cloud](../RoborockCloud/README.md#8-hinweise) bestehen (Statusmeldungen, Karte) |
| IP-Adresse | Feste IP-Adresse des Roboters. Leer = automatisch über die Cloud ermitteln. Nur private Adressen des lokalen Netzwerks (z. B. 192.168.x.x), sonst Status 202 |
| Aktualisierungsintervall | Abfrage des Status in Sekunden (Standard 30). Verschleißteile und Statistik alle 10 Minuten, Räume und Routinen stündlich |
| Karte anzeigen | Speichert die Karte als Bild (Medienobjekt *Karte*). Während der Reinigung bei jeder Aktualisierung, danach noch einmal, sonst stündlich. Benötigt die Cloud-Verbindung |
| Reinigungsprogramme | siehe unten |

Im Aktionsbereich lassen sich Status, Räume und Karte sofort aktualisieren.

### 5. Reinigungsprogramme

In der Liste *Reinigungsprogramme* lassen sich feste Abläufe anlegen. Hinzufügen oder Bearbeiten öffnet einen Dialog:

| Feld | Beschreibung |
|---|---|
| Name | erscheint als Knopf in der Variable *Programm starten* |
| Karte | *Aktive Karte* oder eine feste Karte (z. B. Etage); bei Bedarf wird die Karte vor dem Start gewechselt. Unter der Karte erscheint für jeden ihrer Räume ein Häkchen |
| Räume in Reinigungsreihenfolge | wird über die Häkchen in der Reihenfolge der Klicks gefüllt und kann von Hand angepasst werden; leer = ganze Karte |
| Reinigungsmodus, Saugstärke, Wassermenge, Wischroute | werden vor dem Start gesetzt, *Unverändert* lässt die aktuelle Einstellung |
| Durchgänge | 1x–3x |

Unter der Liste stehen die Räume jeder Karte. Unbekannte Raumnamen werden dort angezeigt.
Mit *Ein Programmstart während einer Reinigung schickt den Roboter zur Station* wird jeder Programm-Knopf zum Start/Stopp-Knopf.

### 6. Statusvariablen

| Ident | Typ | Beschreibung |
|---|---|---|
| Control | Integer (schaltbar) | Start, Pause, Stopp, Zur Station, Spotreinigung, Finden, Ausgewählte Räume reinigen, mit Station: Staubbehälter leeren, Mopp waschen |
| Program | Integer (schaltbar) | Reinigungsprogramm starten (nur wenn Programme angelegt sind) |
| State | Integer | Status des Roboters (Reinigt, Lädt, …) |
| CurrentRoom | String | Raum, in dem sich der Roboter befindet (aus der Karte) |
| CloudConnection | Integer | Cloud-Verbindung: Verbunden, Antwortet nicht, Getrennt |
| LocalConnection | Boolean | Lokale Verbindung zum Roboter erreichbar |
| Battery | Integer | Akkustand in % |
| Error | Integer | Fehlercode |
| CleanMode | Integer (schaltbar) | Reinigungsmodus: Saugen, Saugen und Wischen, Wischen, Benutzerdefiniert, SmartPlan (falls unterstützt) |
| FanPower | Integer (schaltbar) | Saugstärke |
| WaterBoxMode | Integer (schaltbar) | Wassermenge (nur Modelle mit Wischfunktion) |
| MopMode | Integer (schaltbar) | Wischroute (nur Modelle, die sie melden) |
| Map | Integer (schaltbar) | Aktive Karte (nur bei mehreren Karten, z. B. Etagen) |
| Room | Integer (schaltbar) | Einen Raum der aktiven Karte sofort reinigen |
| Passes | Integer (schaltbar) | Anzahl Durchgänge für die Raumreinigung |
| RoomSelect\<ID\> | Boolean (schaltbar) | Auswahl der Räume für *Ausgewählte Räume reinigen* |
| Routine | Integer (schaltbar) | Routine aus der Roborock-App starten (nur wenn Routinen angelegt sind) |
| MapImage | Medium | Karte mit Räumen, Reinigungsweg, Sperrzonen, Station und Roboter |
| CleanTime | Integer | Dauer der aktuellen/letzten Reinigung |
| CleanArea | Float | Fläche der aktuellen/letzten Reinigung in m² |
| DockError | Integer | Fehler der Station (nur mit Station) |
| MainBrush, SideBrush, Filter, Sensors | Integer | Restlebensdauer der Verschleißteile in % |
| TotalCleanTime, TotalCleanArea, TotalCleanCount | Float/Integer | Statistik |
| LastClean | Integer | Zeitpunkt der letzten Reinigung |

Die Auswahl für Saugstärke, Wassermenge und Wischroute enthält die üblichen Stufen und wird um die Werte ergänzt,
die der Roboter meldet.

### 7. PHP-Befehlsreferenz

Die Steuerung erfolgt über `RequestAction` auf die schaltbaren Variablen, z. B.
`RequestAction(IPS_GetObjectIDByIdent('Control', 12345), 1);` startet die Reinigung.

`void RRV_StartProgram(int $InstanzID, string $Name);`
Startet ein Reinigungsprogramm der Instanzkonfiguration, z. B. `RRV_StartProgram(12345, 'EG Saugen kurz');`.

`void RRV_CleanRoomsByName(int $InstanzID, string $Raeume);`
Reinigt Räume der aktiven Karte über ihren Namen, z. B. `RRV_CleanRoomsByName(12345, 'Flur,Wohnzimmer,Küche');`.
Unbekannte Namen führen zu einer Fehlermeldung mit den vorhandenen Räumen.

`void RRV_CleanRooms(int $InstanzID, string $SegmentIDs);`
Reinigt mehrere Räume mit der Anzahl Durchgänge der Variable *Durchgänge*, z. B. `RRV_CleanRooms(12345, '1,6');`.
Die Segment-IDs sind die Werte der Variable *Raum reinigen* und gelten nur für die aktive Karte.

`bool RRV_Update(int $InstanzID);`
Liest den Status sofort.

`string RRV_UpdateRooms(int $InstanzID);`
Liest die Karten und die Räume der aktiven Karte neu ein.

`bool RRV_UpdateMap(int $InstanzID);`
Liest die Karte sofort neu.

`string RRV_SendCommand(int $InstanzID, string $Methode, string $Parameter);`
Sendet einen beliebigen Befehl des Roborock-Protokolls und liefert das Ergebnis als JSON, z. B.
`RRV_SendCommand(12345, 'get_consumable', '[]');` oder `RRV_SendCommand(12345, 'change_sound_volume', '[50]');`.

Die Funktionen mit dem Präfix `RRV_UI` werden vom Konfigurationsformular verwendet und sind nicht für eigene Skripte gedacht.
