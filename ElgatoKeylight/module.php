<?php

declare(strict_types=1);

/**
 * KeyLight
 *
 * IP-Symcon Modul für den Elgato Key Light.
 * Steuert An/Aus, Helligkeit und Farbtemperatur über die lokale REST-API.
 *
 * API-Endpunkt:  http://<hostname>:9123/elgato/lights
 * GET  → liefert aktuellen Zustand
 * PUT  → setzt neuen Zustand
 *
 * Farbtemperatur: Die API arbeitet intern in Mired (reciprocal megakelvin).
 * Die Variable speichert Kelvin (2900–7000 K) für eine lesbare Anzeige.
 * Umrechnung Kelvin ↔ Mired erfolgt beim Lesen/Schreiben der API.
 * Darstellung: VARIABLE_PRESENTATION_SLIDER mit USAGE_TYPE 1 (Tuneable White).
 * Helligkeit: VARIABLE_PRESENTATION_SLIDER mit USAGE_TYPE 2 (Intensity).
 */
class KeyLight extends IPSModule
{
    private const API_PATH    = '/elgato/lights';
    private const DEFAULT_PORT = 9123;

    // Kelvin-Grenzen laut Elgato-Spezifikation
    private const TEMP_MIN_K = 2900;
    private const TEMP_MAX_K = 7000;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Hostname', '');
        $this->RegisterPropertyInteger('Port', self::DEFAULT_PORT);
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        $this->RegisterAttributeInteger('LastBrightness', 50);

        $this->RegisterTimer('UpdateStatus', 0, 'ELGATOKEYLIGHT_UpdateStatus(' . $this->InstanceID . ');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $hostname = trim($this->ReadPropertyString('Hostname'));

        if ($hostname === '') {
            $this->SetTimerInterval('UpdateStatus', 0);
            $this->SetStatus(104); // Inaktiv: Hostname fehlt
            return;
        }

        $this->MaintainVariable('On', 'An/Aus', VARIABLETYPE_BOOLEAN, '~Switch', 1, true);
        $this->MaintainVariable('Brightness', 'Helligkeit', VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP_SIZE'    => 1,
            'SUFFIX'       => ' %',
            'USAGE_TYPE'   => 2, // Intensity
        ], 2, true);
        $this->MaintainVariable('ColorTemp', 'Farbtemperatur', VARIABLETYPE_INTEGER, [
            'PRESENTATION'  => VARIABLE_PRESENTATION_SLIDER,
            'MIN'           => self::TEMP_MIN_K,
            'MAX'           => self::TEMP_MAX_K,
            'STEP_SIZE'     => 100,
            'SUFFIX'        => ' K',
            'GRADIENT_TYPE' => 2, // Tuneable White
            'USAGE_TYPE'    => 1, // Tuneable White = Farbtemperatur
        ], 3, true);

        $this->EnableAction('On');
        $this->EnableAction('Brightness');
        $this->EnableAction('ColorTemp');

        $intervalMs = $this->ReadPropertyInteger('UpdateInterval') * 1000;
        $this->SetTimerInterval('UpdateStatus', $intervalMs);

        $this->SetStatus(102);

