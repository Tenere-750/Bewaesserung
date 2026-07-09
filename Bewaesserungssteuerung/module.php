<?php

declare(strict_types=1);

/**
 * Bewässerungssteuerung für IP-Symcon 9 (PHP 8.5) – KNX-Variante
 *
 * - Bis zu 12 physische Beregnungszonen (Standard: 7) mit Motorkugelhähnen,
 *   Verfahrzeit pro Zone einstellbar
 * - Pumpe und Motorkugelhähne werden über KNX-Geräteinstanzen geschaltet:
 *   KNX_WriteDPT1($InstanzID, $Wert) — die InstanzID verweist auf die per
 *   KNX-Konfigurator/ETS-Import angelegte "Schalten"-Geräteinstanz (DPT1)
 *   der jeweiligen Gruppenadresse.
 * - Zwei der physischen Zonen können zu einer logischen Zone „Rasen"
 *   zusammengelegt werden und im WebFront/in den Sequenzen als EIN Kreis
 *   geführt werden. Schema: Ventil 1 auf -> Verfahrzeit warten -> Pumpe an
 *   -> Ventil 2 auf -> Überlapp warten -> Ventil 1 zu -> Pumpe aus ->
 *   Verfahrzeit warten -> Ventil 2 zu (entspricht dem normalen
 *   Sequenz-Übergang, nur innerhalb einer logischen Zone). Läuft immer
 *   exklusiv, ohne dass gleichzeitig eine andere Zone geöffnet ist. Die
 *   Zyklenzähler bleiben pro physischem Kugelhahn getrennt.
 * - Optionaler Bodenfeuchtesensor je physischer Zone mit Schwellenwert:
 *   ist der Boden feucht genug, wird die Zone in der Automatik übersprungen
 *   (manuelle Bewässerung ignoriert den Sensor bewusst).
 * - Master-Schalter, manuelle Zonensteuerung, zwei Automatik-Sequenzen/Tag
 * - Einschalten:  Ventil auf -> Verfahrzeit warten -> Pumpe an
 * - Ausschalten:  Pumpe aus -> Verfahrzeit warten -> Ventil zu
 * - Sequenz-Übergang: nächstes Ventil auf -> Überlapp warten -> vorheriges Ventil zu
 * - Auch mit „Parallel"-Kopplung bleiben in der Sequenz max. 2 Ventile gleichzeitig offen
 * - Bewässerungsintervall pro logischer Zone und Sequenz (jeden Tag, jeden 2. Tag, ...)
 * - Pumpenlaufzeit (heute/gesamt) und Zyklenzähler pro Motorkugelhahn
 * - Mehrere manuell geöffnete Zonen laufen mit unabhängigen Bewässerungs-
 *   Timern (checkManualDeadlines): die Pumpe wird garantiert erst
 *   ausgeschaltet, nachdem/bevor die jeweils LETZTE offene Zone schließt,
 *   auch wenn mehrere Zonen zu unterschiedlichen Zeiten fertig werden.
 *
 * Die zeitlichen Abläufe werden nicht blockierend (kein IPS_Sleep) über eine
 * Timer-gesteuerte Schritt-Warteschlange (Queue) abgearbeitet.
 */
class Bewaesserungssteuerung extends IPSModule
{
    private const MAX_ZONES = 12;

