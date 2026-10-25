# Roborock Cloud

Verbindung zum Roborock-Konto. Splitter für den [Roborock Konfigurator](../RoborockConfigurator/README.md) und die
[Roborock Saugroboter](../RoborockVacuum/README.md).

Modul-URL: `https://github.com/bgersmann/SymRoborock`

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Kompatibilität](#3-kompatibilität)
4. [Einrichtung](#4-einrichtung)
5. [Konfigurationsseite](#5-konfigurationsseite)
6. [Status](#6-status)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Hinweise](#8-hinweise)

### 1. Funktionsumfang

- Anmeldung per E-Mail-Bestätigungscode über einen Assistenten, ohne Passwort
- Liest Geräte, Räume und Routinen des Kontos aus der Roborock-Cloud
- MQTT-Verbindung zur Cloud für Befehle, Statusmeldungen und Karten
- Lokale Verbindung zu den Saugrobotern (TCP 58867), IP-Adressen werden automatisch ermittelt
- Erkennt eine Cloud-Verbindung, die nicht mehr antwortet, und baut sie neu auf

### 2. Voraussetzungen

- Symcon ab Version 9.1
- Roborock-Konto der Roborock-App (Mi-Home-Konten werden nicht unterstützt)
- Internetzugang (HTTPS, MQTT über TLS auf Port 8883)

### 3. Kompatibilität

Alle Konten der Roborock-App in den Regionen Europa, Amerika, China und Russland.
Welche Geräte unterstützt werden, steht in der [Übersicht der Bibliothek](../README.md#3-kompatibilität).

### 4. Einrichtung

Beim Anlegen fragt Symcon nach der Konfiguration des Client Socket. Dort sind alle Werte vorgegeben und gesperrt, der Dialog wird
einfach mit **OK** bestätigt. Der Server des Kontos ist erst nach der Anmeldung bekannt.

Solange kein Konto angemeldet ist, öffnet sich beim Öffnen der Konfiguration der Anmelde-Assistent:

1. **Roborock-Konto**: E-Mail-Adresse des Kontos der Roborock-App und Region (normalerweise *Automatisch*).
   Mit *Weiter* wird ein Bestätigungscode an die E-Mail-Adresse gesendet.
2. **Bestätigungscode**: Code aus der E-Mail eintragen. Mit *Weiter* erfolgt die Anmeldung.
3. **Fertig**: Übersicht über das Zuhause und die gefundenen Geräte. Über **Schnittstelle konfigurieren** den Client Socket öffnen
   und dort **Änderungen übernehmen** klicken. Host, Port und SSL des Roborock-Servers sind bereits eingetragen und gesperrt.

Dieser Schritt ist nur nach der ersten Anmeldung nötig oder wenn sich der Server des Kontos ändert. Solange der Client Socket nicht
passt, zeigt die Instanz den Status 203 und den Knopf **Schnittstelle konfigurieren**.

Über *Anmelden* kann der Assistent jederzeit erneut gestartet werden, z. B. nach Ablauf der Sitzung.

### 5. Konfigurationsseite

| Element | Beschreibung |
|---|---|
| Anmelden | Startet den Anmelde-Assistenten |
| Schnittstelle konfigurieren | Öffnet den Client Socket, wenn dessen Konfiguration nicht zum Konto passt |
| Geräte neu laden | Lädt Geräte und Räume erneut aus der Cloud |
| Abmelden | Löscht die Sitzung und trennt die Cloud-Verbindung |

### 6. Status

| Code | Bedeutung |
|---|---|
| 102 | Angemeldet |
| 104 | Nicht angemeldet |
| 201 | Sitzung abgelaufen, bitte erneut anmelden |
| 202 | Die MQTT-Verbindung wurde vom Server abgelehnt |
| 203 | Die Konfiguration der Schnittstelle (Client Socket) muss übernommen werden |

### 7. PHP-Befehlsreferenz

`string RRC_UpdateHomeData(int $InstanzID);`
Lädt Geräte und Räume neu aus der Cloud und liefert die Anzahl der Geräte als Text.

`void RRC_Logout(int $InstanzID);`
Meldet das Konto ab.

Die Funktionen mit dem Präfix `RRC_UI` werden vom Konfigurationsformular verwendet und sind nicht für eigene Skripte gedacht.

### 8. Hinweise

- Der Roborock-Server beendet MQTT-Verbindungen nach einigen Stunden sowie nachts. Der Client Socket meldet das als Warnung
  *Fehler beim Lesen: End of file* bzw. *Connection reset by peer* und verbindet sich nach etwa einer Minute selbst neu.
  Befehle laufen in dieser Zeit weiter über die lokale Verbindung.