        // Initialer Abruf, aber erst wenn der Kernel bereit ist
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UpdateStatus();
        }
    }

    // -------------------------------------------------------------------------
    // Öffentliche Funktionen
    // -------------------------------------------------------------------------

    /**
     * Aktuellen Zustand vom Keylight abrufen und Variablen aktualisieren.
     * Wird auch per Timer aufgerufen.
     */
    public function UpdateStatus(): void
    {
        $json = @Sys_GetURLContent($this->BuildUrl());

        if ($json === false || $json === '') {
            $this->LogMessage('Elgato Keylight: Verbindung zu ' . $this->BuildUrl() . ' fehlgeschlagen.', KL_WARNING);
            $this->SetStatus(201);
            return;
        }

        $data = json_decode($json, true);
        if (!isset($data['lights'][0])) {
            $this->LogMessage('Elgato Keylight: Unerwartete API-Antwort: ' . $json, KL_WARNING);
            return;
        }

        $light = $data['lights'][0];

        $on         = (bool) ($light['on'] ?? 0);
        $brightness = (int) ($light['brightness'] ?? 0);

        // Attribut immer mit dem echten API-Wert aktualisieren (erkennt externe Änderungen)
        if ($brightness > 0) {
            $this->WriteAttributeInteger('LastBrightness', $brightness);
        }

        $this->SetValue('On', $on);
        $this->SetValue('Brightness', $on ? $brightness : 0);
        $this->SetValue('ColorTemp', $this->MiredToKelvin((int) ($light['temperature'] ?? 200)));

        $this->SetStatus(102);
    }

    /**
     * Schnittstelle für Variablen-Aktionen (Benutzer ändert Variable im WebFront).
     */
    public function RequestAction($ident, $value): void
    {
        switch ($ident) {
            case 'On':
                $on = (bool) $value;
                $this->SetValue('On', $on);
                if ($on) {
                    // Helligkeit aus letztem bekannten Wert wiederherstellen
                    $brightness = $this->ReadAttributeInteger('LastBrightness');
                    $this->SetValue('Brightness', max(1, $brightness));
                } else {
                    $this->SetValue('Brightness', 0);
                }
                break;
            case 'Brightness':
                $brightness = (int) $value;
                if ($brightness > 0) {
                    $this->WriteAttributeInteger('LastBrightness', $brightness);
                    // Lampe war aus → einschalten
                    if (!$this->GetValue('On')) {
                        $this->SetValue('On', true);
                    }
                } else {
                    // Slider auf 0 → Lampe ausschalten, LastBrightness behalten
                    $this->SetValue('On', false);
                }
                $this->SetValue('Brightness', $brightness);
                break;
            case 'ColorTemp':
                $this->SetValue('ColorTemp', (int) $value);
                break;
            default:
                return;
        }

        $this->SendLightState();
    }

    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    private function SendLightState(): void
    {
        if (!IPS_SemaphoreEnter('KeyLight_' . $this->InstanceID, 0)) {
            return;
        }

        try {
            $brightness = $this->GetValue('Brightness');
            // Wenn Anzeige 0 (Lampe aus), echten Helligkeitswert aus Attribut nehmen
            if ($brightness <= 0) {
                $brightness = $this->ReadAttributeInteger('LastBrightness');
                if ($brightness <= 0) {
                    $brightness = 50; // Fallback
                }
            }

            $payload = [
                'numberOfLights' => 1,
                'lights'         => [
                    [
                        'on'          => (int) $this->GetValue('On'),
                        'brightness'  => $brightness,
                        'temperature' => $this->KelvinToMired($this->GetValue('ColorTemp')),
                    ],
                ],
            ];

            $jsonPayload = json_encode($payload);

            $hostname = trim($this->ReadPropertyString('Hostname'));
            $port     = $this->ReadPropertyInteger('Port');

            $sock = @fsockopen($hostname, $port, $errno, $errstr, 5);
            if ($sock === false) {
                $this->LogMessage('Elgato Key Light: Verbindung fehlgeschlagen: ' . $errstr, KL_WARNING);
                return;
            }

            stream_set_timeout($sock, 5);

            $length  = strlen($jsonPayload);
            $request = "PUT /elgato/lights HTTP/1.1\r\n"
                     . "Host: {$hostname}:{$port}\r\n"
                     . "Content-Type: application/json\r\n"
                     . "Content-Length: {$length}\r\n"
                     . "Connection: close\r\n"
                     . "\r\n"
                     . $jsonPayload;

            fwrite($sock, $request);

            // Header lesen bis Leerzeile, Content-Length merken
            $contentLength = 0;
            while (!feof($sock)) {
                $line = fgets($sock);
                if ($line === false || rtrim($line) === '') {
                    break;
                }
                if (stripos($line, 'Content-Length:') === 0) {
                    $contentLength = (int) trim(substr($line, 15));
                }
            }

            // Genau Content-Length Bytes lesen, dann sofort schließen
            if ($contentLength > 0) {
                fread($sock, $contentLength);
            }
            fclose($sock);

        } finally {
            IPS_SemaphoreLeave('KeyLight_' . $this->InstanceID);
        }
    }

    private function BuildUrl(): string
    {
        $hostname = trim($this->ReadPropertyString('Hostname'));
        $port     = $this->ReadPropertyInteger('Port');
        return 'http://' . $hostname . ':' . $port . self::API_PATH;
    }

    /** Mired → Kelvin, gerundet auf 100 K, begrenzt auf den gültigen Bereich */
    private function MiredToKelvin(int $mired): int
    {
        if ($mired <= 0) {
            return self::TEMP_MIN_K;
        }
        $kelvin = (int) round(1_000_000 / $mired / 100) * 100;
        return max(self::TEMP_MIN_K, min(self::TEMP_MAX_K, $kelvin));
    }

    /** Kelvin → Mired, gerundet auf ganzzahligen Wert */
    private function KelvinToMired(int $kelvin): int
    {
        if ($kelvin <= 0) {
            $kelvin = self::TEMP_MIN_K;
        }
        return (int) round(1_000_000 / $kelvin);
    }

}

