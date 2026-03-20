# IP-Symcon Modul: Elgato Key Light

Dieses Modul bindet [Elgato Key Light](https://www.elgato.com/de/key-light) Lampen direkt in [IP-Symcon](https://www.symcon.de/) ein. Es kommuniziert über die lokale REST-API der Lampe – kein Cloud-Account, keine Elgato-App notwendig.

## Unterstützte Geräte

- Elgato Key Light
- Elgato Key Light Air
- Elgato Key Light Mini

## Funktionen

| Variable | Typ | Beschreibung |
|---|---|---|
| An/Aus | Boolean | Schaltet die Lampe ein oder aus |
| Helligkeit | Integer (0–100 %) | Regelt die Helligkeit |
| Farbtemperatur | Integer (2900–7000 K) | Regelt die Farbtemperatur (warm → kalt) |

Alle Variablen sind über WebFront und Aktionen steuerbar.

## Visualisierung

Die Instanz wird in der Kachel-Visualisierung automatisch als **Licht** dargestellt, wenn Helligkeit und Farbtemperatur als Variablen vorhanden sind. Die Variablen verwenden die neue Schieberegler-Darstellung (ab IP-Symcon 8.0) mit korrekter Verwendungszuweisung:

- **Helligkeit**: Verwendung = Intensität
- **Farbtemperatur**: Verwendung = Farbtemperatur, Gradient = Farbtemperatur (Kelvin)

## Voraussetzungen

- IP-Symcon ab Version 8.0
- Elgato Key Light im selben Netzwerk wie IP-Symcon
- Der Hostname oder die IP-Adresse der Lampe muss bekannt sein

## Installation

1. Im IP-Symcon Module Store oder per **Modulverwaltung → Git** folgende URL eintragen:
   ```
   https://github.com/Apollo4244/IpSymconElgatoKeylight
   ```
2. Instanz **Elgato Key Light** anlegen (unter *Geräte*)
3. Hostname oder IP-Adresse der Lampe eintragen (Port `9123` ist voreingestellt)
4. Speichern – Variablen werden automatisch angelegt

## Konfiguration

| Einstellung | Beschreibung | Standard |
|---|---|---|
| Hostname / IP | Adresse der Lampe | – |
| Port | TCP-Port der Lampe | `9123` |
| Aktualisierungsintervall | Polling-Intervall in Sekunden | `60` |

### Schaltflächen

| Schaltfläche | Beschreibung |
|---|---|
| Status aktualisieren | Liest den aktuellen Zustand der Lampe sofort aus |
| Lampe identifizieren | Lässt die Lampe kurz blinken – nützlich bei mehreren Geräten |
| Debug-Info anzeigen | Zeigt Geräteinformation (Produktname, Seriennummer, Firmware) und aktuellen Lichtstatus als Popup an |

## API-Details

Die Lampe stellt eine lokale HTTP-REST-API zur Verfügung:

```
GET  http://<hostname>:9123/elgato/lights   → aktuellen Zustand abrufen
PUT  http://<hostname>:9123/elgato/lights   → Zustand setzen
```

**Hinweis zur Farbtemperatur:** Die API arbeitet intern in Mired (reziproke Megakelvin). Das Modul rechnet automatisch zwischen Kelvin (Anzeige) und Mired (API) um.

Weitere Details zur API: [elgato-key-light-api auf GitHub](https://github.com/adamesch/elgato-key-light-api)

## Lizenz

MIT License – siehe [LICENSE](LICENSE)
