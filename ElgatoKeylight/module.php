<?php

declare(strict_types=1);

/**
 * KeyLight
 *
 * IP-Symcon module for the Elgato Key Light.
 * Controls on/off, brightness and color temperature via the local REST API.
 *
 * API endpoint:  http://<hostname>:9123/elgato/lights
 * GET  → retrieves current state
 * PUT  → sets new state
 *
 * Color temperature: The API works internally in Mired (reciprocal megakelvin).
 * The variable stores Kelvin (2900-7000 K) for a readable display.
 * Conversion between Kelvin and Mired happens when reading/writing the API.
 * Presentation: VARIABLE_PRESENTATION_SLIDER with USAGE_TYPE 1 (Tuneable White).
 * Brightness: VARIABLE_PRESENTATION_SLIDER with USAGE_TYPE 2 (Intensity).
 */
class KeyLight extends IPSModule
{
    private const API_PATH    = '/elgato/lights';
    private const DEFAULT_PORT = 9123;

    // Kelvin limits per Elgato specification
    private const TEMP_MIN_K = 2900;
    private const TEMP_MAX_K = 7000;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Hostname', '');
        $this->RegisterPropertyInteger('Port', self::DEFAULT_PORT);
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        $this->RegisterAttributeInteger('LastBrightness', 50);
        $this->RegisterAttributeString('DeviceProductName', '');
        $this->RegisterAttributeString('DeviceDisplayName', '');

