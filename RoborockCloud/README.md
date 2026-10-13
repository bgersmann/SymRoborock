# Roborock Cloud

Verbindung zum Roborock-Konto. Splitter für den [Roborock Konfigurator](../RoborockConfigurator/README.md) und die
[Roborock Saugroboter](../RoborockVacuum/README.md).

### Einrichtung

Solange kein Konto angemeldet ist, öffnet sich beim Öffnen der Konfiguration der Anmelde-Assistent:

1. **Roborock-Konto**: E-Mail-Adresse des Kontos der Roborock-App und Region (normalerweise *Automatisch*).
   Mit *Weiter* wird ein Bestätigungscode an die E-Mail-Adresse gesendet.
2. **Bestätigungscode**: Code aus der E-Mail eintragen. Mit *Weiter* erfolgt die Anmeldung.
3. **Fertig**: Übersicht über das Zuhause und die gefundenen Geräte.

Über *Anmelden* kann der Assistent jederzeit erneut gestartet werden (z. B. nach Ablauf der Sitzung).

### Konfigurationsseite

| Element | Beschreibung |
|---|---|
| Anmelden | Startet den Anmelde-Assistenten |
| Geräte neu laden | Lädt Geräte und Räume erneut aus der Cloud |
| Abmelden | Löscht die Sitzung und trennt die Cloud-Verbindung |

### Status

| Code | Bedeutung |
|---|---|
| 102 | Angemeldet |
| 104 | Nicht angemeldet |
| 201 | Sitzung abgelaufen, bitte erneut anmelden |
| 202 | Die MQTT-Verbindung wurde vom Broker abgelehnt |

### PHP-Befehlsreferenz

`string RRC_UpdateHomeData(int $InstanzID);`
Lädt Geräte und Räume neu aus der Cloud.

`void RRC_Logout(int $InstanzID);`
Meldet das Konto ab.