    public function Create()
    {
        parent::Create();

        // ------------------------------------------------------------------
        // Eigenschaften (Konfiguration)
        // ------------------------------------------------------------------
        $this->RegisterPropertyInteger('PumpInstanceID', 0);
        $this->RegisterPropertyInteger('PressureSensorID', 0); // optional: vorhandene Variable mit Wasserdruck-Messwert
        $this->RegisterPropertyInteger('FlowSensorID', 0);     // optional: vorhandene Variable mit Durchfluss-Messwert

        $defaultZones = json_encode([
            ['Name' => 'Zone 1',       'ValveInstanceID' => 0, 'TravelTime' => 7, 'DefaultDuration' => 10, 'Lawn' => false, 'UseSensor' => false, 'SensorID' => 0, 'Threshold' => 60, 'Invert' => false],
            ['Name' => 'Zone 2',       'ValveInstanceID' => 0, 'TravelTime' => 7, 'DefaultDuration' => 10, 'Lawn' => false, 'UseSensor' => false, 'SensorID' => 0, 'Threshold' => 60, 'Invert' => false],
            ['Name' => 'Zone 3',       'ValveInstanceID' => 0, 'TravelTime' => 7, 'DefaultDuration' => 10, 'Lawn' => false, 'UseSensor' => false, 'SensorID' => 0, 'Threshold' => 60, 'Invert' => false],
            ['Name' => 'Zone 4',       'ValveInstanceID' => 0, 'TravelTime' => 7, 'DefaultDuration' => 10, 'Lawn' => false, 'UseSensor' => false, 'SensorID' => 0, 'Threshold' => 60, 'Invert' => false],
            ['Name' => 'Zone 5',       'ValveInstanceID' => 0, 'TravelTime' => 7, 'DefaultDuration' => 10, 'Lawn' => false, 'UseSensor' => false, 'SensorID' => 0, 'Threshold' => 60, 'Invert' => false],
            ['Name' => 'Rasen links',  'ValveInstanceID' => 0, 'TravelTime' => 7, 'DefaultDuration' => 10, 'Lawn' => true,  'UseSensor' => false, 'SensorID' => 0, 'Threshold' => 60, 'Invert' => false],
            ['Name' => 'Rasen rechts', 'ValveInstanceID' => 0, 'TravelTime' => 7, 'DefaultDuration' => 10, 'Lawn' => true,  'UseSensor' => false, 'SensorID' => 0, 'Threshold' => 60, 'Invert' => false],
        ]);
        $this->RegisterPropertyString('Zones', $defaultZones);
        $this->RegisterPropertyString('LawnName', 'Rasen');

        $this->RegisterPropertyString('Sequence1', '[]');   // [{Zone, Duration, Interval, Parallel, Active}]
        $this->RegisterPropertyString('Sequence2', '[]');
        $this->RegisterPropertyInteger('MaxParallel', 2);
        $this->RegisterPropertyInteger('OverlapTime', 10);

        // ------------------------------------------------------------------
        // Attribute (persistenter interner Zustand)
        // ------------------------------------------------------------------
        $this->RegisterAttributeInteger('PumpOnSince', 0);  // Unix-Zeit, seit wann die Pumpe läuft (0 = aus)
        $this->RegisterAttributeInteger('DayAccum', 0);     // Pumpenlaufzeit heute in Sekunden
        $this->RegisterAttributeInteger('TotalAccum', 0);   // Pumpenlaufzeit gesamt in Sekunden
        $this->RegisterAttributeString('DayDate', '');      // Datum, auf das sich DayAccum bezieht
        $this->RegisterAttributeString('LastRun', '{}');    // letzter Bewässerungstag je Sequenz/logischer Zone
        $this->RegisterAttributeString('Open', '[]');       // aktuell geöffnete logische Zonen (Indizes)
        $this->RegisterAttributeString('OpenMembers', '{}'); // je logischer Zone: aktuell offene Teilventile (für "Rasen")
        $this->RegisterAttributeString('ZoneRunSince', '{}');   // je logischer Zone: Unix-Zeit, seit wann sie aktiv bewässert (Pumpe an + Ventil offen)
        $this->RegisterAttributeString('ZoneDayAccum', '{}');   // je logischer Zone: Laufzeit heute in Sekunden
        $this->RegisterAttributeString('ZoneTotalAccum', '{}'); // je logischer Zone: Laufzeit gesamt in Sekunden
        $this->RegisterAttributeString('ZoneManualTarget', '{}'); // je logischer Zone: gewünschte manuelle Bewässerungsdauer in Sekunden (0/fehlt = kein Auto-Timer aktiv)

        // ------------------------------------------------------------------
        // Variablenprofile
        // ------------------------------------------------------------------
        if (!IPS_VariableProfileExists('BWS.Minutes')) {
            IPS_CreateVariableProfile('BWS.Minutes', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('BWS.Minutes', '', ' min');
            IPS_SetVariableProfileIcon('BWS.Minutes', 'Clock');
        }
        if (!IPS_VariableProfileExists('BWS.Hours')) {
            IPS_CreateVariableProfile('BWS.Hours', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('BWS.Hours', '', ' h');
            IPS_SetVariableProfileDigits('BWS.Hours', 2);
            IPS_SetVariableProfileIcon('BWS.Hours', 'Clock');
        }
        if (!IPS_VariableProfileExists('BWS.Cycles')) {
            IPS_CreateVariableProfile('BWS.Cycles', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('BWS.Cycles', '', ' Zyklen');
            IPS_SetVariableProfileIcon('BWS.Cycles', 'Repeat');
        }
        if (!IPS_VariableProfileExists('BWS.SeqControl')) {
            IPS_CreateVariableProfile('BWS.SeqControl', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('BWS.SeqControl', 'Execute');
            IPS_SetVariableProfileAssociation('BWS.SeqControl', 0, 'Stopp', '', 0xFF4040);
            IPS_SetVariableProfileAssociation('BWS.SeqControl', 1, 'Sequenz 1', '', 0x40A0FF);
            IPS_SetVariableProfileAssociation('BWS.SeqControl', 2, 'Sequenz 2', '', 0x4040FF);
        }
        if (!IPS_VariableProfileExists('BWS.Pressure')) {
            IPS_CreateVariableProfile('BWS.Pressure', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('BWS.Pressure', '', ' bar');
            IPS_SetVariableProfileDigits('BWS.Pressure', 2);
        }
        if (!IPS_VariableProfileExists('BWS.Flow')) {
            IPS_CreateVariableProfile('BWS.Flow', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('BWS.Flow', '', ' l/min');
            IPS_SetVariableProfileDigits('BWS.Flow', 1);
        }

        // ------------------------------------------------------------------
        // Timer
        // ------------------------------------------------------------------
        $this->RegisterTimer('Queue', 0, 'BWS_ProcessQueue($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Schedule', 0, 'BWS_CheckSchedule($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // ------------------------------------------------------------------
        // Statusvariablen
        // ------------------------------------------------------------------
        $newActive = @$this->GetIDForIdent('Active') === false;
        $this->RegisterVariableBoolean('Active', 'Master-Schalter', '~Switch', 10);
        $this->EnableAction('Active');
        if ($newActive) {
            $this->SetValue('Active', true);
        }

        $this->RegisterVariableInteger('SeqControl', 'Automatik-Sequenz', 'BWS.SeqControl', 20);
        $this->EnableAction('SeqControl');

        $newStatus = @$this->GetIDForIdent('Status') === false;
        $this->RegisterVariableString('Status', 'Status', '', 30);
        if ($newStatus) {
            $this->SetValue('Status', 'Bereit');
        }

        // Wasserdruck/Durchfluss: reine Anzeigevariablen, die optional den
        // Messwert einer bereits vorhandenen Sensor-Variable spiegeln (siehe
        // updateSensorDisplays()). Ohne konfigurierten Sensor bleiben sie bei 0.
        $this->RegisterVariableFloat('Pressure', 'Wasserdruck', 'BWS.Pressure', 32);
        $this->RegisterVariableFloat('Flow', 'Durchfluss', 'BWS.Flow', 34);

        $newST1 = @$this->GetIDForIdent('StartTime1') === false;
        $this->RegisterVariableInteger('StartTime1', 'Startzeit Sequenz 1 (morgens)', '~UnixTimestampTime', 40);
        $this->EnableAction('StartTime1');
        if ($newST1) {
            $this->SetValue('StartTime1', strtotime('06:00'));
        }

        $newA1 = @$this->GetIDForIdent('Auto1') === false;
        $this->RegisterVariableBoolean('Auto1', 'Automatik Sequenz 1', '~Switch', 45);
        $this->EnableAction('Auto1');
        if ($newA1) {
            $this->SetValue('Auto1', true);
        }

        $newST2 = @$this->GetIDForIdent('StartTime2') === false;
        $this->RegisterVariableInteger('StartTime2', 'Startzeit Sequenz 2 (abends)', '~UnixTimestampTime', 50);
        $this->EnableAction('StartTime2');
        if ($newST2) {
            $this->SetValue('StartTime2', strtotime('19:00'));
        }

        $newA2 = @$this->GetIDForIdent('Auto2') === false;
        $this->RegisterVariableBoolean('Auto2', 'Automatik Sequenz 2', '~Switch', 55);
        $this->EnableAction('Auto2');
        if ($newA2) {
            $this->SetValue('Auto2', true);
        }

        // ------------------------------------------------------------------
        // Statistik-Kategorie (separiert Laufzeiten/Zyklen von der Steuerung)
        // ------------------------------------------------------------------
        $statsCategoryID = $this->ensureCategory('StatsCategory', 'Statistik', 900);

        // ------------------------------------------------------------------
        // Zyklenzähler pro physischem Motorkugelhahn (-> Statistik)
        // ------------------------------------------------------------------
        $physical = $this->physicalZones();
        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            $used = isset($physical[$i]);
            $name = $used ? $physical[$i]['Name'] : '';
            $this->maintainStatVariable('CyclesP' . $i, $name . ' – Zyklen Kugelhahn', VARIABLETYPE_INTEGER, 'BWS.Cycles', 200 + $i, $used, $statsCategoryID);
        }

        // ------------------------------------------------------------------
        // Manuelle Schalter + Dauer pro logischer Zone (Steuerung, bleibt bei
        // der Instanz) sowie Laufzeitanzeige je Zone (-> Statistik)
        // ------------------------------------------------------------------
        $logical = $this->logicalZones();
        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            $used = isset($logical[$i]);
            $name = $used ? $logical[$i]['name'] : '';

            $this->MaintainVariable('ManualZ' . $i, $name, VARIABLETYPE_BOOLEAN, '~Switch', 100 + $i, $used);
            if ($used) {
                $this->EnableAction('ManualZ' . $i);
                // MaintainVariable aktualisiert den Namen bei bereits vorhandenen
                // Variablen nicht zuverlässig – daher hier explizit nachziehen,
                // damit eine Umbenennung der Zone im Konfigurator auch im
                // WebFront ankommt.
                IPS_SetName($this->GetIDForIdent('ManualZ' . $i), $name);
            }

            $newDur = $used && @$this->GetIDForIdent('ManualDurationZ' . $i) === false;
            $this->MaintainVariable('ManualDurationZ' . $i, $name . ' – manuelle Dauer', VARIABLETYPE_INTEGER, 'BWS.Minutes', 150 + $i, $used);
            if ($used) {
                $this->EnableAction('ManualDurationZ' . $i);
                IPS_SetName($this->GetIDForIdent('ManualDurationZ' . $i), $name . ' – manuelle Dauer');
                if ($newDur) {
                    $this->SetValue('ManualDurationZ' . $i, $logical[$i]['defaultDuration']);
                }
            }

            $this->maintainStatVariable('ZRunDay' . $i, $name . ' – Laufzeit heute', VARIABLETYPE_INTEGER, 'BWS.Minutes', 400 + $i, $used, $statsCategoryID);
            $this->maintainStatVariable('ZRunTotal' . $i, $name . ' – Laufzeit gesamt', VARIABLETYPE_FLOAT, 'BWS.Hours', 500 + $i, $used, $statsCategoryID);
        }

        // ------------------------------------------------------------------
        // Laufzeitanzeige (Pumpe gesamt) (-> Statistik)
        // ------------------------------------------------------------------
        $this->maintainStatVariable('RuntimeDay', 'Pumpenlaufzeit heute', VARIABLETYPE_INTEGER, 'BWS.Minutes', 300, true, $statsCategoryID);
        $this->maintainStatVariable('RuntimeTotal', 'Pumpenlaufzeit gesamt', VARIABLETYPE_FLOAT, 'BWS.Hours', 310, true, $statsCategoryID);

        // Zeitplan-Prüfung alle 10 Sekunden (auch Grundlage für die
        // Auto-Abschaltung manuell gestarteter Zonen, s. checkManualDeadlines)
        $this->SetTimerInterval('Schedule', 10000);
        $this->updateRuntimeDisplay();
        $this->updateAllZoneRuntimeDisplays();
        $this->updateSensorDisplays();
    }

    /**
     * Legt bei Bedarf eine Unterkategorie unterhalb der Instanz an (bzw.
     * benennt/positioniert eine bereits vorhandene neu) und gibt ihre
     * Objekt-ID zurück.
     */
    private function ensureCategory(string $ident, string $name, int $position): int
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === false) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        return $id;
    }

    /**
     * Legt eine Statistik-Variable DIREKT als Kind der Statistik-Kategorie an
     * (nicht der Instanz) bzw. pflegt sie. WICHTIG: Da diese Variablen nicht
     * direkte Kinder der Instanz sind, funktionieren $this->GetIDForIdent()
     * und $this->SetValue() für sie NICHT (die suchen nur direkte Kinder der
     * Instanz) – Zugriff erfolgt stattdessen über statVarID() plus die
     * globalen Funktionen SetValue($id, ...) / GetValue($id).
     */
    private function maintainStatVariable(string $ident, string $name, int $type, string $profile, int $position, bool $used, int $categoryID): void
    {
        if ($categoryID <= 0) {
            return;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $categoryID);

        if (!$used) {
            if ($id !== false) {
                IPS_DeleteVariable($id);
            }
            return;
        }

        if ($id === false) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $categoryID);
            IPS_SetIdent($id, $ident);
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        if ($profile !== '') {
            @IPS_SetVariableCustomProfile($id, $profile);
        }
    }

    /**
     * Löst die Objekt-ID einer Statistik-Variable auf (Kind der
     * Statistik-Kategorie, siehe maintainStatVariable()). Gibt 0 zurück,
     * wenn die Kategorie oder die Variable (noch) nicht existiert.
     */
    private function statVarID(string $ident): int
    {
        $catID = @$this->GetIDForIdent('StatsCategory');
        if ($catID === false) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $catID);
        return $id === false ? 0 : $id;
    }

    // ======================================================================
    // Aktionen aus dem WebFront
    // ======================================================================
    public function RequestAction($Ident, $Value)
    {
        switch (true) {
            case $Ident === 'Active':
                $this->SetValue('Active', (bool)$Value);
                if (!$Value) {
                    $this->StopAll();
                }
                break;

            case $Ident === 'SeqControl':
                if ((int)$Value === 0) {
                    $this->StopAll();
                } else {
                    $this->StartSequence((int)$Value);
                }
                break;

            case $Ident === 'StartTime1':
            case $Ident === 'StartTime2':
                $this->SetValue($Ident, (int)$Value);
                break;

            case $Ident === 'Auto1':
            case $Ident === 'Auto2':
                $this->SetValue($Ident, (bool)$Value);
                break;

            case str_starts_with($Ident, 'ManualZ'):
                $this->manualZone((int)substr($Ident, 7), (bool)$Value);
                break;

            case str_starts_with($Ident, 'ManualDurationZ'):
                $this->SetValue($Ident, max(1, (int)$Value));
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    // ======================================================================
    // Öffentliche Funktionen (BWS_*)
    // ======================================================================

    /**
     * Startet die Automatik-Sequenz 1 oder 2.
     * Es werden nur logische Zonen bewässert, die laut Intervall heute fällig
     * sind UND (falls ein Sensor konfiguriert ist) laut Bodenfeuchte auch
     * tatsächlich Wasser brauchen.
     */
    public function StartSequence(int $Sequence): void
    {
        if (!in_array($Sequence, [1, 2], true)) {
            return;
        }
        if (!$this->GetValue('Active')) {
            $this->SetValue('Status', 'Master-Schalter aus – Start abgebrochen');
            return;
        }

        $zones = $this->logicalZones();
        $rows = json_decode($this->ReadPropertyString('Sequence' . $Sequence), true) ?: [];
        $lastRun = json_decode($this->ReadAttributeString('LastRun'), true) ?: [];
        $today = strtotime(date('Y-m-d'));

        // Fällige Zonen in konfigurierter Reihenfolge ermitteln
        $due = [];
        $skippedMoist = [];
        foreach ($rows as $row) {
            if (empty($row['Active'])) {
                continue;
            }
            $idx = (int)($row['Zone'] ?? -1);
            if (!isset($zones[$idx])) {
                continue;
            }
            $interval = max(1, (int)($row['Interval'] ?? 1));
            $key = 's' . $Sequence . 'z' . $idx;
            $last = isset($lastRun[$key]) ? (int)strtotime((string)$lastRun[$key]) : 0;
            $daysSince = $last > 0 ? (int)round(($today - $last) / 86400) : PHP_INT_MAX;
            if ($daysSince < $interval) {
                continue;
            }
            if (!$this->zoneNeedsWater($zones[$idx])) {
                $skippedMoist[] = $zones[$idx]['name'];
                continue;
            }
            // Dauer: 0 in der Sequenz-Zeile = Standard-Dauer der Zone verwenden,
            // ein Wert > 0 überschreibt sie nur für diese eine Zeile.
            $rowDuration = (int)($row['Duration'] ?? 0);
            $effectiveDuration = $rowDuration > 0 ? $rowDuration : $zones[$idx]['defaultDuration'];
            $due[] = [
                'idx' => $idx,
                'dur' => max(1, $effectiveDuration) * 60,
                'par' => !empty($row['Parallel'])
            ];
        }

        if (count($due) === 0) {
            $msg = 'Sequenz ' . $Sequence . ': heute keine Zone fällig';
            if (count($skippedMoist) > 0) {
                $msg .= ' (Boden feucht genug: ' . implode(', ', $skippedMoist) . ')';
            }
            $this->SetValue('Status', $msg);
            $this->LogMessage($msg, KL_NOTIFY);
            return;
        }

        // Gruppen bilden: Zonen mit "Parallel"-Haken laufen zusammen mit der
        // vorherigen fälligen Zone. Gruppengröße ist hart auf MaxParallel
        // (höchstens 2) begrenzt. Zusammengelegte Zonen (z. B. "Rasen")
        // dürfen NIE mit einer anderen Zone gepaart werden – ihr eigener
        // interner Übergang benötigt bereits bis zu 2 gleichzeitig offene
        // Ventile, sie bilden daher immer eine eigene, in sich
        // abgeschlossene Gruppe.
        $groupLimit = min(2, max(1, $this->ReadPropertyInteger('MaxParallel')));
        $groups = [];
        foreach ($due as $z) {
            $g = count($groups) - 1;
            $isSeq = !empty($zones[$z['idx']]['sequential']);
            $prevGroupIsSeq = $g >= 0 && count($groups[$g]) === 1 && !empty($zones[$groups[$g][0]['idx']]['sequential']);
            if (!$isSeq && !$prevGroupIsSeq && $z['par'] && $g >= 0 && count($groups[$g]) < $groupLimit) {
                $groups[$g][] = $z;
            } else {
                $groups[] = [$z];
            }
        }

        $groupName = fn(array $g): string => implode(' + ', array_map(fn(array $z): string => $zones[$z['idx']]['name'], $g));
        $groupDur = fn(array $g): int => max(array_map(fn(array $z): int => $z['dur'], $g));
        $groupTravel = fn(array $g): int => max(array_map(fn(array $z): int => $this->travel($z['idx']), $g));

        $overlap = max(0, $this->ReadPropertyInteger('OverlapTime'));

        // Evtl. laufende manuelle Bewässerung geordnet beenden, dann Sequenz starten
        $steps = $this->shutdownSteps();

        $isLawnGroup = fn(array $g): bool => count($g) === 1 && !empty($zones[$g[0]['idx']]['sequential']);

        foreach ($groups as $k => $g) {
            if ($isLawnGroup($g)) {
                // Zusammengelegte Zone (z. B. "Rasen"): läuft in sich
                // abgeschlossen mit eigenem Überlapp-Übergang zwischen den
                // Teilventilen (siehe lawnChainSteps()). Falls davor noch
                // eine andere Gruppe offen ist, wird diese zuerst normal
                // beendet, da "Rasen" nicht mit einer anderen Zone
                // gemeinsam anlaufen darf.
                if ($k > 0) {
                    $prev = $groups[$k - 1];
                    $steps[] = ['cmd' => 'status', 'param' => 'Beende ' . $groupName($prev) . ' vor ' . $zones[$g[0]['idx']]['name']];
                    $steps[] = ['cmd' => 'pump_off', 'post' => $groupTravel($prev)];
                    foreach ($prev as $z) {
                        $steps[] = ['cmd' => 'valve_off', 'zone' => $z['idx']];
                    }
                }
                $steps[] = ['cmd' => 'mark', 'param' => 's' . $Sequence . 'z' . $g[0]['idx']];
                $steps = array_merge($steps, $this->lawnChainSteps($zones[$g[0]['idx']], $g[0]['idx'], $g[0]['dur']));
                continue;
            }

            $prevWasLawn = $k > 0 && $isLawnGroup($groups[$k - 1]);

            if ($k === 0 || $prevWasLawn) {
                // Frischer Start: nichts ist mehr offen (entweder ganz am Anfang,
                // oder weil die vorherige "Rasen"-Kette bereits alles geschlossen hat)
                $steps[] = ['cmd' => 'status', 'param' => 'Sequenz ' . $Sequence . ': öffne ' . $groupName($g)];
                foreach ($g as $j => $z) {
                    $steps[] = ['cmd' => 'mark', 'param' => 's' . $Sequence . 'z' . $z['idx']];
                    $steps[] = [
                        'cmd'  => 'valve_on',
                        'zone' => $z['idx'],
                        'post' => ($j === count($g) - 1) ? $groupTravel($g) : 0
                    ];
                }
                $steps[] = ['cmd' => 'pump_on'];
                $steps[] = ['cmd' => 'status', 'param' => 'Sequenz ' . $Sequence . ': bewässere ' . $groupName($g), 'post' => $groupDur($g)];
            } else {
                // Gruppenwechsel mit Überlapp, dabei nie mehr als 2 Ventile offen
                // und die Pumpe hat immer mindestens ein offenes Ventil:
                //   [2->x] erst Ventil A1 zu (A2 bleibt offen)
                //   B1 auf -> Überlapp warten -> letztes A-Ventil zu -> ggf. B2 auf
                $prev = $groups[$k - 1];
                if (count($prev) >= 2) {
                    $steps[] = ['cmd' => 'valve_off', 'zone' => $prev[0]['idx']];
                }
                $steps[] = ['cmd' => 'mark', 'param' => 's' . $Sequence . 'z' . $g[0]['idx']];
                $steps[] = ['cmd' => 'valve_on', 'zone' => $g[0]['idx'], 'post' => $overlap];
                $steps[] = ['cmd' => 'valve_off', 'zone' => $prev[count($prev) - 1]['idx']];
                if (count($g) >= 2) {
                    $steps[] = ['cmd' => 'mark', 'param' => 's' . $Sequence . 'z' . $g[1]['idx']];
                    $steps[] = ['cmd' => 'valve_on', 'zone' => $g[1]['idx']];
                }
                $steps[] = ['cmd' => 'status', 'param' => 'Sequenz ' . $Sequence . ': bewässere ' . $groupName($g), 'post' => max(0, $groupDur($g) - $overlap)];
            }
        }

        // Abschluss: Pumpe aus -> Verfahrzeit -> letzte Ventile zu
        // (falls die letzte Gruppe "Rasen" war, ist bereits alles geschlossen)
        $lastGroup = $groups[count($groups) - 1];
        $steps[] = ['cmd' => 'status', 'param' => 'Sequenz ' . $Sequence . ': beende'];
        if (!$isLawnGroup($lastGroup)) {
            $steps[] = ['cmd' => 'pump_off', 'post' => $groupTravel($lastGroup)];
            foreach ($lastGroup as $z) {
                $steps[] = ['cmd' => 'valve_off', 'zone' => $z['idx']];
            }
        }
        $steps[] = ['cmd' => 'seq_end'];

        $this->clearQueue();
        $this->WriteAttributeString('ZoneManualTarget', '{}');
        $this->SetValue('SeqControl', $Sequence);
        $note = count($skippedMoist) > 0 ? (' (übersprungen wegen Feuchte: ' . implode(', ', $skippedMoist) . ')') : '';
        $this->LogMessage('Sequenz ' . $Sequence . ' gestartet (' . count($due) . ' Zonen)' . $note, KL_NOTIFY);
        $this->enqueue($steps);
    }

    /**
     * Stoppt alles geordnet: Pumpe aus -> Verfahrzeit -> alle offenen Ventile zu.
     */
    public function StopAll(): void
    {
        $this->clearQueue();
        $this->WriteAttributeString('ZoneManualTarget', '{}');
        $this->SetValue('SeqControl', 0);
        $steps = $this->shutdownSteps();
        $steps[] = ['cmd' => 'status', 'param' => 'Bereit'];
        $this->enqueue($steps);
    }

    /**
     * Setzt Pumpenlaufzeiten, Zonenlaufzeiten und Zyklenzähler zurück.
     */
    public function ResetCounters(): void
    {
        $this->WriteAttributeInteger('DayAccum', 0);
        $this->WriteAttributeInteger('TotalAccum', 0);
        if ($this->ReadAttributeInteger('PumpOnSince') > 0) {
            $this->WriteAttributeInteger('PumpOnSince', time());
        }

        // Zonenlaufzeiten zurücksetzen; aktuell laufende Zonen zählen ab jetzt neu
        $zoneRunSinceAll = json_decode($this->ReadAttributeString('ZoneRunSince'), true) ?: [];
        foreach ($zoneRunSinceAll as $idxStr => $ts) {
            $zoneRunSinceAll[$idxStr] = time();
        }
        $this->WriteAttributeString('ZoneRunSince', json_encode($zoneRunSinceAll));
        $this->WriteAttributeString('ZoneDayAccum', '{}');
        $this->WriteAttributeString('ZoneTotalAccum', '{}');

        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            $id = $this->statVarID('CyclesP' . $i);
            if ($id > 0) {
                SetValue($id, 0);
            }
        }
        $this->updateRuntimeDisplay();
        $this->updateAllZoneRuntimeDisplays();
    }

    /**
     * Timer: prüft Startzeiten (alle 30 s), setzt Tageszähler um Mitternacht
     * zurück und aktualisiert die Laufzeitanzeigen (Pumpe + je Zone).
     */
    public function CheckSchedule(): void
    {
        // --- Tageswechsel -------------------------------------------------
        $today = date('Y-m-d');
        if ($this->ReadAttributeString('DayDate') !== $today) {
            $since = $this->ReadAttributeInteger('PumpOnSince');
            if ($since > 0) {
                // laufende Pumpe: bisherige Laufzeit in Gesamt übernehmen, Tag neu starten
                $delta = time() - $since;
                $this->WriteAttributeInteger('TotalAccum', $this->ReadAttributeInteger('TotalAccum') + $delta);
                $this->WriteAttributeInteger('PumpOnSince', time());
            }
            $this->WriteAttributeInteger('DayAccum', 0);

            // Gleiches für alle gerade aktiv bewässernden Zonen
            $zoneRunSinceAll = json_decode($this->ReadAttributeString('ZoneRunSince'), true) ?: [];
            foreach ($zoneRunSinceAll as $idxStr => $ts) {
                $this->addZoneTotalAccum((int)$idxStr, time() - (int)$ts);
                $zoneRunSinceAll[$idxStr] = time();
            }
            $this->WriteAttributeString('ZoneRunSince', json_encode($zoneRunSinceAll));
            $this->WriteAttributeString('ZoneDayAccum', '{}');

            $this->WriteAttributeString('DayDate', $today);
        }
        $this->updateRuntimeDisplay();
        $this->updateAllZoneRuntimeDisplays();
        $this->updateSensorDisplays();
        $this->checkManualDeadlines();

        // --- Automatikstart -----------------------------------------------
        if (!$this->GetValue('Active')) {
            return;
        }
        if ($this->GetValue('SeqControl') !== 0) {
            return; // Sequenz läuft bereits
        }

        $now = date('H:i');
        $started = json_decode($this->GetBuffer('Started'), true) ?: [];
        foreach ([1, 2] as $seq) {
            if (!$this->GetValue('Auto' . $seq)) {
                continue;
            }
            $ts = $this->GetValue('StartTime' . $seq);
            if ($ts <= 0 || date('H:i', $ts) !== $now) {
                continue;
            }
            $marker = $today . ' ' . $now;
            if (($started[$seq] ?? '') === $marker) {
                continue; // in dieser Minute bereits gestartet
            }
            $started[$seq] = $marker;
            $this->SetBuffer('Started', json_encode($started));
            $this->StartSequence($seq);
            break;
        }
    }

    /**
     * Timer: arbeitet die Schritt-Warteschlange ab (nicht blockierend).
     */
    public function ProcessQueue(): void
    {
        if (!IPS_SemaphoreEnter($this->sem(), 5000)) {
            return;
        }
        try {
            // Läuft gerade eine Wartezeit (Verfahrzeit / Bewässerungsdauer)?
            $wait = (int)$this->GetBuffer('WaitUntil');
            if ($wait > time()) {
                $this->SetTimerInterval('Queue', max(1, $wait - time()) * 1000);
                return;
            }

            $queue = json_decode($this->GetBuffer('Queue'), true) ?: [];
            while (count($queue) > 0) {
                $step = array_shift($queue);
                $extra = $this->executeStep($step);
                if (count($extra) > 0) {
                    $queue = array_merge($extra, $queue);
                }
                $this->SetBuffer('Queue', json_encode($queue));

                $post = (int)($step['post'] ?? 0);
                if ($post > 0) {
                    $this->SetBuffer('WaitUntil', (string)(time() + $post));
                    $this->SetTimerInterval('Queue', $post * 1000);
                    return;
                }
            }
            $this->SetBuffer('WaitUntil', '0');
            $this->SetTimerInterval('Queue', 0);
        } finally {
            IPS_SemaphoreLeave($this->sem());
        }
    }

    // ======================================================================
    // Manuelle Zonensteuerung
    // ======================================================================
    private function manualZone(int $idx, bool $on): void
    {
        if ($this->GetValue('SeqControl') !== 0) {
            throw new Exception('Automatik-Sequenz läuft – bitte zuerst stoppen.');
        }
        $zones = $this->logicalZones();
        if (!isset($zones[$idx])) {
            return;
        }
        $open = $this->openList();
        $pumpOn = $this->ReadAttributeInteger('PumpOnSince') > 0;
        $name = $zones[$idx]['name'];

        $isLawn = !empty($zones[$idx]['sequential']);

        if ($on) {
            if (!$this->GetValue('Active')) {
                throw new Exception('Master-Schalter ist aus.');
            }
            if (in_array($idx, $open, true)) {
                return;
            }
            // Zusammengelegte Zonen (z. B. "Rasen") benötigen den Pumpenkreis
            // exklusiv: ihr interner Übergang zwischen den Teilventilen nutzt
            // bereits einen kurzen Überlapp (2 Ventile gleichzeitig offen),
            // eine zusätzliche andere Zone würde die maximale Anzahl
            // gleichzeitig offener Ventile überschreiten.
            if ($isLawn && count($open) > 0) {
                throw new Exception($name . ' benötigt den Pumpenkreis exklusiv – bitte zuerst alle anderen offenen Zonen schließen.');
            }
            if (!$isLawn && $this->anyOpenIsSequential()) {
                throw new Exception('Aktuell läuft eine zusammengelegte Zone (z. B. Rasen) – bitte warten, bis diese fertig ist.');
            }
            if (count($open) >= max(1, $this->ReadPropertyInteger('MaxParallel'))) {
                throw new Exception('Maximal ' . $this->ReadPropertyInteger('MaxParallel') . ' Zonen gleichzeitig erlaubt.');
            }

            // Bewässerungsdauer: das im WebFront direkt editierbare Dauer-Feld
            // dieser Zone (voreingestellt mit der Standard-Bewässerungsdauer
            // aus der Konfiguration, dort aber jederzeit anpassbar).
            $durSeconds = max(1, $this->GetValue('ManualDurationZ' . $idx)) * 60;

            if ($isLawn) {
                // Eigene, in sich geschlossene Kette mit kurzem Überlapp beim
                // Wechsel zwischen den Teilventilen (siehe lawnChainSteps()).
                // Die Dauer gilt je Teilfläche. "Rasen" ist exklusiv (siehe
                // Prüfung oben), daher ist diese Kette immer die einzige
                // Aktivität und darf komplett seriell in der Warteschlange
                // stehen, ohne andere Zonen zu blockieren.
                $this->enqueue($this->lawnChainSteps($zones[$idx], $idx, $durSeconds));
                return;
            }

            // WICHTIG: Die Bewässerungsdauer wird NICHT als Wartezeit in die
            // (instanzweit gemeinsame) serielle Warteschlange gelegt – sonst
            // würde eine zweite, während dieser Dauer manuell geöffnete Zone
            // erst nach Ablauf der ersten Wartezeit tatsächlich schalten
            // (die Warteschlange verarbeitet Schritte strikt nacheinander).
            // Stattdessen wird nur die kurze Schalt-Choreografie (Ventil auf
            // -> Verfahrzeit -> Pumpe an) seriell eingereiht, und die Dauer
            // separat als Ziel-Zeitpunkt hinterlegt. Ein periodischer Check
            // (CheckSchedule -> checkManualDeadlines) schließt die Zone dann
            // unabhängig von anderen offenen Zonen zum richtigen Zeitpunkt.
            $this->setZoneManualTarget($idx, $durSeconds);

            $steps = [];
            if (!$pumpOn) {
                // Erste Zone: Ventil auf -> Verfahrzeit -> Pumpe an
                $steps[] = ['cmd' => 'status', 'param' => 'Manuell: öffne ' . $name];
                $steps[] = ['cmd' => 'valve_on', 'zone' => $idx, 'post' => $this->travel($idx)];
                $steps[] = ['cmd' => 'pump_on'];
                $steps[] = ['cmd' => 'status', 'param' => 'Manuell: ' . $name . ' aktiv'];
            } else {
                // Pumpe läuft bereits (andere Zone offen): Ventil einfach öffnen
                $steps[] = ['cmd' => 'valve_on', 'zone' => $idx];
                $steps[] = ['cmd' => 'status', 'param' => 'Manuell: ' . $name . ' zusätzlich aktiv'];
            }
            $this->enqueue($steps);
        } else {
            if (!in_array($idx, $open, true)) {
                $this->SetValue('ManualZ' . $idx, false);
                return;
            }

            // Anstehenden Auto-Timer für diese Zone verwerfen (vorzeitiger
            // manueller Abbruch).
            $this->setZoneManualTarget($idx, 0);

            if ($isLawn) {
                // "Rasen" läuft exklusiv (siehe Prüfung beim Einschalten) –
                // die Warteschlange kann in diesem Moment daher nur noch
                // Schritte der eigenen, noch laufenden Kette enthalten und
                // darf komplett verworfen werden, um sie sofort abzubrechen.
                $this->clearQueue();
            }
            // Bei einfachen Zonen wird die Warteschlange bewusst NICHT
            // pauschal geleert: sie kann die kurze Schalt-Choreografie einer
            // ANDEREN, gerade erst geöffneten Zone enthalten. Falls diese
            // Zone selbst noch mitten in ihrer eigenen kurzen Öffnungs-
            // Choreografie steckt, werden die folgenden Schließ-Schritte
            // einfach angehängt und laufen danach korrekt durch – maximal
            // um eine Verfahrzeit verzögert, nie in falscher Reihenfolge.

            $steps = [];
            if ($pumpOn && count($open) === 1) {
                // Letzte offene Zone: Pumpe aus -> Verfahrzeit -> Ventil zu
                $steps[] = ['cmd' => 'status', 'param' => 'Manuell: beende ' . $name];
                $steps[] = ['cmd' => 'pump_off', 'post' => $this->travel($idx)];
                $steps[] = ['cmd' => 'valve_off', 'zone' => $idx];
                $steps[] = ['cmd' => 'status', 'param' => 'Bereit'];
            } else {
                // Weitere Zone bleibt offen: nur dieses Ventil/diese Ventile schließen
                $steps[] = ['cmd' => 'valve_off', 'zone' => $idx];
            }
            $this->enqueue($steps);
        }
    }

    // ======================================================================
    // Queue-Verwaltung
    // ======================================================================
    private function enqueue(array $steps): void
    {
        if (count($steps) === 0) {
            return;
        }
        if (IPS_SemaphoreEnter($this->sem(), 5000)) {
            $queue = json_decode($this->GetBuffer('Queue'), true) ?: [];
            $queue = array_merge($queue, $steps);
            $this->SetBuffer('Queue', json_encode($queue));
            IPS_SemaphoreLeave($this->sem());
        }
        $this->ProcessQueue();
    }

    private function clearQueue(): void
    {
        if (IPS_SemaphoreEnter($this->sem(), 5000)) {
            $this->SetBuffer('Queue', '[]');
            $this->SetBuffer('WaitUntil', '0');
            $this->SetTimerInterval('Queue', 0);
            IPS_SemaphoreLeave($this->sem());
        }
    }

    /**
     * Führt einen Warteschlangen-Schritt aus. Der Rückgabewert enthält
     * optional zusätzliche Schritte, die sofort VOR den bereits wartenden
     * Schritten eingefügt werden (aktuell ungenutzt, aber als Erweiterungs-
     * punkt beibehalten; die manuelle Auto-Abschaltung läuft inzwischen über
     * checkManualDeadlines(), unabhängig von dieser seriellen Warteschlange).
     */
    private function executeStep(array $step): array
    {
        switch ($step['cmd'] ?? '') {
            case 'valve_on':
                $this->setValve((int)$step['zone'], true, isset($step['member']) ? (int)$step['member'] : null);
                break;
            case 'valve_off':
                $this->setValve((int)$step['zone'], false, isset($step['member']) ? (int)$step['member'] : null);
                break;
            case 'pump_on':
                $this->setPump(true);
                break;
            case 'pump_off':
                $this->setPump(false);
                break;
            case 'status':
                $this->SetValue('Status', (string)$step['param']);
                break;
            case 'mark':
                $lr = json_decode($this->ReadAttributeString('LastRun'), true) ?: [];
                $lr[(string)$step['param']] = date('Y-m-d');
                $this->WriteAttributeString('LastRun', json_encode($lr));
                break;
            case 'seq_end':
                $this->SetValue('SeqControl', 0);
                $this->SetValue('Status', 'Bereit');
                $this->LogMessage('Sequenz beendet', KL_NOTIFY);
                break;
        }
        return [];
    }

    /**
     * Schließt eine einzelne Zone passend zum aktuellen Zustand (genutzt,
     * wenn die manuelle Bewässerungsdauer einer Zone abgelaufen ist). Ist die
     * Zone inzwischen bereits geschlossen (z. B. weil der Nutzer vorher
     * manuell ausgeschaltet hat), passiert nichts. Ist es die letzte noch
     * offene Zone, wird zusätzlich die Pumpe geordnet abgeschaltet.
     */
    private function autoCloseSteps(int $idx): array
    {
        $open = $this->openList();
        if (!in_array($idx, $open, true)) {
            return [];
        }
        $zones = $this->logicalZones();
        $name = $zones[$idx]['name'] ?? ('Zone ' . $idx);
        $pumpOn = $this->ReadAttributeInteger('PumpOnSince') > 0;

        if ($pumpOn && count($open) === 1) {
            return [
                ['cmd' => 'status', 'param' => 'Manuell: beende ' . $name],
                ['cmd' => 'pump_off', 'post' => $this->travel($idx)],
                ['cmd' => 'valve_off', 'zone' => $idx],
                ['cmd' => 'status', 'param' => 'Bereit'],
            ];
        }
        return [['cmd' => 'valve_off', 'zone' => $idx]];
    }

    /**
     * Erzeugt Schritte, um den aktuellen Zustand geordnet herunterzufahren:
     * Pumpe aus -> max. Verfahrzeit warten -> alle offenen Ventile zu.
     */
    private function shutdownSteps(): array
    {
        $steps = [];
        $open = $this->openList();
        if ($this->ReadAttributeInteger('PumpOnSince') > 0) {
            $maxTravel = 7;
            foreach ($open as $i) {
                $maxTravel = max($maxTravel, $this->travel($i));
            }
            $steps[] = ['cmd' => 'status', 'param' => 'Stoppe laufende Bewässerung …'];
            $steps[] = ['cmd' => 'pump_off', 'post' => $maxTravel];
        }
        foreach ($open as $i) {
            $members = $this->zoneOpenMembers($i);
            if (count($members) === 0) {
                $steps[] = ['cmd' => 'valve_off', 'zone' => $i];
                continue;
            }
            foreach ($members as $m) {
                $steps[] = ['cmd' => 'valve_off', 'zone' => $i, 'member' => $m];
            }
        }
        return $steps;
    }

    // ======================================================================
    // Aktoren schalten (KNX)
    // ======================================================================
    /**
     * Schaltet ein oder alle Teilventile einer logischen Zone.
     * $member = null -> alle Teilventile (Normalfall bei einfachen Zonen).
     * $member = Index -> nur dieses eine Teilventil (für das strikt
     * sequentielle Schalten von "Rasen": nie zwei Teilventile gleichzeitig).
     */
    private function setValve(int $idx, bool $state, ?int $member = null): void
    {
        $zones = $this->logicalZones();
        if (!isset($zones[$idx])) {
            return;
        }
        $valves = $zones[$idx]['valves'];
        $targets = $member === null ? array_keys($valves) : [$member];

        $openMembers = $this->zoneOpenMembers($idx);
        $prevCount = count($openMembers);
        foreach ($targets as $t) {
            if (!isset($valves[$t])) {
                continue;
            }
            $this->knxSwitch((int)$valves[$t]['instanceID'], $state);
            if ($state) {
                if (!in_array($t, $openMembers, true)) {
                    $openMembers[] = $t;
                    // Zyklenzähler: jedes Öffnen = 1 Zyklus des betroffenen physischen Kugelhahns
                    $p = $valves[$t]['physIdx'];
                    $cyclesID = $this->statVarID('CyclesP' . $p);
                    if ($cyclesID > 0) {
                        SetValue($cyclesID, GetValue($cyclesID) + 1);
                    }
                }
            } else {
                $openMembers = array_values(array_diff($openMembers, [$t]));
            }
        }
        $this->setZoneOpenMembers($idx, $openMembers);
        $newCount = count($openMembers);

        $open = $this->openList();
        if ($newCount > 0) {
            if (!in_array($idx, $open, true)) {
                $open[] = $idx;
            }
        } else {
            $open = array_values(array_diff($open, [$idx]));
        }
        $this->WriteAttributeString('Open', json_encode($open));

        // Zonen-Laufzeit: zählt die Zeit, in der die Pumpe läuft UND diese Zone
        // offen ist (tatsächliche Bewässerungszeit). Wird die Zone neu geöffnet
        // während die Pumpe schon läuft (z. B. zusätzliche manuelle Zone oder
        // Sequenz-Übergang), startet die Zählung sofort; startet sie erst mit
        // der Pumpe selbst, übernimmt das setPump().
        if ($prevCount === 0 && $newCount > 0 && $this->ReadAttributeInteger('PumpOnSince') > 0) {
            $this->startZoneRuntime($idx);
        }
        if ($prevCount > 0 && $newCount === 0) {
            $this->stopZoneRuntime($idx);
        }

        // Zonen-Schalter im WebFront spiegelt immer den echten Zustand: an, solange
        // mindestens ein Teilventil offen ist (bei "Rasen" also während der ganzen Kette)
        if (@$this->GetIDForIdent('ManualZ' . $idx) !== false) {
            $this->SetValue('ManualZ' . $idx, $newCount > 0);
        }
    }

    private function zoneOpenMembers(int $idx): array
    {
        $all = json_decode($this->ReadAttributeString('OpenMembers'), true) ?: [];
        return $all[(string)$idx] ?? [];
    }

    private function setZoneOpenMembers(int $idx, array $members): void
    {
        $all = json_decode($this->ReadAttributeString('OpenMembers'), true) ?: [];
        if (count($members) > 0) {
            $all[(string)$idx] = array_values($members);
        } else {
            unset($all[(string)$idx]);
        }
        $this->WriteAttributeString('OpenMembers', json_encode($all));
    }

    /**
     * True, wenn aktuell eine strikt-sequentielle Zone (z. B. "Rasen") offen
     * ist bzw. mitten in ihrer eigenen Ablösekette steckt.
     */
    private function anyOpenIsSequential(): bool
    {
        $zones = $this->logicalZones();
        foreach ($this->openList() as $i) {
            if (!empty($zones[$i]['sequential'] ?? false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Baut die in sich geschlossene Schrittkette für eine zusammengelegte
     * Zone (z. B. "Rasen") nach dem Schema:
     *   Ventil 1 auf -> Verfahrzeit warten -> Pumpe an
     *   -> Ventil 2 auf -> Überlapp warten -> Ventil 1 zu
     *   -> [weitere Teilventile nach demselben Übergangs-Schema]
     *   -> Pumpe aus -> Verfahrzeit warten -> letztes Ventil zu
     * Das entspricht exakt dem normalen Sequenz-Übergang zwischen zwei
     * Zonen (nächstes Ventil auf -> Überlapp warten -> vorheriges Ventil
     * zu), nur innerhalb einer einzigen logischen Zone angewendet: die
     * Teilventile sind dabei während der Überlapp-Zeit kurz gemeinsam
     * offen, danach läuft immer nur ein Teilventil.
     */
    private function lawnChainSteps(array $zone, int $idx, int $perMemberDurationSeconds): array
    {
        $steps = [];
        $members = $zone['valves'];
        $count = count($members);
        $overlap = max(0, $this->ReadPropertyInteger('OverlapTime'));

        foreach ($members as $i => $v) {
            $label = $count > 1 ? ($zone['name'] . ' – Teil ' . ($i + 1) . '/' . $count) : $zone['name'];
            $travel = max(1, (int)($v['travel'] ?? 7));

            if ($i === 0) {
                // Erstes Teilventil: Ventil auf -> Verfahrzeit -> Pumpe an
                $steps[] = ['cmd' => 'status', 'param' => 'öffne ' . $label];
                $steps[] = ['cmd' => 'valve_on', 'zone' => $idx, 'member' => $i, 'post' => $travel];
                $steps[] = ['cmd' => 'pump_on'];
                $steps[] = ['cmd' => 'status', 'param' => 'bewässere ' . $label, 'post' => $perMemberDurationSeconds];
            } else {
                // Übergang zum nächsten Teilventil: nächstes Ventil auf ->
                // Überlapp warten -> vorheriges Ventil zu (wie beim normalen
                // Sequenz-Übergang zwischen zwei Zonen)
                $steps[] = ['cmd' => 'valve_on', 'zone' => $idx, 'member' => $i, 'post' => $overlap];
                $steps[] = ['cmd' => 'valve_off', 'zone' => $idx, 'member' => $i - 1];
                $steps[] = ['cmd' => 'status', 'param' => 'bewässere ' . $label, 'post' => max(0, $perMemberDurationSeconds - $overlap)];
            }
        }

        // Abschluss: Pumpe aus -> Verfahrzeit -> letztes Teilventil zu
        $lastIndex = $count - 1;
        $lastTravel = max(1, (int)($members[$lastIndex]['travel'] ?? 7));
        $steps[] = ['cmd' => 'status', 'param' => 'beende ' . $zone['name']];
        $steps[] = ['cmd' => 'pump_off', 'post' => $lastTravel];
        $steps[] = ['cmd' => 'valve_off', 'zone' => $idx, 'member' => $lastIndex];

        return $steps;
    }

    private function setPump(bool $state): void
    {
        $instanceID = $this->ReadPropertyInteger('PumpInstanceID');
        if ($state) {
            if ($this->ReadAttributeInteger('PumpOnSince') === 0) {
                $this->knxSwitch($instanceID, true);
                $this->WriteAttributeInteger('PumpOnSince', time());
                // Für alle bereits offenen Zonen beginnt jetzt die tatsächliche Bewässerung
                foreach ($this->openList() as $i) {
                    $this->startZoneRuntime($i);
                }
            }
        } else {
            $this->knxSwitch($instanceID, false);
            $since = $this->ReadAttributeInteger('PumpOnSince');
            if ($since > 0) {
                $delta = time() - $since;
                $this->WriteAttributeInteger('DayAccum', $this->ReadAttributeInteger('DayAccum') + $delta);
                $this->WriteAttributeInteger('TotalAccum', $this->ReadAttributeInteger('TotalAccum') + $delta);
                $this->WriteAttributeInteger('PumpOnSince', 0);
            }
            // Für alle noch als "laufend" gezählten Zonen die Bewässerungszeit stoppen
            foreach ($this->openList() as $i) {
                $this->stopZoneRuntime($i);
            }
            $this->updateRuntimeDisplay();
        }
    }

    /**
     * Schreibt einen Boolean-Wert über KNX_WriteDPT1 auf die angegebene
     * KNX-Geräteinstanz (die im KNX-Konfigurator/ETS-Import für die
     * jeweilige Gruppenadresse mit Unit "1.001 Schalten" angelegt wurde).
     */
    private function knxSwitch(int $instanceID, bool $value): void
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            $this->LogMessage('KNX-Instanz #' . $instanceID . ' existiert nicht – Schaltbefehl übersprungen', KL_WARNING);
            return;
        }
        if (!function_exists('KNX_WriteDPT1')) {
            $this->LogMessage('Funktion KNX_WriteDPT1 nicht verfügbar – ist das KNX-Modul installiert?', KL_ERROR);
            return;
        }
        @KNX_WriteDPT1($instanceID, $value);
    }

    // ======================================================================
    // Bodenfeuchte-Logik
    // ======================================================================

    /**
     * Prüft, ob eine logische Zone laut konfigurierten Sensoren bewässert
     * werden soll. Ohne konfigurierten Sensor: immer true.
     * Bei mehreren Sensoren (Rasen-Zusammenlegung): true, sobald mindestens
     * ein Teilbereich als "trocken" gemeldet wird (ODER-Verknüpfung) –
     * es wird also gegossen, wenn irgendein Teil des Rasens es braucht.
     */
    private function zoneNeedsWater(array $zone): bool
    {
        if (count($zone['sensors']) === 0) {
            return true;
        }
        foreach ($zone['sensors'] as $s) {
            if ($s['id'] <= 0 || !IPS_VariableExists($s['id'])) {
                continue; // ungültiger/nicht konfigurierter Sensor -> ignorieren, nicht blockieren
            }
            $value = GetValue($s['id']);
            // Nicht invertiert: hoher Wert = feucht -> feucht genug, wenn Wert >= Schwelle
            // Invertiert:       hoher Wert = trocken -> feucht genug, wenn Wert <= Schwelle
            $moistEnough = $s['invert'] ? ($value <= $s['threshold']) : ($value >= $s['threshold']);
            if (!$moistEnough) {
                return true; // mind. ein Sensor meldet "trocken" -> gießen
            }
        }
        return false; // alle konfigurierten Sensoren melden "feucht genug"
    }

    // ======================================================================
    // Zonen-Verwaltung: physische Konfiguration <-> logische Kreise
    // ======================================================================

    /**
     * Liest die physischen Zonenzeilen aus der Konfiguration (1:1 wie im
     * Formular eingegeben, mit sinnvollen Vorgabewerten).
     */
    private function physicalZones(): array
    {
        $zones = json_decode($this->ReadPropertyString('Zones'), true) ?: [];
        foreach ($zones as $i => &$z) {
            if (trim((string)($z['Name'] ?? '')) === '') {
                $z['Name'] = 'Zone ' . ($i + 1);
            }
            $z['ValveInstanceID'] = (int)($z['ValveInstanceID'] ?? 0);
            $t = (int)($z['TravelTime'] ?? 7);
            $z['TravelTime'] = $t > 0 ? $t : 7;
            $d = (int)($z['DefaultDuration'] ?? 10);
            $z['DefaultDuration'] = $d > 0 ? $d : 10;
            $z['Lawn'] = !empty($z['Lawn']);
            $z['UseSensor'] = !empty($z['UseSensor']);
            $z['SensorID'] = (int)($z['SensorID'] ?? 0);
            $z['Threshold'] = (float)($z['Threshold'] ?? 60);
            $z['Invert'] = !empty($z['Invert']);
        }
        unset($z);
        return $zones;
    }

    /**
     * Baut die logischen Bewässerungskreise: alle physischen Zonen mit
     * gesetztem "Lawn"-Haken werden zu EINEM logischen Kreis (Name aus
     * Eigenschaft "LawnName") zusammengefasst, dessen Ventile immer
     * gemeinsam schalten. Alle übrigen Zonen bleiben eigenständige Kreise.
     * Die Position des zusammengefassten Kreises entspricht der Position
     * der ersten als "Lawn" markierten physischen Zeile.
     */
    private function logicalZones(): array
    {
        $physical = $this->physicalZones();
        $logical = [];
        $lawnDone = false;

        foreach ($physical as $i => $z) {
            if ($z['Lawn']) {
                if ($lawnDone) {
                    continue; // bereits als Sammelkreis eingefügt
                }
                $members = [];
                foreach ($physical as $j => $pz) {
                    if ($pz['Lawn']) {
                        $members[] = $pz + ['idx' => $j];
                    }
                }
                $logical[] = $this->buildLogicalZone($this->ReadPropertyString('LawnName') ?: 'Rasen', $members);
                $lawnDone = true;
                continue;
            }
            $logical[] = $this->buildLogicalZone($z['Name'], [$z + ['idx' => $i]]);
        }

        return array_values($logical);
    }

    private function buildLogicalZone(string $name, array $members): array
    {
        $valves = [];
        $sensors = [];
        $physIdx = [];
        $travel = 7;

        foreach ($members as $m) {
            $physIdx[] = $m['idx'];
            $valves[] = ['instanceID' => $m['ValveInstanceID'], 'physIdx' => $m['idx'], 'travel' => (int)$m['TravelTime']];
            $travel = max($travel, (int)$m['TravelTime']);
            if ($m['UseSensor']) {
                $sensors[] = ['id' => $m['SensorID'], 'threshold' => $m['Threshold'], 'invert' => $m['Invert']];
            }
        }

        return [
            'name'            => $name,
            'valves'          => $valves,
            'travel'          => $travel,
            'sensors'         => $sensors,
            'physIdx'         => $physIdx,
            // Standard-Bewässerungsdauer (Minuten) dieses Kreises: bei "Rasen"
            // die Vorgabe der ERSTEN als "Lawn" markierten Zeile, da die
            // Sequenz-Dauer bei "Rasen" je Teilfläche gilt.
            'defaultDuration' => (int)($members[0]['DefaultDuration'] ?? 10),
            // "sequential" = mehr als ein Ventil -> zusammengelegte Zone (z. B.
            // "Rasen"): Teilventile schalten über den Überlapp-Übergang nach-
            // einander (siehe lawnChainSteps()) und laufen exklusiv, ohne dass
            // gleichzeitig eine andere logische Zone geöffnet ist.
            'sequential'      => count($valves) > 1,
        ];
    }

    private function travel(int $idx): int
    {
        $zones = $this->logicalZones();
        return $zones[$idx]['travel'] ?? 7;
    }

    private function openList(): array
    {
        return json_decode($this->ReadAttributeString('Open'), true) ?: [];
    }

    private function updateRuntimeDisplay(): void
    {
        $since = $this->ReadAttributeInteger('PumpOnSince');
        $running = $since > 0 ? time() - $since : 0;
        $day = $this->ReadAttributeInteger('DayAccum') + $running;
        $total = $this->ReadAttributeInteger('TotalAccum') + $running;
        $dayID = $this->statVarID('RuntimeDay');
        $totalID = $this->statVarID('RuntimeTotal');
        if ($dayID > 0) {
            SetValue($dayID, (int)round($day / 60));
        }
        if ($totalID > 0) {
            SetValue($totalID, round($total / 3600, 2));
        }
    }

    // ------------------------------------------------------------------
    // Laufzeit je logischer Zone (Kreis): zählt die Zeit, in der die Pumpe
    // läuft UND die jeweilige Zone offen ist (tatsächliche Bewässerungszeit).
    // ------------------------------------------------------------------

    private function zoneRunSince(int $idx): int
    {
        $all = json_decode($this->ReadAttributeString('ZoneRunSince'), true) ?: [];
        return (int)($all[(string)$idx] ?? 0);
    }

    private function setZoneRunSince(int $idx, int $ts): void
    {
        $all = json_decode($this->ReadAttributeString('ZoneRunSince'), true) ?: [];
        if ($ts > 0) {
            $all[(string)$idx] = $ts;
        } else {
            unset($all[(string)$idx]);
        }
        $this->WriteAttributeString('ZoneRunSince', json_encode($all));
    }

    private function zoneDayAccum(int $idx): int
    {
        $all = json_decode($this->ReadAttributeString('ZoneDayAccum'), true) ?: [];
        return (int)($all[(string)$idx] ?? 0);
    }

    private function addZoneDayAccum(int $idx, int $delta): void
    {
        $all = json_decode($this->ReadAttributeString('ZoneDayAccum'), true) ?: [];
        $all[(string)$idx] = (int)($all[(string)$idx] ?? 0) + $delta;
        $this->WriteAttributeString('ZoneDayAccum', json_encode($all));
    }

    private function zoneTotalAccum(int $idx): int
    {
        $all = json_decode($this->ReadAttributeString('ZoneTotalAccum'), true) ?: [];
        return (int)($all[(string)$idx] ?? 0);
    }

    private function addZoneTotalAccum(int $idx, int $delta): void
    {
        $all = json_decode($this->ReadAttributeString('ZoneTotalAccum'), true) ?: [];
        $all[(string)$idx] = (int)($all[(string)$idx] ?? 0) + $delta;
        $this->WriteAttributeString('ZoneTotalAccum', json_encode($all));
    }

    private function startZoneRuntime(int $idx): void
    {
        if ($this->zoneRunSince($idx) === 0) {
            $this->setZoneRunSince($idx, time());
        }
    }

    private function stopZoneRuntime(int $idx): void
    {
        $since = $this->zoneRunSince($idx);
        if ($since > 0) {
            $delta = time() - $since;
            $this->addZoneDayAccum($idx, $delta);
            $this->addZoneTotalAccum($idx, $delta);
            $this->setZoneRunSince($idx, 0);
        }
        $this->updateZoneRuntimeDisplay($idx);
    }

    private function updateZoneRuntimeDisplay(int $idx): void
    {
        $since = $this->zoneRunSince($idx);
        $running = $since > 0 ? time() - $since : 0;
        $day = $this->zoneDayAccum($idx) + $running;
        $total = $this->zoneTotalAccum($idx) + $running;
        $dayID = $this->statVarID('ZRunDay' . $idx);
        if ($dayID > 0) {
            SetValue($dayID, (int)round($day / 60));
        }
        $totalID = $this->statVarID('ZRunTotal' . $idx);
        if ($totalID > 0) {
            SetValue($totalID, round($total / 3600, 2));
        }
    }

    private function updateAllZoneRuntimeDisplays(): void
    {
        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            $this->updateZoneRuntimeDisplay($i);
        }
    }

    /**
     * Spiegelt die optional verknüpften Sensor-Variablen (Wasserdruck,
     * Durchfluss) in die eigenen Anzeigevariablen "Pressure"/"Flow". Ist
     * kein Sensor konfiguriert oder die verknüpfte Variable nicht (mehr)
     * vorhanden, bleibt der zuletzt bekannte Wert stehen.
     */
    private function updateSensorDisplays(): void
    {
        $pressureID = $this->ReadPropertyInteger('PressureSensorID');
        if ($pressureID > 0 && IPS_VariableExists($pressureID)) {
            $this->SetValue('Pressure', (float)GetValue($pressureID));
        }

        $flowID = $this->ReadPropertyInteger('FlowSensorID');
        if ($flowID > 0 && IPS_VariableExists($flowID)) {
            $this->SetValue('Flow', (float)GetValue($flowID));
        }
    }

    // ------------------------------------------------------------------
    // Manuelle Bewässerungsdauer je Zone: unabhängig von der seriellen
    // Schalt-Warteschlange verwaltet, damit mehrere manuell geöffnete Zonen
    // ihre jeweils eigene Dauer parallel abwarten können (siehe manualZone()).
    // ------------------------------------------------------------------

    private function zoneManualTarget(int $idx): int
    {
        $all = json_decode($this->ReadAttributeString('ZoneManualTarget'), true) ?: [];
        return (int)($all[(string)$idx] ?? 0);
    }

    private function setZoneManualTarget(int $idx, int $seconds): void
    {
        $all = json_decode($this->ReadAttributeString('ZoneManualTarget'), true) ?: [];
        if ($seconds > 0) {
            $all[(string)$idx] = $seconds;
        } else {
            unset($all[(string)$idx]);
        }
        $this->WriteAttributeString('ZoneManualTarget', json_encode($all));
    }

    /**
     * Wird periodisch (aus CheckSchedule) aufgerufen: prüft für jede Zone mit
     * gesetzter manueller Ziel-Dauer, ob die tatsächliche Bewässerungszeit
     * (zoneRunSince, "Pumpe an + Ventil offen") die Ziel-Dauer erreicht hat,
     * und schließt sie dann passend – unabhängig davon, ob andere Zonen
     * noch offen sind. Toleranz: bis zu einem Prüfintervall (Standard 30 s),
     * für Bewässerungsdauern im Minutenbereich unkritisch.
     */
    private function checkManualDeadlines(): void
    {
        $targets = json_decode($this->ReadAttributeString('ZoneManualTarget'), true) ?: [];
        if (count($targets) === 0) {
            return;
        }
        foreach ($targets as $idxStr => $targetSeconds) {
            $idx = (int)$idxStr;
            $since = $this->zoneRunSince($idx);
            if ($since === 0) {
                continue; // wässert gerade noch nicht aktiv (z. B. noch in der Öffnungs-Verfahrzeit)
            }
            if (time() - $since < (int)$targetSeconds) {
                continue; // noch nicht fällig
            }
            $this->setZoneManualTarget($idx, 0);
            $this->enqueue($this->autoCloseSteps($idx));
        }
    }

    private function sem(): string
    {
        return 'BWS_' . $this->InstanceID;
    }

    // ======================================================================
    // Konfigurationsformular (dynamisch, damit Zonennamen in den
    // Sequenz-Auswahlfeldern erscheinen)
    // ======================================================================
    public function GetConfigurationForm()
    {
        $zones = $this->logicalZones();
        $zoneOptions = [];
        foreach ($zones as $i => $z) {
            $zoneOptions[] = ['caption' => ($i + 1) . ': ' . $z['name'], 'value' => $i];
        }
        if (count($zoneOptions) === 0) {
            $zoneOptions[] = ['caption' => '– zuerst Zonen anlegen –', 'value' => 0];
        }

        $sequenceColumns = [
            [
                'caption' => 'Zone',
                'name'    => 'Zone',
                'width'   => '220px',
                'add'     => 0,
                'edit'    => ['type' => 'Select', 'options' => $zoneOptions]
            ],
            [
                'caption' => 'Dauer (0 = Standard der Zone verwenden)',
                'name'    => 'Duration',
                'width'   => '230px',
                'add'     => 0,
                'edit'    => ['type' => 'NumberSpinner', 'suffix' => ' min', 'minimum' => 0]
            ],
            [
                'caption' => 'Intervall',
                'name'    => 'Interval',
                'width'   => '160px',
                'add'     => 1,
                'edit'    => ['type' => 'Select', 'options' => [
                    ['caption' => 'täglich', 'value' => 1],
                    ['caption' => 'alle 2 Tage', 'value' => 2],
                    ['caption' => 'alle 3 Tage', 'value' => 3],
                    ['caption' => 'alle 4 Tage', 'value' => 4],
                    ['caption' => 'alle 5 Tage', 'value' => 5],
                    ['caption' => 'alle 6 Tage', 'value' => 6],
                    ['caption' => 'wöchentlich (alle 7 Tage)', 'value' => 7],
                ]]
            ],
            [
                'caption' => 'Parallel zur vorherigen Zone',
                'name'    => 'Parallel',
                'width'   => '210px',
                'add'     => false,
                'edit'    => ['type' => 'CheckBox']
            ],
            [
                'caption' => 'Aktiv',
                'name'    => 'Active',
                'width'   => '70px',
                'add'     => true,
                'edit'    => ['type' => 'CheckBox']
            ]
        ];

        $form = [
            'elements' => [
                [
                    'type'    => 'SelectInstance',
                    'name'    => 'PumpInstanceID',
                    'caption' => 'Pumpe – KNX-Instanz ("Schalten", DPT1)'
                ],
                [
                    'type'    => 'SelectVariable',
                    'name'    => 'PressureSensorID',
                    'caption' => 'Wasserdrucksensor (optional, vorhandene Variable)'
                ],
                [
                    'type'    => 'SelectVariable',
                    'name'    => 'FlowSensorID',
                    'caption' => 'Durchflusssensor (optional, vorhandene Variable)'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'LawnName',
                    'caption' => 'Anzeigename der zusammengelegten Rasen-Zone'
                ],
                [
                    'type'    => 'List',
                    'name'    => 'Zones',
                    'caption' => 'Physische Beregnungszonen (Reihenfolge = Zonennummer)',
                    'rowCount' => 7,
                    'add'     => true,
                    'delete'  => true,
                    'columns' => [
                        [
                            'caption' => 'Name',
                            'name'    => 'Name',
                            'width'   => '140px',
                            'add'     => '',
                            'edit'    => ['type' => 'ValidationTextBox']
                        ],
                        [
                            'caption' => 'Motorkugelhahn – KNX-Instanz ("Schalten", DPT1)',
                            'name'    => 'ValveInstanceID',
                            'width'   => '280px',
                            'add'     => 0,
                            'edit'    => ['type' => 'SelectInstance']
                        ],
                        [
                            'caption' => 'Verfahrzeit',
                            'name'    => 'TravelTime',
                            'width'   => '100px',
                            'add'     => 7,
                            'edit'    => ['type' => 'NumberSpinner', 'suffix' => ' s', 'minimum' => 0]
                        ],
                        [
                            'caption' => 'Standard-Bewässerungsdauer',
                            'name'    => 'DefaultDuration',
                            'width'   => '190px',
                            'add'     => 10,
                            'edit'    => ['type' => 'NumberSpinner', 'suffix' => ' min', 'minimum' => 1]
                        ],
                        [
                            'caption' => 'Teil von „Rasen“ (läuft nacheinander)',
                            'name'    => 'Lawn',
                            'width'   => '210px',
                            'add'     => false,
                            'edit'    => ['type' => 'CheckBox']
                        ],
                        [
                            'caption' => 'Bodenfeuchtesensor nutzen',
                            'name'    => 'UseSensor',
                            'width'   => '150px',
                            'add'     => false,
                            'edit'    => ['type' => 'CheckBox']
                        ],
                        [
                            'caption' => 'Sensor-Variable',
                            'name'    => 'SensorID',
                            'width'   => '220px',
                            'add'     => 0,
                            'edit'    => ['type' => 'SelectVariable']
                        ],
                        [
                            'caption' => 'Schwelle „feucht genug“',
                            'name'    => 'Threshold',
                            'width'   => '160px',
                            'add'     => 60,
                            'edit'    => ['type' => 'NumberSpinner', 'suffix' => ' %', 'minimum' => 0, 'maximum' => 100]
                        ],
                        [
                            'caption' => 'Skala invertiert',
                            'name'    => 'Invert',
                            'width'   => '110px',
                            'add'     => false,
                            'edit'    => ['type' => 'CheckBox']
                        ]
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Sequenz 1 (morgens) – Reihenfolge = Bewässerungsreihenfolge',
                    'items'   => [[
                        'type'     => 'List',
                        'name'     => 'Sequence1',
                        'rowCount' => 7,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => $sequenceColumns
                    ]]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Sequenz 2 (abends) – Reihenfolge = Bewässerungsreihenfolge',
                    'items'   => [[
                        'type'     => 'List',
                        'name'     => 'Sequence2',
                        'rowCount' => 7,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => $sequenceColumns
                    ]]
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'MaxParallel',
                    'caption' => 'Maximal gleichzeitig geöffnete Zonen',
                    'suffix'  => ' Zonen',
                    'minimum' => 1,
                    'maximum' => 2
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'OverlapTime',
                    'caption' => 'Überlapp beim Zonenwechsel',
                    'suffix'  => ' s',
                    'minimum' => 0
                ]
            ],
            'actions' => [
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        ['type' => 'Button', 'caption' => 'Sequenz 1 jetzt starten', 'onClick' => 'BWS_StartSequence($id, 1);'],
                        ['type' => 'Button', 'caption' => 'Sequenz 2 jetzt starten', 'onClick' => 'BWS_StartSequence($id, 2);'],
                        ['type' => 'Button', 'caption' => 'Alles stoppen', 'onClick' => 'BWS_StopAll($id);'],
                        ['type' => 'Button', 'caption' => 'Zähler zurücksetzen', 'onClick' => 'BWS_ResetCounters($id);']
                    ]
                ]
            ],
            'status' => []
        ];

        return json_encode($form);
    }
}