        $this->RegisterTimer('UpdateStatus', 0, 'ELGATOKEYLIGHT_UpdateStatus(' . $this->InstanceID . ');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $hostname = trim($this->ReadPropertyString('Hostname'));

        if ($hostname === '') {
            $this->SetTimerInterval('UpdateStatus', 0);
            $this->SetStatus(104); // Inactive: hostname missing
            return;
        }

        $this->MaintainVariable('On', $this->Translate('On/Off'), VARIABLETYPE_BOOLEAN, '~Switch', 1, true);
        $this->MaintainVariable('Brightness', $this->Translate('Brightness'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP_SIZE'    => 1,
            'SUFFIX'       => ' %',
            'USAGE_TYPE'   => 2, // Intensity
        ], 2, true);
        $this->MaintainVariable('ColorTemp', $this->Translate('Color Temperature'), VARIABLETYPE_INTEGER, [
            'PRESENTATION'  => VARIABLE_PRESENTATION_SLIDER,
            'MIN'           => self::TEMP_MIN_K,
            'MAX'           => self::TEMP_MAX_K,
            'STEP_SIZE'     => 100,
            'SUFFIX'        => ' K',
            'GRADIENT_TYPE' => 2, // Tuneable White
            'USAGE_TYPE'   => 1, // Tuneable White = color temperature
        ], 3, true);

        $this->EnableAction('On');
        $this->EnableAction('Brightness');
        $this->EnableAction('ColorTemp');

        $intervalMs = $this->ReadPropertyInteger('UpdateInterval') * 1000;
        $this->SetTimerInterval('UpdateStatus', $intervalMs);

        $this->SetStatus(102);

        // Initial fetch, but only once the kernel is ready
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UpdateStatus();
        }
    }

    // -------------------------------------------------------------------------
    // Public functions
    // -------------------------------------------------------------------------

    /**
     * Returns the configuration form populated with current device info from stored attributes.
     */
    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $product     = $this->ReadAttributeString('DeviceProductName');
        $displayName = $this->ReadAttributeString('DeviceDisplayName');

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'LabelProduct') {
                $element['caption'] = $this->Translate('Product:') . ' ' . ($product !== '' ? $product : '-');
            } elseif (($element['name'] ?? '') === 'LabelDisplayName') {
                $element['caption'] = $this->Translate('Display Name:') . ' ' . ($displayName !== '' ? $displayName : '-');
            }
        }

        return json_encode($form);
    }

    /**
     * Fetches the device name from the lamp, applies it as the IPS instance name,
     * and updates the product and display name labels in the configuration form.
     */
    public function ApplyDeviceName(): void
    {
        $hostname = trim($this->ReadPropertyString('Hostname'));
        $port     = $this->ReadPropertyInteger('Port');

        $infoJson = @Sys_GetURLContent('http://' . $hostname . ':' . $port . '/elgato/accessory-info');

        if ($infoJson === false || $infoJson === '') {
            $this->LogMessage('Elgato Key Light: Could not fetch accessory info from ' . $hostname . '.', KL_WARNING);
            echo $this->Translate('Error: Could not connect to lamp. Check hostname and port.');
            return;
        }

        $info = json_decode($infoJson, true);
        if (!is_array($info)) {
            echo $this->Translate('Error: Unexpected response from lamp.') . "\n" . $infoJson;
            return;
        }

        $productName = trim($info['productName'] ?? '');
        $displayName = trim($info['displayName'] ?? '');
        $name        = $displayName !== '' ? $displayName : $productName;

        if ($name === '') {
            echo $this->Translate('Error: No device name available.');
            return;
        }

        $this->WriteAttributeString('DeviceProductName', $productName);
        $this->WriteAttributeString('DeviceDisplayName', $displayName);

        $this->UpdateFormField('LabelProduct',     'caption', $this->Translate('Product:')      . ' ' . ($productName !== '' ? $productName : '-'));
        $this->UpdateFormField('LabelDisplayName', 'caption', $this->Translate('Display Name:') . ' ' . ($displayName !== '' ? $displayName : '-'));

        IPS_SetName($this->InstanceID, $name);

        echo sprintf($this->Translate('Instance renamed to: %s'), $name);
    }

    /**
     * Fetch current state from the Key Light and update variables.
     * Also called by the timer.
     */
    public function UpdateStatus(): void
    {
        $json = @Sys_GetURLContent($this->BuildUrl());

        if ($json === false || $json === '') {
            $this->LogMessage('Elgato Key Light: Connection to ' . $this->BuildUrl() . ' failed.', KL_WARNING);
            $this->SetStatus(201);
            echo $this->Translate('Error: Could not connect to lamp. Check hostname and port.');
            return;
        }

        $data = json_decode($json, true);
        if (!isset($data['lights'][0])) {
            $this->LogMessage('Elgato Key Light: Unexpected API response: ' . $json, KL_WARNING);
            echo $this->Translate('Error: Unexpected response from lamp.') . "\n" . $json;
            return;
        }

        $light = $data['lights'][0];

        $on         = (bool) ($light['on'] ?? 0);
        $brightness = (int) ($light['brightness'] ?? 0);

        // Always update the attribute with the real API value (detects external changes)
        if ($brightness > 0) {
            $this->WriteAttributeInteger('LastBrightness', $brightness);
        }

        $this->SetValue('On', $on);
        $this->SetValue('Brightness', $on ? $brightness : 0);
        $this->SetValue('ColorTemp', $this->MiredToKelvin((int) ($light['temperature'] ?? 200)));

        $this->SetStatus(102);
    }

    /**
     * Interface for variable actions (user changes a variable in WebFront).
     */
    public function RequestAction($ident, $value): void
    {
        switch ($ident) {
            case 'On':
                $on = (bool) $value;
                $this->SetValue('On', $on);
                if ($on) {
                    // Restore brightness from last known value
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
                    // Lamp was off → turn on
                    if (!$this->GetValue('On')) {
                        $this->SetValue('On', true);
                    }
                } else {
                    // Slider at 0 → turn lamp off, keep LastBrightness
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

    /**
     * Makes the lamp flash briefly for identification.
     */
    public function Identify(): void
    {
        $hostname = trim($this->ReadPropertyString('Hostname'));
        $port     = $this->ReadPropertyInteger('Port');

        $sock = @fsockopen($hostname, $port, $errno, $errstr, 5);
        if ($sock === false) {
            echo $this->Translate('Error: Could not connect to lamp.') . "\n" . $errstr;
            return;
        }

        stream_set_timeout($sock, 5);

        $request = "POST /elgato/identify HTTP/1.1\r\n"
                 . "Host: {$hostname}:{$port}\r\n"
                 . "Content-Length: 0\r\n"
                 . "Connection: close\r\n"
                 . "\r\n";

        fwrite($sock, $request);

        // Read status line
        $statusLine = fgets($sock);
        fclose($sock);

        if ($statusLine === false || strpos($statusLine, '200') === false) {
            echo $this->Translate('Error: Lamp did not respond as expected.') . "\n" . trim((string) $statusLine);
        }
    }

    /**
     * Displays device information and current light status as a popup.
     */
    public function ShowDebugInfo(): void
    {
        $infoJson  = @Sys_GetURLContent('http://' . trim($this->ReadPropertyString('Hostname')) . ':' . $this->ReadPropertyInteger('Port') . '/elgato/accessory-info');
        $lightsJson = @Sys_GetURLContent($this->BuildUrl());

        $info   = $infoJson  ? json_decode($infoJson, true)   : null;
        $lights = $lightsJson ? json_decode($lightsJson, true) : null;

        $lines = [];

        if ($info) {
            $lines[] = $this->Translate('=== Device Information ===');
            $lines[] = $this->Translate('Product:')          . ' ' . ($info['productName'] ?? '–');
            $lines[] = $this->Translate('Display Name:')     . ' ' . ($info['displayName'] !== '' ? $info['displayName'] : $this->Translate('(not set)'));
            $lines[] = $this->Translate('Serial Number:')    . ' ' . ($info['serialNumber'] ?? '–');
            $lines[] = $this->Translate('Firmware:')         . ' ' . ($info['firmwareVersion'] ?? '–') . ' (Build ' . ($info['firmwareBuildNumber'] ?? '–') . ')';
            $lines[] = $this->Translate('Hardware Type:')    . ' ' . ($info['hardwareBoardType'] ?? '–');
            $lines[] = $this->Translate('Features:')         . ' ' . implode(', ', $info['features'] ?? []);
        } else {
            $lines[] = $this->Translate('=== Device Information ===');
            $lines[] = $this->Translate('Error: No connection.');
        }

        $lines[] = '';

        if ($lights && isset($lights['lights'][0])) {
            $l = $lights['lights'][0];
            $lines[] = $this->Translate('=== Light Status ===');
            $lines[] = $this->Translate('On/Off:')             . ' ' . ($l['on'] ? $this->Translate('On') : $this->Translate('Off'));
            $lines[] = $this->Translate('Brightness:')         . ' ' . ($l['brightness'] ?? '–') . ' %';
            $lines[] = $this->Translate('Color Temperature:')  . ' ' . $this->MiredToKelvin((int) ($l['temperature'] ?? 0)) . ' K (' . ($l['temperature'] ?? '–') . ' Mired)';
        } else {
            $lines[] = $this->Translate('=== Light Status ===');
            $lines[] = $this->Translate('Error: No connection.');
        }

        echo implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function SendLightState(): void
    {
        if (!IPS_SemaphoreEnter('KeyLight_' . $this->InstanceID, 0)) {
            return;
        }

        try {
            $brightness = $this->GetValue('Brightness');
            // If display shows 0 (lamp off), use real brightness value from attribute
            if ($brightness <= 0) {
                $brightness = $this->ReadAttributeInteger('LastBrightness');
                if ($brightness <= 0) {
                    $brightness = 50; // fallback
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
                $this->LogMessage('Elgato Key Light: Connection failed: ' . $errstr, KL_WARNING);
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

            // Read headers until blank line, record Content-Length
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

            // Read exactly Content-Length bytes, then close immediately
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

    /** Mired → Kelvin, rounded to 100 K, clamped to the valid range */
    private function MiredToKelvin(int $mired): int
    {
        if ($mired <= 0) {
            return self::TEMP_MIN_K;
        }
        $kelvin = (int) round(1_000_000 / $mired / 100) * 100;
        return max(self::TEMP_MIN_K, min(self::TEMP_MAX_K, $kelvin));
    }

    /** Kelvin → Mired, rounded to an integer value */
    private function KelvinToMired(int $kelvin): int
    {
        if ($kelvin <= 0) {
            $kelvin = self::TEMP_MIN_K;
        }
        return (int) round(1_000_000 / $kelvin);
    }

}

