# IP-Symcon Module: Elgato Key Light

This module integrates [Elgato Key Light](https://www.elgato.com/en/key-light) lamps directly into [IP-Symcon](https://www.symcon.de/). It communicates via the lamp's local REST API – no cloud account, no Elgato app required.

## Supported Devices

- Elgato Key Light
- Elgato Key Light Air
- Elgato Key Light Mini

## Features

| Variable | Type | Description |
|---|---|---|
| On/Off | Boolean | Turns the lamp on or off |
| Brightness | Integer (0–100 %) | Controls the brightness |
| Color Temperature | Integer (2900–7000 K) | Controls the color temperature (warm → cool) |

All variables are controllable via WebFront and actions.

## Visualization

The instance is automatically displayed as a **light** in the tile visualization when brightness and color temperature variables are present. The variables use the new slider presentation (requires IP-Symcon 8.0) with correct usage assignment:

- **Brightness**: Usage = Intensity
- **Color Temperature**: Usage = Color Temperature, Gradient = Color Temperature (Kelvin)

## Requirements

- IP-Symcon version 8.0 or later
- Elgato Key Light on the same network as IP-Symcon
- The hostname or IP address of the lamp must be known

## Installation

1. In the IP-Symcon Module Store or via **Module Management → Git**, enter the following URL:
   ```
   https://github.com/Apollo4244/IpSymconElgatoKeylight
   ```
2. Create an **Elgato Key Light** instance (under *Devices*)
3. Enter the hostname or IP address of the lamp (port `9123` is preset)
4. Save – variables are created automatically

## Configuration

| Setting | Description | Default |
|---|---|---|
| Hostname / IP | Address of the lamp | – |
| Port | TCP port of the lamp | `9123` |
| Update Interval | Polling interval in seconds | `60` |

### Buttons

| Button | Description |
|---|---|
| Update Status | Reads the current state of the lamp immediately |
| Identify Lamp | Makes the lamp flash briefly – useful when multiple devices are present |
| Show Debug Info | Displays device information (product name, serial number, firmware) and current light status as a popup |

## API Details

The lamp provides a local HTTP REST API:

```
GET  http://<hostname>:9123/elgato/lights   → retrieve current state
PUT  http://<hostname>:9123/elgato/lights   → set new state
```

**Note on color temperature:** The API works internally in Mired (reciprocal megakelvin). The module automatically converts between Kelvin (display) and Mired (API).

Further API details: [elgato-key-light-api on GitHub](https://github.com/adamesch/elgato-key-light-api)

## License

MIT License – see [LICENSE](LICENSE)
