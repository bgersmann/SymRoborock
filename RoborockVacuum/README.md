# Roborock Saugroboter

Status und Steuerung eines Roborock-Saugroboters. Die Instanz wird normalerweise über den
[Roborock Konfigurator](../RoborockConfigurator/README.md) angelegt.

### Konfigurationsseite

| Eigenschaft | Beschreibung |
|---|---|
| Geräte-ID (DUID) | ID des Geräts im Roborock-Konto (wird vom Konfigurator gesetzt) |
| Verbindung | *Lokales Netzwerk, Cloud als Ersatz* (Standard), *Nur Cloud* oder *Nur lokales Netzwerk* |
| IP-Adresse | Feste IP-Adresse des Roboters. Leer = automatisch über die Cloud ermitteln |
| Aktualisierungsintervall | Abfrage des Status in Sekunden (Standard 30). Verschleißteile und Statistik alle 10 Minuten, Räume und Routinen stündlich |
| Karte anzeigen | Speichert die Karte als Bild (Medienobjekt *Karte*). Während der Reinigung bei jeder Aktualisierung, sonst alle 10 Minuten |

### Statusvariablen

| Ident | Typ | Beschreibung |
|---|---|---|
| Control | Integer (schaltbar) | Start, Pause, Stopp, Zur Station, Spotreinigung, Finden, Ausgewählte Räume reinigen, mit Station: Staubbehälter leeren, Mopp waschen |
| State | Integer | Status des Roboters (Reinigt, Lädt, …) |
| CurrentRoom | String | Raum, in dem sich der Roboter befindet (aus der Karte) |
| Battery | Integer | Akkustand in % |
| Error | Integer | Fehlercode |
| CleanMode | Integer (schaltbar) | Reinigungsmodus wie in der App: Saugen, Saugen und Wischen, Wischen, Benutzerdefiniert, SmartPlan (falls unterstützt) |
| FanPower | Integer (schaltbar) | Saugstärke |
| WaterBoxMode | Integer (schaltbar) | Wassermenge (nur Modelle mit Wischfunktion) |
| MopMode | Integer (schaltbar) | Wischroute (nur Modelle, die sie melden) |
| Map | Integer (schaltbar) | Aktive Karte (nur bei mehreren Karten, z. B. Etagen) |
| Room | Integer (schaltbar) | Einen Raum der aktuellen Karte sofort reinigen |
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

### PHP-Befehlsreferenz

`bool RRV_Update(int $InstanzID);`
Liest den Status sofort.

`string RRV_UpdateRooms(int $InstanzID);`
Liest die Räume der aktuellen Karte neu ein.

`void RRV_CleanRooms(int $InstanzID, string $SegmentIDs);`
Reinigt mehrere Räume mit der Anzahl Durchgänge der Variable *Durchgänge*, z. B. `RRV_CleanRooms(12345, '1,6');`.
Die Segment-IDs sind die Werte der Variable *Raum reinigen* und gelten nur für die aktive Karte.

`void RRV_CleanRoomsByName(int $InstanzID, string $Raeume);`
Reinigt Räume der aktiven Karte über ihren Namen, z. B. `RRV_CleanRoomsByName(12345, 'Flur,Wohnzimmer,Küche');`.
Unbekannte Namen führen zu einer Fehlermeldung mit den vorhandenen Räumen.

`bool RRV_UpdateMap(int $InstanzID);`
Liest die Karte sofort neu.

`string RRV_SendCommand(int $InstanzID, string $Methode, string $Parameter);`
Sendet einen beliebigen Befehl des Roborock-Protokolls und liefert das Ergebnis als JSON, z. B.
`RRV_SendCommand(12345, 'get_consumable', '[]');` oder `RRV_SendCommand(12345, 'change_sound_volume', '[50]');`.

Die Steuerung erfolgt sonst über `RequestAction` auf die schaltbaren Variablen, z. B.
`RequestAction(IPS_GetObjectIDByIdent('Control', 12345), 1);` startet die Reinigung.
