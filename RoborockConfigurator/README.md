# Roborock Konfigurator

Listet alle Geräte des angemeldeten Roborock-Kontos und legt die [Roborock Saugroboter](../RoborockVacuum/README.md)-Instanzen an.

Modul-URL: `https://github.com/bgersmann/SymRoborock`

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Kompatibilität](#3-kompatibilität)
4. [Einrichtung](#4-einrichtung)
5. [Konfigurationsseite](#5-konfigurationsseite)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

- Zeigt alle Geräte des Kontos, auch mit dir geteilte Geräte
- Legt Instanzen für unterstützte Saugroboter an
- Zeigt auch Instanzen an der eigenen Roborock Cloud, deren Gerät nicht mehr im Konto ist

### 2. Voraussetzungen

- Symcon ab Version 9.1
- Eine angemeldete [Roborock Cloud](../RoborockCloud/README.md)

### 3. Kompatibilität

Geräte mit nicht unterstütztem Protokoll (`A01`, `B01`) werden angezeigt, können aber nicht erstellt werden.
Details stehen in der [Übersicht der Bibliothek](../README.md#3-kompatibilität).

### 4. Einrichtung

Beim Hinzufügen des Konfigurators legt Symcon die [Roborock Cloud](../RoborockCloud/README.md) und ihren Client Socket mit an.
Nach der Anmeldung in der Roborock Cloud erscheinen die Geräte im Konfigurator.

### 5. Konfigurationsseite

| Spalte | Beschreibung |
|---|---|
| Name | Name des Geräts in der Roborock-App |
| Modell | Produktname bzw. Modellkennung |
| Geräte-ID | DUID des Geräts |
| Online | Ob das Gerät laut Cloud online ist |
| Unterstützt | Ob das Gerät angelegt werden kann |

### 6. PHP-Befehlsreferenz

Der Konfigurator bietet keine PHP-Befehle.
