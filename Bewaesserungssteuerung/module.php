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

    /** Request-lokaler Cache für logicalZones() – die Konfiguration ändert
     *  sich innerhalb eines PHP-Aufrufs nicht, wird aber von travel(),
     *  setValve() usw. sehr häufig benötigt. */
    private ?array $logicalZonesCache = null;

    public function Create()
    {
        parent::Create();

        // ------------------------------------------------------------------
        // Eigenschaften (Konfiguration)
        // ------------------------------------------------------------------
        $this->RegisterPropertyInteger('PumpInstanceID', 0);
        $this->RegisterPropertyInteger('PressureSensorID', 0); // optional: vorhandene Variable mit Wasserdruck-Messwert
        $this->RegisterPropertyInteger('FlowSensorID', 0);     // optional: vorhandene Variable mit Durchfluss-Messwert
        $this->RegisterPropertyInteger('PumpStatusID', 0);     // optional: vorhandene Variable mit KNX-Rückmeldung des Pumpenaktors (an/aus)

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
        $this->RegisterPropertyString('Seq1Name', 'Sequenz 1');
        $this->RegisterPropertyString('Seq2Name', 'Sequenz 2');
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
        $this->RegisterAttributeFloat('WaterRunAccum', 0);       // Verbrauch (Liter) der aktuellen/letzten Sequenz bzw. manuellen Aktion (Summe über alle beteiligten Kreise)
        $this->RegisterAttributeFloat('WaterGlobalTotal', 0);    // Verbrauch (Liter) gesamt über alle Zonen und alle Läufe
        $this->RegisterAttributeInteger('WaterLastSample', 0);   // Unix-Zeit der letzten Verbrauchs-Probe
        $this->RegisterAttributeInteger('WaterLastValveEvent', 0); // Unix-Zeit des letzten Ventil-Öffnen-Ereignisses (steuert 1s/10s-Takt)

        // ------------------------------------------------------------------
        // Variablenprofile
        // ------------------------------------------------------------------
        if (!IPS_VariableProfileExists('BWS.Minutes')) {
            IPS_CreateVariableProfile('BWS.Minutes', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('BWS.Minutes', '', ' min');
            IPS_SetVariableProfileIcon('BWS.Minutes', 'Clock');
        }
        // Wertebereich IMMER sicherstellen (auch wenn das Profil aus einer
        // älteren Modulversion schon existiert, aber noch ohne Bereich
        // angelegt wurde): ohne Min/Max/Schrittweite lässt sich ein daran
        // gebundenes editierbares Feld (z. B. "manuelle Dauer") im
        // WebFront nicht bedienen – reine Anzeigevariablen sind davon
        // nicht betroffen, da bei ihnen kein Eingabe-Element gerendert wird.
        IPS_SetVariableProfileValues('BWS.Minutes', 0, 240, 1);
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
        // Hinweis: Die Sequenz-Auswahl (SeqControl) nutzt ein
        // instanzspezifisches Profil BWS.SeqCtrl<InstanceID>, das weiter unten
        // erstellt wird (damit die frei wählbaren Sequenznamen je Instanz als
        // Button-Beschriftung erscheinen). Ein gemeinsames globales Profil
        // gibt es dafür bewusst nicht mehr.
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
        if (!IPS_VariableProfileExists('BWS.Liters')) {
            IPS_CreateVariableProfile('BWS.Liters', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('BWS.Liters', '', ' l');
            IPS_SetVariableProfileDigits('BWS.Liters', 2);
        }

        // ------------------------------------------------------------------
        // Timer
        // ------------------------------------------------------------------
        $this->RegisterTimer('Queue', 0, 'BWS_ProcessQueue($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Schedule', 0, 'BWS_CheckSchedule($_IPS[\'TARGET\']);');
        $this->RegisterTimer('WaterSample', 0, 'BWS_SampleWater($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        parent::Destroy();

        // Instanzspezifisches SeqControl-Profil beim endgültigen Löschen der
        // Instanz mit entfernen (bei Neustarts existiert die Instanz noch).
        if (!IPS_InstanceExists($this->InstanceID)) {
            $profile = 'BWS.SeqCtrl' . $this->InstanceID;
            if (IPS_VariableProfileExists($profile)) {
                IPS_DeleteVariableProfile($profile);
            }
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Konfiguration kann sich geändert haben -> Zonen-Cache verwerfen
        $this->logicalZonesCache = null;

        // ------------------------------------------------------------------
        // Statusvariablen
        // ------------------------------------------------------------------
        $newActive = @$this->GetIDForIdent('Active') === false;
        $this->RegisterVariableBoolean('Active', 'Master-Schalter', '~Switch', 10);
        $this->EnableAction('Active');
        if ($newActive) {
            $this->SetValue('Active', true);
        }

        // Instanzspezifisches Profil für die Sequenz-Auswahl, damit die frei
        // wählbaren Sequenznamen als Button-Beschriftungen erscheinen.
        // (Assoziationen werden bei jedem Übernehmen aktualisiert.)
        $seqProfile = 'BWS.SeqCtrl' . $this->InstanceID;
        if (!IPS_VariableProfileExists($seqProfile)) {
            IPS_CreateVariableProfile($seqProfile, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon($seqProfile, 'Execute');
        }
        IPS_SetVariableProfileAssociation($seqProfile, 0, 'Stopp', '', 0xFF4040);
        IPS_SetVariableProfileAssociation($seqProfile, 1, $this->seqName(1), '', 0x40A0FF);
        IPS_SetVariableProfileAssociation($seqProfile, 2, $this->seqName(2), '', 0x4040FF);

        $this->RegisterVariableInteger('SeqControl', 'Automatik-Sequenz', $seqProfile, 20);
        $this->EnableAction('SeqControl');
        // Bestehende Installationen: Variable kann noch das alte globale
        // Profil tragen -> explizit auf das instanzspezifische umstellen.
        IPS_SetVariableCustomProfile($this->GetIDForIdent('SeqControl'), $seqProfile);

        $newStatus = @$this->GetIDForIdent('Status') === false;
        $this->RegisterVariableString('Status', 'Status', '', 30);
        if ($newStatus) {
            $this->SetValue('Status', 'Bereit');
        }

        // Pumpenstatus: reine Anzeigevariable, die optional die tatsächliche
        // KNX-Rückmeldung des Pumpenaktors spiegelt (siehe updateSensorDisplays()).
        // Ohne konfigurierte Rückmeldevariable bleibt sie beim zuletzt bekannten
        // Wert stehen (Startwert: aus).
        $newPumpStatus = @$this->GetIDForIdent('PumpStatus') === false;
        $this->RegisterVariableBoolean('PumpStatus', 'Pumpenstatus (Rückmeldung)', '~Switch', 31);
        if ($newPumpStatus) {
            $this->SetValue('PumpStatus', false);
        }

        // Wasserdruck/Durchfluss: reine Anzeigevariablen, die optional den
        // Messwert einer bereits vorhandenen Sensor-Variable spiegeln (siehe
        // updateSensorDisplays()). Ohne konfigurierten Sensor bleiben sie bei 0.
        $this->RegisterVariableFloat('Pressure', 'Wasserdruck', 'BWS.Pressure', 32);
        $this->RegisterVariableFloat('Flow', 'Durchfluss', 'BWS.Flow', 34);

        // Restlaufzeit: verbleibende Zeit bis zum vollständigen Abschluss der
        // aktuell laufenden Automatik-Sequenz bzw. manuellen Bewässerung
        // (siehe updateRemainingDisplay()). 0, wenn nichts aktiv ist.
        $this->RegisterVariableInteger('Remaining', 'Restlaufzeit', 'BWS.Minutes', 36);

        $newST1 = @$this->GetIDForIdent('StartTime1') === false;
        $this->RegisterVariableInteger('StartTime1', 'Startzeit ' . $this->seqName(1), '~UnixTimestampTime', 40);
        $this->EnableAction('StartTime1');
        IPS_SetName($this->GetIDForIdent('StartTime1'), 'Startzeit ' . $this->seqName(1));
        if ($newST1) {
            $this->SetValue('StartTime1', strtotime('06:00'));
        }

        $newA1 = @$this->GetIDForIdent('Auto1') === false;
        $this->RegisterVariableBoolean('Auto1', 'Automatik ' . $this->seqName(1), '~Switch', 45);
        $this->EnableAction('Auto1');
        IPS_SetName($this->GetIDForIdent('Auto1'), 'Automatik ' . $this->seqName(1));
        if ($newA1) {
            $this->SetValue('Auto1', true);
        }

        $newST2 = @$this->GetIDForIdent('StartTime2') === false;
        $this->RegisterVariableInteger('StartTime2', 'Startzeit ' . $this->seqName(2), '~UnixTimestampTime', 50);
        $this->EnableAction('StartTime2');
        IPS_SetName($this->GetIDForIdent('StartTime2'), 'Startzeit ' . $this->seqName(2));
        if ($newST2) {
            $this->SetValue('StartTime2', strtotime('19:00'));
        }

        $newA2 = @$this->GetIDForIdent('Auto2') === false;
        $this->RegisterVariableBoolean('Auto2', 'Automatik ' . $this->seqName(2), '~Switch', 55);
        $this->EnableAction('Auto2');
        IPS_SetName($this->GetIDForIdent('Auto2'), 'Automatik ' . $this->seqName(2));
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
        // Kategorien: "Manuelle Laufzeit" (editierbare Dauer je Kreis) und
        // "Nächste Laufzeiten" (Vorschau, wann welche Zone wieder läuft)
        // ------------------------------------------------------------------
        $manualDurCategoryID = $this->ensureCategory('ManualDurCategory', 'Manuelle Laufzeit', 150);
        $planCategoryID = $this->ensureCategory('PlanCategory', 'Nächste Laufzeiten', 60);

        // ------------------------------------------------------------------
        // Manuelle Schalter pro logischer Zone (Steuerung, direkt bei der
        // Instanz), Dauer je Kreis (-> "Manuelle Laufzeit"), nächste
        // Laufzeit (-> "Nächste Laufzeiten"), Laufzeitanzeige (-> Statistik)
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

            // Migration aus älteren Versionen: die Dauer-Variable lag früher
            // als direktes Kind bei der Instanz ("<Zone> – manuelle Dauer").
            // Wert übernehmen und die alte Variable entfernen, bevor die neue
            // in der Kategorie gepflegt wird.
            $migratedValue = null;
            $oldID = @$this->GetIDForIdent('ManualDurationZ' . $i);
            if ($oldID !== false) {
                $migratedValue = (int)GetValue($oldID);
                $this->UnregisterVariable('ManualDurationZ' . $i);
            }

            $isNewDur = $used && @IPS_GetObjectIDByIdent('ManualDurationZ' . $i, $manualDurCategoryID) === false;
            $this->maintainCatVariable($manualDurCategoryID, 'ManualDurationZ' . $i, $name, VARIABLETYPE_INTEGER, 'BWS.Minutes', 150 + $i, $used, true);
            if ($used) {
                $durID = $this->catVarID('ManualDurCategory', 'ManualDurationZ' . $i);
                if ($durID > 0) {
                    if ($migratedValue !== null && $migratedValue > 0) {
                        SetValue($durID, $migratedValue);
                    } elseif ($isNewDur) {
                        SetValue($durID, $logical[$i]['defaultDuration']);
                    }
                }
            }

            // Nächste geplante Laufzeit (reine Anzeige, siehe updateNextRunDisplays())
            $this->maintainCatVariable($planCategoryID, 'NextRunZ' . $i, $name, VARIABLETYPE_STRING, '', 60 + $i, $used, false);

            $this->maintainStatVariable('ZRunDay' . $i, $name . ' – Laufzeit heute', VARIABLETYPE_INTEGER, 'BWS.Minutes', 400 + $i, $used, $statsCategoryID);
            $this->maintainStatVariable('ZRunTotal' . $i, $name . ' – Laufzeit gesamt', VARIABLETYPE_FLOAT, 'BWS.Hours', 500 + $i, $used, $statsCategoryID);
        }

        // ------------------------------------------------------------------
        // Laufzeitanzeige (Pumpe gesamt) und Wasserverbrauch (-> Statistik).
        // Wasserverbrauch bewusst NICHT pro Zone, sondern als zwei
        // Summenwerte: "letzte Laufzeit" (alle Kreise der zuletzt
        // abgeschlossenen Sequenz bzw. manuellen Aktion zusammen) und
        // "gesamt" (alles seit je her), siehe SampleWater()/setPump().
        // ------------------------------------------------------------------
        $this->maintainStatVariable('RuntimeDay', 'Pumpenlaufzeit heute', VARIABLETYPE_INTEGER, 'BWS.Minutes', 300, true, $statsCategoryID);
        $this->maintainStatVariable('RuntimeTotal', 'Pumpenlaufzeit gesamt', VARIABLETYPE_FLOAT, 'BWS.Hours', 310, true, $statsCategoryID);
        $this->maintainStatVariable('WaterLastRun', 'Wasserverbrauch letzte Laufzeit', VARIABLETYPE_FLOAT, 'BWS.Liters', 320, true, $statsCategoryID);
        $this->maintainStatVariable('WaterTotal', 'Wasserverbrauch gesamt', VARIABLETYPE_FLOAT, 'BWS.Liters', 330, true, $statsCategoryID);

        // Zeitplan-Prüfung alle 10 Sekunden (auch Grundlage für die
        // Auto-Abschaltung manuell gestarteter Zonen, s. checkManualDeadlines)
        $this->SetTimerInterval('Schedule', 10000);
        $this->updateRuntimeDisplay();
        $this->updateAllZoneRuntimeDisplays();
        $this->updateWaterDisplay();
        $this->updateSensorDisplays();
        $this->updateRemainingDisplay();
        $this->updateNextRunDisplays();
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
     * Legt eine Variable DIREKT als Kind einer Kategorie an (nicht der
     * Instanz) bzw. pflegt sie (Name/Position/Profil werden bei jedem Aufruf
     * gesetzt). WICHTIG: Da diese Variablen keine direkten Kinder der
     * Instanz sind, funktionieren $this->GetIDForIdent() und
     * $this->SetValue() für sie NICHT – Zugriff über catVarID()/statVarID()
     * plus die globalen Funktionen SetValue($id, ...) / GetValue($id).
     * Mit $enableAction = true wird die Instanz als Aktionsziel der Variable
     * eingetragen: Bedienung im WebFront landet dann wie gewohnt in
     * RequestAction() mit dem Ident der Variable.
     */
    private function maintainCatVariable(int $categoryID, string $ident, string $name, int $type, string $profile, int $position, bool $used, bool $enableAction): void
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
        if ($enableAction) {
            @IPS_SetVariableCustomAction($id, $this->InstanceID);
        }
    }

    /**
     * Kompatibilitäts-Wrapper für Statistik-Variablen (reine Anzeigen ohne
     * Aktion in der Statistik-Kategorie).
     */
    private function maintainStatVariable(string $ident, string $name, int $type, string $profile, int $position, bool $used, int $categoryID): void
    {
        $this->maintainCatVariable($categoryID, $ident, $name, $type, $profile, $position, $used, false);
    }

    /**
     * Löst die Objekt-ID einer Variable auf, die als Kind einer per Ident
     * referenzierten Unterkategorie liegt. Gibt 0 zurück, wenn Kategorie
     * oder Variable (noch) nicht existieren.
     */
    private function catVarID(string $categoryIdent, string $ident): int
    {
        $catID = @$this->GetIDForIdent($categoryIdent);
        if ($catID === false) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $catID);
        return $id === false ? 0 : $id;
    }

    /**
     * Löst die Objekt-ID einer Statistik-Variable auf (Kind der
     * Statistik-Kategorie). Gibt 0 zurück, wenn die Kategorie oder die
     * Variable (noch) nicht existiert.
     */
    private function statVarID(string $ident): int
    {
        return $this->catVarID('StatsCategory', $ident);
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
                // Liegt in der Unterkategorie "Manuelle Laufzeit" -> Zugriff
                // über die aufgelöste Objekt-ID statt $this->SetValue().
                $durID = $this->catVarID('ManualDurCategory', $Ident);
                if ($durID > 0) {
                    SetValue($durID, max(1, (int)$Value));
                }
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

        $seqLabel = $this->seqName($Sequence);

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
            $msg = $seqLabel . ': heute keine Zone fällig';
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
                $steps[] = ['cmd' => 'status', 'param' => $seqLabel . ': öffne ' . $groupName($g)];
                foreach ($g as $j => $z) {
                    $steps[] = ['cmd' => 'mark', 'param' => 's' . $Sequence . 'z' . $z['idx']];
                    $steps[] = [
                        'cmd'  => 'valve_on',
                        'zone' => $z['idx'],
                        'post' => ($j === count($g) - 1) ? $groupTravel($g) : 0
                    ];
                }
                $steps[] = ['cmd' => 'pump_on'];
                $steps[] = ['cmd' => 'status', 'param' => $seqLabel . ': bewässere ' . $groupName($g), 'post' => $groupDur($g)];
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
                $steps[] = ['cmd' => 'status', 'param' => $seqLabel . ': bewässere ' . $groupName($g), 'post' => max(0, $groupDur($g) - $overlap)];
            }
        }

        // Abschluss: Pumpe aus -> Verfahrzeit -> letzte Ventile zu
        // (falls die letzte Gruppe "Rasen" war, ist bereits alles geschlossen)
        $lastGroup = $groups[count($groups) - 1];
        $steps[] = ['cmd' => 'status', 'param' => $seqLabel . ': beende'];
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
        $this->LogMessage($seqLabel . ' gestartet (' . count($due) . ' Zonen)' . $note, KL_NOTIFY);
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
     * Setzt Pumpenlaufzeiten, Zonenlaufzeiten, Wasserverbrauch (gesamt) und
     * Zyklenzähler zurück. Der Verbrauch des gerade laufenden Bewässerungs-
     * laufs ("letzte Laufzeit") bleibt erhalten, da er sich beim nächsten
     * Zonenstart ohnehin automatisch zurücksetzt.
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

        // Wasserverbrauch (Gesamtsumme) zurücksetzen; der Verbrauch der
        // aktuellen/letzten Session ("letzte Laufzeit") bleibt unangetastet,
        // da er sich beim nächsten Sequenz-/manuellen Start ohnehin
        // automatisch zurücksetzt (siehe setPump()).
        $this->WriteAttributeFloat('WaterGlobalTotal', 0);

        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            $id = $this->statVarID('CyclesP' . $i);
            if ($id > 0) {
                SetValue($id, 0);
            }
        }
        $this->updateRuntimeDisplay();
        $this->updateAllZoneRuntimeDisplays();
        $this->updateWaterDisplay();
    }

    /**
     * Timer: prüft Startzeiten (alle 10 s), setzt Tageszähler um Mitternacht
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
        $this->updateRemainingDisplay();
        $this->pumpWatchdog();
        $this->updateNextRunDisplays();

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
            $this->updateRemainingDisplay();
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
            // dieser Zone (Kategorie "Manuelle Laufzeit"; voreingestellt mit
            // der Standard-Bewässerungsdauer aus der Konfiguration).
            $durID = $this->catVarID('ManualDurCategory', 'ManualDurationZ' . $idx);
            $durMinutes = $durID > 0 ? (int)GetValue($durID) : 0;
            if ($durMinutes <= 0) {
                $durMinutes = $zones[$idx]['defaultDuration'];
            }
            $durSeconds = max(1, $durMinutes) * 60;

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
            if (count($open) === 0) {
                // Erste Zone: Ventil auf -> Verfahrzeit -> Pumpe an.
                // Hinweis: Hier wird bewusst count($open)===0 statt des
                // "PumpOnSince"-Merkers geprüft. Der Merker wird erst
                // gesetzt, wenn der pump_on-Schritt tatsächlich ausgeführt
                // wurde (nach Ablauf der Verfahrzeit) – die Open-Liste
                // dagegen sofort, wenn ein Ventil-auf-Befehl ausgeführt
                // wird. Bei zwei fast gleichzeitig geschalteten Zonen ist
                // die Open-Liste damit das zuverlässigere Signal, ob schon
                // eine Öffnungs-Choreografie läuft.
                $steps[] = ['cmd' => 'status', 'param' => 'Manuell: öffne ' . $name];
                $steps[] = ['cmd' => 'valve_on', 'zone' => $idx, 'post' => $this->travel($idx)];
                $steps[] = ['cmd' => 'pump_on'];
                $steps[] = ['cmd' => 'status', 'param' => 'Manuell: ' . $name . ' aktiv'];
            } else {
                // Es ist schon eine andere Zone offen bzw. gerade dabei zu
                // öffnen: Ventil öffnen, Verfahrzeit abwarten, dann das
                // Sicherheits-"pump_on" (setPump() sendet den Befehl immer,
                // auch wenn die Pumpe laut Merker schon läuft). Die
                // Verfahrzeit-Wartezeit VOR dem pump_on stellt sicher, dass
                // das Schema "Ventil auf -> warten -> Pumpe an" auch dann
                // eingehalten wird, wenn diese Schritte ausnahmsweise hinter
                // einer noch laufenden Schließ-Choreografie einer anderen
                // Zone ausgeführt werden (dann wäre die Pumpe zu diesem
                // Zeitpunkt tatsächlich aus). Im Normalfall (Pumpe läuft)
                // kostet die Wartezeit nichts außer ein paar Sekunden bis
                // zur Statusanzeige.
                $steps[] = ['cmd' => 'valve_on', 'zone' => $idx, 'post' => $this->travel($idx)];
                $steps[] = ['cmd' => 'pump_on'];
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
            // Das Schließen läuft über den "close_zone"-Schritt: ob dies die
            // letzte offene Zone ist (-> Pumpe mit ausschalten) wird erst zur
            // Ausführungszeit anhand des dann aktuellen Zustands entschieden
            // – nicht jetzt beim Einreihen, wo evtl. noch eine gerade
            // öffnende andere Zone unsichtbar in der Warteschlange steckt.
            $this->enqueue([['cmd' => 'close_zone', 'zone' => $idx]]);
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
     * Schritten eingefügt werden (genutzt von "close_zone", um die
     * Entscheidung "letzte Zone -> Pumpe mit ausschalten?" erst zur
     * Ausführungszeit anhand des dann aktuellen Zustands zu treffen).
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
            case 'close_zone':
                // WICHTIG: erst JETZT (zur Ausführungszeit) entscheiden, ob
                // dies die letzte offene Zone ist und die Pumpe mit
                // ausgeschaltet werden muss. Würde das schon beim Einreihen
                // entschieden, könnte eine zwischenzeitlich geöffnete andere
                // Zone übersehen und die Pumpe fälschlich abgeschaltet
                // werden, obwohl noch eine Zone bewässert (Race zwischen
                // "Zone B öffnet" und "Zone A schließt").
                return $this->autoCloseSteps((int)$step['zone']);
            case 'seq_end':
                $this->SetValue('SeqControl', 0);
                $this->SetValue('Status', 'Bereit');
                $this->LogMessage('Sequenz beendet', KL_NOTIFY);
                break;
        }
        return [];
    }

    /**
     * Schließt eine einzelne Zone passend zum AKTUELLEN Zustand. Wird vom
     * "close_zone"-Warteschlangenschritt zur Ausführungszeit aufgerufen
     * (manuelles Ausschalten und Ablauf der manuellen Dauer laufen beide
     * über diesen Schritt). Ist die Zone inzwischen bereits geschlossen,
     * passiert nichts. Ist es die letzte noch offene Zone, wird zusätzlich
     * die Pumpe geordnet abgeschaltet (Pumpe aus -> Verfahrzeit -> Ventil zu).
     */
    private function autoCloseSteps(int $idx): array
    {
        $open = $this->openList();
        if (!in_array($idx, $open, true)) {
            return [];
        }
        $zones = $this->logicalZones();
        $name = $zones[$idx]['name'] ?? ('Zone ' . $idx);

        if (count($open) === 1) {
            // Letzte offene Zone: Pumpe-aus wird bewusst immer gesendet
            // (siehe shutdownSteps()), unabhängig vom internen PumpOnSince-Merker.
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
     * Das Pumpe-aus-Kommando wird bewusst IMMER gesendet (nicht nur, wenn
     * der interne "PumpOnSince"-Merker die Pumpe als an führt) – so bleibt
     * "Alles stoppen" auch dann ein zuverlässiger Notausschalter, wenn
     * dieser Merker durch einen unterbrochenen Vorgang oder ein verlorenes
     * KNX-Telegramm einmal nicht mit der Realität übereinstimmt.
     * Ist laut interner Buchführung gar nichts aktiv, wird eine verkürzte
     * Sicherheitsvariante erzeugt (nur Pumpe-aus mit kurzer Wartezeit, kein
     * irreführender "Stoppe..."-Status, keine volle Verfahrzeit-Pause) –
     * relevant z. B. beim Sequenzstart aus dem Leerlauf.
     */
    private function shutdownSteps(): array
    {
        $open = $this->openList();

        if (count($open) === 0 && $this->ReadAttributeInteger('PumpOnSince') === 0) {
            // Leerlauf: reines Sicherheits-Aus für die Pumpe (falls sie durch
            // einen Status-Desync real doch laufen sollte), ohne lange Pause.
            return [['cmd' => 'pump_off', 'post' => 2]];
        }

        $steps = [];
        $maxTravel = 7;
        foreach ($open as $i) {
            $maxTravel = max($maxTravel, $this->travel($i));
        }
        $steps[] = ['cmd' => 'status', 'param' => 'Stoppe laufende Bewässerung …'];
        $steps[] = ['cmd' => 'pump_off', 'post' => $maxTravel];

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
     * $member = null  -> alle Teilventile (Normalfall bei einfachen Zonen).
     * $member = Index -> nur dieses eine Teilventil (für die "Rasen"-Kette,
     * die ihre Teilventile einzeln über den Überlapp-Übergang schaltet,
     * siehe lawnChainSteps()).
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
        $anyNewlyOpened = false;
        foreach ($targets as $t) {
            if (!isset($valves[$t])) {
                continue;
            }
            $this->knxSwitch((int)$valves[$t]['instanceID'], $state);
            if ($state) {
                if (!in_array($t, $openMembers, true)) {
                    $openMembers[] = $t;
                    $anyNewlyOpened = true;
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
        if ($anyNewlyOpened) {
            // Steuert den Wasserverbrauchs-Sampling-Takt: 1 s für die ersten
            // 20 s nach diesem Ventil-Ereignis, danach 10 s (siehe SampleWater()).
            $this->noteValveOpened();
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
            // WICHTIG: Der Einschaltbefehl wird immer gesendet, unabhängig
            // vom internen "PumpOnSince"-Merker. Das Senden ist bei einer
            // bereits laufenden Pumpe unschädlich (Telegramm mit Wert true
            // an eine Pumpe, die schon an ist), verhindert aber, dass ein
            // verfälschter interner Merker (z. B. nach einem unterbrochenen
            // Vorgang oder einem nicht angekommenen KNX-Telegramm) dazu
            // führt, dass die Pumpe bei der nächsten Zone gar nicht mehr
            // eingeschaltet wird.
            $this->LogMessage('Pumpe AN (offene Zonen: ' . implode(',', $this->openList()) . ')', KL_NOTIFY);
            $this->knxSwitch($instanceID, true);
            if ($this->ReadAttributeInteger('PumpOnSince') === 0) {
                $this->WriteAttributeInteger('PumpOnSince', time());
                // Neue Bewässerungs-Session (Sequenz oder manuelle Steuerung)
                // beginnt: Verbrauchssumme dieser Session auf 0 zurücksetzen,
                // damit "Wasserverbrauch letzte Laufzeit" nur diesen Lauf zeigt.
                $this->WriteAttributeFloat('WaterRunAccum', 0);
                $this->WriteAttributeInteger('WaterLastSample', 0);
                $this->updateWaterDisplay();
                // Für alle bereits offenen Zonen beginnt jetzt die tatsächliche Bewässerung
                foreach ($this->openList() as $i) {
                    $this->startZoneRuntime($i);
                }
            }
        } else {
            $this->LogMessage('Pumpe AUS (offene Zonen: ' . implode(',', $this->openList()) . ')', KL_NOTIFY);
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
        if ($this->logicalZonesCache !== null) {
            return $this->logicalZonesCache;
        }

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

        $this->logicalZonesCache = array_values($logical);
        return $this->logicalZonesCache;
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

    // ------------------------------------------------------------------
    // Wasserverbrauch: EINE Summe für den aktuellen/letzten Lauf (Sequenz
    // oder manuelle Aktion, über alle daran beteiligten Kreise) und EINE
    // Gesamtsumme über alle Läufe. Berechnet aus Durchfluss (l/min) × Zeit
    // über einen eigenen, adaptiven Timer ("WaterSample"): läuft ein
    // Ventil-Öffnen-Ereignis weniger als 20 s zurück, wird sekündlich
    // gesampelt, sonst alle 10 s. Das gilt gleichermaßen für manuell
    // geöffnete Zonen und für Zonen innerhalb einer Automatik-Sequenz
    // (inkl. "Rasen"-Kette), da jedes Ventil-Öffnen (auch beim
    // Sequenz-Übergang) den Timer erneut auf den 1-Sekunden-Takt setzt.
    // ------------------------------------------------------------------

    /**
     * Timer-Callback: nimmt bei Bedarf eine Verbrauchs-Probe (Durchfluss ×
     * seit der letzten Probe vergangene Zeit) und bestimmt den nächsten
     * Timer-Takt selbst (1 s, solange ein Ventil-Ereignis < 20 s zurückliegt,
     * sonst 10 s; 0/aus, sobald keine Zone mehr offen ist).
     */
    public function SampleWater(): void
    {
        $open = $this->openList();
        if (count($open) === 0) {
            $this->SetTimerInterval('WaterSample', 0);
            return;
        }

        $now = time();
        $lastSample = $this->ReadAttributeInteger('WaterLastSample');
        $interval = $lastSample > 0 ? ($now - $lastSample) : 0;

        if ($interval > 0) {
            $flowID = $this->ReadPropertyInteger('FlowSensorID');
            if ($flowID > 0 && IPS_VariableExists($flowID)) {
                $flowLPerMin = (float)GetValue($flowID);
                if ($flowLPerMin > 0) {
                    $liters = ($flowLPerMin / 60) * $interval;
                    $this->WriteAttributeFloat('WaterRunAccum', $this->ReadAttributeFloat('WaterRunAccum') + $liters);
                    $this->WriteAttributeFloat('WaterGlobalTotal', $this->ReadAttributeFloat('WaterGlobalTotal') + $liters);
                    $this->updateWaterDisplay();
                }
            }
        }
        $this->WriteAttributeInteger('WaterLastSample', $now);

        // Takt selbst bestimmen: In den ersten 20 s nach einem Ventil-Öffnen
        // schwankt der Durchfluss noch (Einschwingphase des Motorkugelhahns),
        // daher sekündlich messen, um den schwankenden Verbrauch genau zu
        // erfassen. Danach ist der Durchfluss stabil -> 10-s-Takt genügt und
        // spart Rechenlast. Jede Probe rechnet Durchfluss × tatsächlich
        // vergangene Zeit, beide Takte liefern also dieselbe Summe.
        $sinceValveEvent = $now - $this->ReadAttributeInteger('WaterLastValveEvent');
        $this->SetTimerInterval('WaterSample', ($sinceValveEvent < 20 ? 1 : 10) * 1000);
    }

    /**
     * Wird bei jedem Ventil-Öffnen aufgerufen (siehe setValve()): merkt sich
     * den Zeitpunkt (steuert den 1s/10s-Takt von SampleWater) und startet
     * den Sampling-Timer, falls er noch nicht läuft.
     */
    private function noteValveOpened(): void
    {
        $this->WriteAttributeInteger('WaterLastValveEvent', time());
        $this->SetTimerInterval('WaterSample', 1000);
    }

    private function updateWaterDisplay(): void
    {
        $lastID = $this->statVarID('WaterLastRun');
        if ($lastID > 0) {
            SetValue($lastID, round($this->ReadAttributeFloat('WaterRunAccum'), 2));
        }
        $totalID = $this->statVarID('WaterTotal');
        if ($totalID > 0) {
            SetValue($totalID, round($this->ReadAttributeFloat('WaterGlobalTotal'), 2));
        }
    }

    /**
     * Spiegelt die optional verknüpften Sensor-/Rückmeldevariablen
     * (Pumpenstatus, Wasserdruck, Durchfluss) in die eigenen
     * Anzeigevariablen. Ist nichts konfiguriert oder die verknüpfte
     * Variable nicht (mehr) vorhanden, bleibt der zuletzt bekannte Wert
     * stehen. Bei der Pumpen-Rückmeldung wird zusätzlich mit dem intern
     * angenommenen Zustand (PumpOnSince) verglichen und eine Abweichung
     * im Meldungsfenster protokolliert – das erleichtert die Diagnose bei
     * einem Status-Desync (siehe Abschnitt „Pumpen-Status" in der README).
     */
    private function updateSensorDisplays(): void
    {
        $pumpStatusID = $this->ReadPropertyInteger('PumpStatusID');
        if ($pumpStatusID > 0 && IPS_VariableExists($pumpStatusID)) {
            $actual = (bool)GetValue($pumpStatusID);
            $this->SetValue('PumpStatus', $actual);

            $assumed = $this->ReadAttributeInteger('PumpOnSince') > 0;
            // Nur beim WECHSEL des Abweichungszustands loggen – sonst würde
            // die Warnung während normaler kurzer Übergangsfenster (z. B.
            // KNX-Rückmeldung trifft erst Sekunden nach dem Schaltbefehl
            // ein) alle 10 Sekunden wiederholt.
            $mismatch = $actual !== $assumed;
            $lastLogged = $this->GetBuffer('PumpMismatch') === '1';
            if ($mismatch !== $lastLogged) {
                $this->SetBuffer('PumpMismatch', $mismatch ? '1' : '0');
                if ($mismatch) {
                    $this->LogMessage(
                        'Pumpen-Rückmeldung (' . ($actual ? 'an' : 'aus') . ') weicht vom intern angenommenen Zustand (' . ($assumed ? 'an' : 'aus') . ') ab',
                        KL_WARNING
                    );
                }
            }
        }

        $pressureID = $this->ReadPropertyInteger('PressureSensorID');
        if ($pressureID > 0 && IPS_VariableExists($pressureID)) {
            $this->SetValue('Pressure', (float)GetValue($pressureID));
        }

        $flowID = $this->ReadPropertyInteger('FlowSensorID');
        if ($flowID > 0 && IPS_VariableExists($flowID)) {
            $this->SetValue('Flow', (float)GetValue($flowID));
        }
    }

    /**
     * Ermittelt die verbleibende Zeit bis zum vollständigen Abschluss der
     * aktuell laufenden Aktivität und schreibt sie (in Minuten, aufgerundet)
     * in die Anzeigevariable "Remaining". Deckt beide Fälle ab:
     * - Automatik-Sequenz bzw. "Rasen"-Kette: Summe der noch ausstehenden
     *   Wartezeiten in der seriellen Warteschlange (queueRemainingSeconds).
     * - Manuell geöffnete einfache Zone(n): Ziel-Dauer minus bereits
     *   verstrichene Bewässerungszeit (ZoneManualTarget/zoneRunSince).
     * Da beide Mechanismen sich gegenseitig ausschließen (siehe Exklusivität
     * von "Rasen" bzw. Sperre der manuellen Bedienung während einer
     * Sequenz), wird einfach das Maximum beider Quellen verwendet.
     */
    private function updateRemainingDisplay(): void
    {
        $remaining = $this->queueRemainingSeconds();

        $targets = json_decode($this->ReadAttributeString('ZoneManualTarget'), true) ?: [];
        foreach ($targets as $idxStr => $targetSeconds) {
            $since = $this->zoneRunSince((int)$idxStr);
            $zoneRemaining = $since > 0
                ? max(0, (int)$targetSeconds - (time() - $since))
                : (int)$targetSeconds; // noch nicht aktiv wässernd (Verfahrzeit läuft) -> volle Dauer als Rest
            $remaining = max($remaining, $zoneRemaining);
        }

        $this->SetValue('Remaining', (int)ceil(max(0, $remaining) / 60));
    }

    /**
     * Summe aller noch ausstehenden Wartezeiten in der seriellen
     * Warteschlange: die aktuell laufende Wartezeit (falls vorhanden) plus
     * alle "post"-Werte der noch nicht ausgeführten Schritte.
     */
    private function queueRemainingSeconds(): int
    {
        $remaining = 0;

        $wait = (int)$this->GetBuffer('WaitUntil');
        if ($wait > time()) {
            $remaining += $wait - time();
        }

        $queue = json_decode($this->GetBuffer('Queue'), true) ?: [];
        foreach ($queue as $step) {
            $remaining += (int)($step['post'] ?? 0);
        }

        return $remaining;
    }

    // ------------------------------------------------------------------
    // Manuelle Bewässerungsdauer je Zone: unabhängig von der seriellen
    // Schalt-Warteschlange verwaltet, damit mehrere manuell geöffnete Zonen
    // ihre jeweils eigene Dauer parallel abwarten können (siehe manualZone()).
    // ------------------------------------------------------------------

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
     * und reiht dann einen "close_zone"-Schritt ein – die Entscheidung, ob
     * die Pumpe mit ausgeschaltet werden muss (letzte offene Zone), fällt
     * erst bei dessen Ausführung anhand des dann aktuellen Zustands.
     * Toleranz: bis zu einem Prüfintervall (10 s), für Bewässerungsdauern
     * im Minutenbereich unkritisch.
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
            $this->LogMessage('Manuelle Dauer abgelaufen für Zone-Index ' . $idx . ' (seit ' . (time() - $since) . 's, Ziel war ' . $targetSeconds . 's) – schließe automatisch', KL_NOTIFY);
            $this->enqueue([['cmd' => 'close_zone', 'zone' => $idx]]);
        }
    }

    /**
     * Frei wählbarer Anzeigename einer Sequenz (Fallback: "Sequenz N").
     */
    private function seqName(int $seq): string
    {
        $name = trim($this->ReadPropertyString('Seq' . $seq . 'Name'));
        return $name !== '' ? $name : ('Sequenz ' . $seq);
    }

    /**
     * Pumpen-Watchdog (läuft im 10-s-Takt aus CheckSchedule): Solange laut
     * interner Buchführung bewässert wird (Pumpe an + mindestens eine Zone
     * offen), wird der Pumpen-Einschaltbefehl abgesichert:
     * - Meldet die (optionale) Rückmeldevariable "aus", obwohl die Pumpe
     *   seit mehr als 15 s laufen müsste, wird der Einschaltbefehl sofort
     *   erneut gesendet und eine Warnung protokolliert.
     * - Unabhängig davon wird der Einschaltbefehl vorsorglich einmal pro
     *   Minute wiederholt (unschädlich bei laufender Pumpe, fängt aber ein
     *   verlorenes KNX-Telegramm ab, auch ohne Rückmeldevariable).
     */
    private function pumpWatchdog(): void
    {
        $since = $this->ReadAttributeInteger('PumpOnSince');
        if ($since === 0 || count($this->openList()) === 0) {
            $this->SetBuffer('PumpAssert', '');
            return;
        }

        $feedbackOff = false;
        $fbID = $this->ReadPropertyInteger('PumpStatusID');
        if ($fbID > 0 && IPS_VariableExists($fbID) && (time() - $since) > 15) {
            $feedbackOff = !(bool)GetValue($fbID);
        }

        $lastAssert = (int)$this->GetBuffer('PumpAssert');
        if ($feedbackOff || (time() - $lastAssert) >= 60) {
            if ($feedbackOff) {
                $this->LogMessage('Watchdog: Pumpe sollte laufen, Rückmeldung meldet AUS – sende Einschaltbefehl erneut', KL_WARNING);
            }
            $this->knxSwitch($this->ReadPropertyInteger('PumpInstanceID'), true);
            $this->SetBuffer('PumpAssert', (string)time());
        }
    }

    /**
     * Aktualisiert für jede logische Zone die Anzeige "Nächste Laufzeit":
     * Aus Intervall, letztem Lauf und den Startzeiten beider (aktivierter)
     * Sequenzen wird der früheste kommende Termin berechnet und lesbar
     * formatiert (z. B. "heute, 19:00 (Abends)" oder "Freitag, 06:00
     * (Morgens)"). Zonen ohne aktiven Sequenz-Eintrag zeigen "–".
     * Bodenfeuchte-Sensoren werden bewusst nicht einbezogen (nicht
     * vorhersagbar); der Termin ist also "spätestens dann fällig".
     */
    private function updateNextRunDisplays(): void
    {
        $zones = $this->logicalZones();
        $lastRun = json_decode($this->ReadAttributeString('LastRun'), true) ?: [];

        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            $varID = $this->catVarID('PlanCategory', 'NextRunZ' . $i);
            if ($varID <= 0) {
                continue;
            }
            $text = isset($zones[$i]) ? $this->computeNextRun($i, $lastRun) : '–';
            if (GetValue($varID) !== $text) {
                SetValue($varID, $text);
            }
        }
    }

    private function computeNextRun(int $idx, array $lastRun): string
    {
        $bestTs = null;
        $bestSeq = 0;

        foreach ([1, 2] as $seq) {
            if (!$this->GetValue('Auto' . $seq)) {
                continue;
            }
            $startTs = $this->GetValue('StartTime' . $seq);
            if ($startTs <= 0) {
                continue;
            }
            $startHM = date('H:i', $startTs);

            $rows = json_decode($this->ReadPropertyString('Sequence' . $seq), true) ?: [];
            foreach ($rows as $row) {
                if (empty($row['Active']) || (int)($row['Zone'] ?? -1) !== $idx) {
                    continue;
                }
                $interval = max(1, (int)($row['Interval'] ?? 1));
                $key = 's' . $seq . 'z' . $idx;
                $last = isset($lastRun[$key]) ? (int)strtotime((string)$lastRun[$key]) : 0;

                // Fälligkeitstag: letzter Lauf + Intervall, frühestens heute
                $dueDay = $last > 0 ? strtotime('+' . $interval . ' days', $last) : strtotime('today');
                if ($dueDay < strtotime('today')) {
                    $dueDay = strtotime('today');
                }
                $candidate = strtotime(date('Y-m-d', $dueDay) . ' ' . $startHM);
                if ($candidate <= time()) {
                    // Heutiger Termin bereits vorbei (oder gerade gelaufen,
                    // dann greift beim nächsten Tick lastRun=heute) ->
                    // nächster möglicher Termin ist morgen zur Startzeit.
                    $candidate = strtotime('+1 day', $candidate);
                }
                if ($bestTs === null || $candidate < $bestTs) {
                    $bestTs = $candidate;
                    $bestSeq = $seq;
                }
            }
        }

        if ($bestTs === null) {
            return '–';
        }

        $dayLabel = match (date('Y-m-d', $bestTs)) {
            date('Y-m-d')                          => 'heute',
            date('Y-m-d', strtotime('+1 day'))     => 'morgen',
            default                                => ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][(int)date('w', $bestTs)]
        };

        return $dayLabel . ', ' . date('H:i', $bestTs) . ' (' . $this->seqName($bestSeq) . ')';
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
                    'name'    => 'PumpStatusID',
                    'caption' => 'Pumpenstatus-Rückmeldung (optional, vorhandene Variable)'
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
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Seq1Name',
                    'caption' => 'Name Sequenz 1 (z. B. "Morgens")'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Seq2Name',
                    'caption' => 'Name Sequenz 2 (z. B. "Abends")'
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
                    'caption' => $this->seqName(1) . ' – Reihenfolge = Bewässerungsreihenfolge',
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
                    'caption' => $this->seqName(2) . ' – Reihenfolge = Bewässerungsreihenfolge',
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
                        ['type' => 'Button', 'caption' => $this->seqName(1) . ' jetzt starten', 'onClick' => 'BWS_StartSequence($id, 1);'],
                        ['type' => 'Button', 'caption' => $this->seqName(2) . ' jetzt starten', 'onClick' => 'BWS_StartSequence($id, 2);'],
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
