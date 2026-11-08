<?php

declare(strict_types=1);

/**
 * Bewässerungssteuerung für IP-Symcon 9 (PHP 8.5) – KNX-Variante
 *
 * Schaltschema
 *   Einschalten:  Ventil auf -> Verfahrzeit -> Pumpe an
 *   Zonenwechsel: nächstes Ventil auf -> Überlapp (mindestens Verfahrzeit) -> vorheriges Ventil zu
 *   Ausschalten:  Pumpe aus -> Verfahrzeit -> Ventil zu
 *   Höchstens zwei Ventile sind gleichzeitig offen, die Pumpe läuft nie ohne offenes Ventil.
 *
 * Wartungsmodus
 *   Pumpe gesperrt, Kugelhähne einzeln schaltbar (z. B. Leitungen ausblasen).
 *
 * Ablaufsteuerung
 *   Jeder Schaltschritt steht mit absolutem Fälligkeitszeitpunkt in einer
 *   persistenten Warteschlange (Attribut "Plan"), die der Timer "Queue"
 *   abarbeitet. Offene Ventile werden im Attribut "Valves" geführt. Startet
 *   IP-Symcon neu, während etwas läuft, werden geplante Schritte verworfen und
 *   die Anlage nach KR_READY geordnet abgeschaltet.
 *
 * Zonen
 *   Jede Zeile der Zonenliste ist eine physische Zone mit eigenem
 *   Motorkugelhahn. Alle als "Rasen" markierten Zeilen bilden eine logische
 *   Zone, deren Teilventile nacheinander laufen; sie bewässert exklusiv.
 *   Sequenzzeilen und der Intervall-Speicher verweisen über die
 *   Ventil-Instanz (Rasen: -1) auf eine Zone, nicht über ihre Position.
 */
class Bewaesserungssteuerung extends IPSModule
{
    private const MAX_ZONES = 12;
    private const MAX_SEQUENCES = 4;
    private const MAX_OPEN = 2;
    private const LAWN = -1;
    private const DEFAULT_TRAVEL = 7;
    private const TICK = 10;                // s, Takt des Timers "Schedule"
    private const START_WINDOW = 900;       // s, so lange wird ein verpasster Start nachgeholt
    private const RECOVERY_DELAY = 10;      // s nach KR_READY, damit das KNX-Gateway verbunden ist
    private const PUMP_GRACE = 20;          // s, bis eine abweichende Pumpen-Rückmeldung als Fehler gilt
    private const PUMP_REASSERT = 60;       // s, Wiederholung des Einschaltbefehls bei laufender Pumpe
    private const CLOSE_RETRY = 5;          // s bis zum zweiten Versuch, ein Ventil zu schließen
    private const LEGACY_REF_LIMIT = 1000;  // Sequenzzeilen mit Zone < 1000 enthalten eine Listenposition

    private ?array $zoneCache = null;

    // ======================================================================
    // Lebenszyklus
    // ======================================================================

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PumpInstanceID', 0);
        $this->RegisterPropertyInteger('PressureSensorID', 0);
        $this->RegisterPropertyInteger('FlowSensorID', 0);
        $this->RegisterPropertyInteger('PumpStatusID', 0);
        $this->RegisterPropertyString('Zones', json_encode($this->defaultZones()));
        $this->RegisterPropertyString('LawnName', 'Rasen');
        for ($s = 1; $s <= self::MAX_SEQUENCES; $s++) {
            $this->RegisterPropertyString('Sequence' . $s, '[]');
            $this->RegisterPropertyString('Seq' . $s . 'Name', 'Sequenz ' . $s);
        }
        $this->RegisterPropertyInteger('MaxParallel', 2);
        $this->RegisterPropertyInteger('OverlapTime', 10);
        $this->RegisterPropertyBoolean('WUEnabled', false);
        $this->RegisterPropertyString('WUApiKey', '');
        $this->RegisterPropertyString('WUStationID', '');
        $this->RegisterPropertyFloat('WURainThreshold', 0.0);

        // Statistik
        $this->RegisterAttributeInteger('PumpOnSince', 0);
        $this->RegisterAttributeInteger('DayAccum', 0);
        $this->RegisterAttributeInteger('TotalAccum', 0);
        $this->RegisterAttributeString('DayDate', '');
        $this->RegisterAttributeFloat('WaterRunAccum', 0);
        $this->RegisterAttributeFloat('WaterGlobalTotal', 0);
        $this->RegisterAttributeInteger('WaterLastTs', 0);

        // Ablaufzustand
        $this->RegisterAttributeString('Plan', '[]');      // [{at, op, src, ...}] nach Fälligkeit sortiert
        $this->RegisterAttributeString('Valves', '{}');    // {Ventil-Instanz: Zeitpunkt "vollständig offen"}
        $this->RegisterAttributeString('Manual', '[]');    // manuell belegte Zonen (Referenzen)
        $this->RegisterAttributeString('LastRun', '{}');   // {"s<Seq>k<Ref>": "Y-m-d"}
        $this->RegisterAttributeString('Started', '{}');   // {Seq: "Y-m-d H:i"} bereits gestarteter Termin
        $this->RegisterAttributeBoolean('RecoveryPending', false);
        $this->RegisterAttributeBoolean('ZoneRefsMigrated', false);

        $this->ensureProfile('BWS.Minutes', VARIABLETYPE_INTEGER, ' min', 0, 'Clock');
        IPS_SetVariableProfileValues('BWS.Minutes', 0, 240, 1);
        $this->ensureProfile('BWS.Hours', VARIABLETYPE_FLOAT, ' h', 2, 'Clock');
        $this->ensureProfile('BWS.Cycles', VARIABLETYPE_INTEGER, ' Zyklen', 0, 'Repeat');
        $this->ensureProfile('BWS.Pressure', VARIABLETYPE_FLOAT, ' bar', 2);
        $this->ensureProfile('BWS.Flow', VARIABLETYPE_FLOAT, ' l/min', 1);
        $this->ensureProfile('BWS.Liters', VARIABLETYPE_FLOAT, ' l', 2);

        $this->SetVisualizationType(1); // eigene Kachel (HTML-SDK), siehe visualization.html
        $this->RegisterTimer('Queue', 0, 'BWS_ProcessQueue($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Schedule', 0, 'BWS_CheckSchedule($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function Destroy()
    {
        parent::Destroy();
        if (!IPS_InstanceExists($this->InstanceID) && IPS_VariableProfileExists($this->seqProfile())) {
            IPS_DeleteVariableProfile($this->seqProfile());
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->zoneCache = null;

        if ($this->migrateZoneReferences()) {
            return; // IPS_ApplyChanges wurde mit der migrierten Konfiguration erneut ausgeführt
        }

        $this->maintainVariables();
        $this->watchSensors();
        $this->validateConfiguration();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->prepareRecovery();
        } elseif ($this->isBusy()) {
            $this->withLock(fn () => $this->shutdownLocked('Konfiguration geändert – Bewässerung beendet.'));
        }

        $this->SetTimerInterval('Schedule', self::TICK * 1000);
        $this->armQueueTimer();
        $this->mirrorSensors();
        $this->updateRuntimeDisplay();
        $this->updateWaterDisplay();
        $this->updateRemainingDisplay();
        $this->updateNextRunDisplays(true);
        $this->pushVisualization();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->recoverAfterStart();
        } elseif ($Message === VM_UPDATE) {
            $this->withLock(fn () => $this->onSensorUpdate((int)$SenderID, $Data));
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $this->handleAction((string)$Ident, $Value);
        $this->pushVisualization();
    }

    private function handleAction(string $Ident, $Value): void
    {
        switch (true) {
            case $Ident === 'Active':
                $this->SetValue('Active', (bool)$Value);
                if (!$Value && !$this->maintenance()) {
                    $this->StopAll();
                }
                return;

            case $Ident === 'Maintenance':
                $this->SetMaintenance((bool)$Value);
                return;

            case $Ident === 'SeqControl':
                if ((int)$Value === 0) {
                    $this->StopAll();
                } else {
                    $this->StartSequence((int)$Value);
                }
                return;

            case preg_match('/^(Auto|StartTime)([1-4])$/', $Ident, $m) === 1:
                if ($m[1] === 'StartTime' && is_string($Value) && $this->isTime($Value)) {
                    $Value = (int)strtotime($Value, $this->now()); // Kachel sendet "HH:MM"
                }
                $this->SetValue($Ident, $m[1] === 'Auto' ? (bool)$Value : (int)$Value);
                $this->withLock(fn () => $this->skipPassedSlot((int)$m[2]));
                $this->updateSequenceOrder();
                $this->updateNextRunDisplays(true);
                return;

            case preg_match('/^ManualZ(\d+)$/', $Ident, $m) === 1:
                $idx = (int)$m[1];
                $this->report($this->withLock(fn () => $Value ? $this->startManualLocked($idx) : $this->stopManualLocked($idx)));
                return;

            case preg_match('/^ServiceV(\d+)$/', $Ident, $m) === 1:
                $phys = (int)$m[1];
                $this->report($this->withLock(fn () => $this->serviceValveLocked($phys, (bool)$Value)));
                return;

            case preg_match('/^ManualDurationZ\d+$/', $Ident) === 1:
                $id = $this->catVarID('ManualDurCategory', $Ident);
                if ($id > 0) {
                    SetValue($id, min(240, max(1, (int)$Value)));
                }
                return;
        }
        throw new Exception('Unbekannte Aktion: ' . $Ident);
    }

    // ======================================================================
    // Öffentliche Funktionen (BWS_*)
    // ======================================================================

    /** Startet eine Sequenz mit den heute fälligen Zonen. */
    public function StartSequence(int $Sequence): void
    {
        if ($Sequence < 1 || $Sequence > self::MAX_SEQUENCES) {
            return;
        }
        if (!$this->GetValue('Active')) {
            $this->SetValue('Status', 'Master-Schalter aus – Start abgebrochen');
            return;
        }
        if ($this->maintenance()) {
            $this->SetValue('Status', 'Wartungsmodus aktiv – Sequenz nicht gestartet');
            return;
        }
        // Netzwerkabfrage bewusst außerhalb der Sperre
        if ($this->wuRainBlocks($reason)) {
            $this->SetValue('Status', $this->seqName($Sequence) . ': Start wegen Regen unterbunden');
            $this->LogMessage($this->seqName($Sequence) . ' nicht gestartet – ' . $reason, KL_NOTIFY);
            return;
        }
        $this->report($this->withLock(fn () => $this->startSequenceLocked($Sequence) ?? ''));
    }

    /** Stoppt alles: Pumpe sofort aus, nach der Verfahrzeit alle offenen Ventile zu. */
    public function StopAll(): void
    {
        $this->report($this->withLock(fn () => $this->shutdownLocked('Bereit') ?? ''));
    }

    /**
     * Wartungsmodus, z. B. zum Ausblasen der Leitungen: Bewässerung wird
     * geordnet beendet, die Pumpe bleibt gesperrt, jeder Kugelhahn lässt
     * sich einzeln schalten. Beim Verlassen schließen alle Kugelhähne.
     */
    public function SetMaintenance(bool $Enabled): void
    {
        $this->report($this->withLock(fn () => $this->setMaintenanceLocked($Enabled) ?? ''));
    }

    /** Setzt Pumpenlaufzeit, Gesamt-Wasserverbrauch und Zyklenzähler zurück. */
    public function ResetCounters(): void
    {
        $this->report($this->withLock(function (): string {
            $this->WriteAttributeInteger('DayAccum', 0);
            $this->WriteAttributeInteger('TotalAccum', 0);
            if ($this->ReadAttributeInteger('PumpOnSince') > 0) {
                $this->WriteAttributeInteger('PumpOnSince', $this->now());
            }
            $this->WriteAttributeFloat('WaterGlobalTotal', 0);
            for ($i = 0; $i < self::MAX_ZONES; $i++) {
                $this->setVarIfChanged($this->statVarID('CyclesP' . $i), 0);
            }
            $this->updateRuntimeDisplay();
            $this->updateWaterDisplay();
            return '';
        }));
    }

    /** Timer (10 s): Tageswechsel, Überwachung, Anzeigen, Automatikstart. */
    public function CheckSchedule(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY || $this->ReadAttributeBoolean('RecoveryPending')) {
            return;
        }
        $due = $this->withLock(function (): int {
            $this->rollDay();
            $this->processLocked();
            $this->superviseLocked();
            $this->accumulateWater();
            $this->updateRuntimeDisplay();
            $this->updateRemainingDisplay();
            $this->updateNextRunDisplays(false);
            return $this->claimDueSequence();
        });
        if ($due > 0) {
            $this->StartSequence($due);
        }
    }

    /** Timer: führt fällige Schritte der Warteschlange aus. */
    public function ProcessQueue(): void
    {
        $this->withLock(fn () => $this->processLocked());
    }

    /** Button "Verbindung testen" der Regensperre. */
    public function WUTestConnection(): void
    {
        $apiKey = trim($this->ReadPropertyString('WUApiKey'));
        $station = trim($this->ReadPropertyString('WUStationID'));
        if ($apiKey === '' || $station === '') {
            echo 'Bitte zuerst API-Key und Station-ID eintragen und übernehmen.';
            return;
        }
        $rate = $this->wuPrecipRate($station, $apiKey, $err);
        if ($rate === null) {
            echo 'Abruf fehlgeschlagen: ' . $err;
            return;
        }
        echo 'Verbindung ok. Aktuelle Regenrate an Station ' . $station . ': ' . $rate . ' mm/h'
            . ($rate > 0 ? ' (es regnet – ein Sequenzstart würde blockiert)' : ' (kein Regen)');
    }

    /** Zeitquelle; in Tests überschreibbar. */
    protected function now(): int
    {
        return time();
    }

    // ======================================================================
    // Sequenzen
    // ======================================================================

    private function startSequenceLocked(int $seq): void
    {
        if (!$this->GetValue('Active') || $this->maintenance()) {
            return;
        }
        $label = $this->seqName($seq);
        [$stages, $count, $skipped] = $this->sequenceStages($seq);
        if ($count === 0) {
            $msg = $label . ': heute keine Zone fällig' . ($skipped ? ' (übersprungen: ' . implode(', ', $skipped) . ')' : '');
            $this->setIfChanged('Status', $msg);
            $this->LogMessage($msg, KL_NOTIFY);
            return;
        }

        // Läuft noch etwas, wird es zuerst geordnet beendet.
        $now = $this->now();
        $ops = [];
        $t = $now;
        $open = array_keys($this->openValves());
        if ($open) {
            $t = $this->planStop($ops, $open, $now);
        }
        $ops = array_merge($ops, $this->compileStages($stages, $t, $label . ': ', 'Bereit', $seq));

        $this->writeJson('Plan', []);
        $this->writeJson('Manual', []);
        $this->enqueue($ops, 'seq');
        $this->SetValue('SeqControl', $seq);
        $this->LogMessage($label . ' gestartet (' . $count . ' Zonen' . ($skipped ? '; übersprungen: ' . implode(', ', $skipped) : '') . ')', KL_NOTIFY);
        $this->processLocked();
    }

    /**
     * Ermittelt die fälligen Zonen einer Sequenz und bildet daraus die
     * Bewässerungsabschnitte ("Stages"). Ein Abschnitt enthält 1–2 Ventile,
     * die gemeinsam bewässern. "break" = vor dem Abschnitt wird die Pumpe
     * abgeschaltet (Übergang von bzw. zu Rasen).
     *
     * @return array{0: array, 1: int, 2: string[]}
     */
    private function sequenceStages(int $seq): array
    {
        $zones = $this->zones();
        $lastRun = $this->readJson('LastRun');
        $today = strtotime(date('Y-m-d', $this->now()));
        $due = [];
        $skipped = [];

        foreach ($this->sequenceRows($seq) as $row) {
            if (empty($row['Active'])) {
                continue;
            }
            $ref = (int)($row['Zone'] ?? 0);
            $idx = $this->findZone($ref);
            if ($idx === null || !$zones[$idx]['usable']) {
                $skipped[] = ($idx === null ? 'unbekannte Zone' : $zones[$idx]['name']) . ' (nicht konfiguriert)';
                continue;
            }
            $zone = $zones[$idx];
            $key = $this->lastRunKey($seq, $ref);
            $last = isset($lastRun[$key]) ? (int)strtotime((string)$lastRun[$key]) : 0;
            if ($last > 0 && (int)round(($today - $last) / 86400) < max(1, (int)($row['Interval'] ?? 1))) {
                continue;
            }
            if (!$this->zoneNeedsWater($zone)) {
                $skipped[] = $zone['name'] . ' (Boden feucht genug)';
                continue;
            }
            $minutes = (int)($row['Duration'] ?? 0);
            $due[] = [
                'zone' => $zone,
                'dur'  => max(1, $minutes > 0 ? $minutes : $zone['duration']) * 60,
                'par'  => !empty($row['Parallel']),
                'key'  => $key
            ];
        }

        // Gruppen: "Parallel" koppelt an die vorherige Zone, Rasen bleibt immer allein.
        $groups = [];
        foreach ($due as $d) {
            $g = count($groups) - 1;
            $canJoin = $d['par'] && $g >= 0 && !$d['zone']['lawn'] && !$groups[$g][0]['zone']['lawn']
                && count($groups[$g]) < $this->maxParallel();
            if ($canJoin) {
                $groups[$g][] = $d;
            } else {
                $groups[] = [$d];
            }
        }

        $stages = [];
        $afterLawn = false;
        foreach ($groups as $group) {
            $first = $group[0];
            if ($first['zone']['lawn']) {
                $members = $first['zone']['valves'];
                foreach ($members as $j => $valve) {
                    $stages[] = [
                        'valves' => [$valve],
                        'dur'    => $first['dur'],
                        'marks'  => $j === count($members) - 1 ? [$first['key']] : [],
                        'label'  => $first['zone']['name'] . ' – Teil ' . ($j + 1) . '/' . count($members),
                        'break'  => $j === 0 && count($stages) > 0
                    ];
                }
                $afterLawn = true;
                continue;
            }
            $stages[] = [
                'valves' => array_merge(...array_map(fn (array $d): array => $d['zone']['valves'], $group)),
                'dur'    => max(array_column($group, 'dur')),
                'marks'  => array_column($group, 'key'),
                'label'  => implode(' + ', array_map(fn (array $d): string => $d['zone']['name'], $group)),
                'break'  => $afterLawn
            ];
            $afterLawn = false;
        }

        return [$stages, count($due), $skipped];
    }

    /**
     * Übersetzt Abschnitte in zeitlich geplante Schaltschritte ab Zeitpunkt $t.
     * Intervall-Markierungen werden erst nach Ablauf der Bewässerungsdauer
     * gesetzt, ein abgebrochener Lauf zählt also nicht als erledigt.
     */
    private function compileStages(array $stages, int $t, string $prefix, string $doneText, int $seq): array
    {
        $ops = [];
        $prev = null;
        $overlap = max(0, $this->ReadPropertyInteger('OverlapTime'));

        foreach ($stages as $stage) {
            $valves = $stage['valves'];
            if ($prev === null || $stage['break']) {
                if ($prev !== null) {
                    $t = $this->planStop($ops, array_column($prev['valves'], 'id'), $t);
                }
                foreach ($valves as $v) {
                    $ops[] = ['at' => $t, 'op' => 'valve', 'id' => $v['id'], 'on' => true];
                }
                $ops[] = ['at' => $t, 'op' => 'status', 'text' => $prefix . 'öffne ' . $stage['label']];
                $t += max(array_column($valves, 'travel'));
                $ops[] = ['at' => $t, 'op' => 'pump', 'on' => true, 'need' => array_column($valves, 'id')];
            } else {
                // Das neue Ventil ist vollständig offen, bevor das alte schließt.
                $ov = max($overlap, $valves[0]['travel']);
                $old = $prev['valves'];
                if (count($old) > 1) {
                    $ops[] = ['at' => $t, 'op' => 'valve', 'id' => $old[0]['id'], 'on' => false];
                }
                $ops[] = ['at' => $t, 'op' => 'valve', 'id' => $valves[0]['id'], 'on' => true];
                $ops[] = ['at' => $t + $ov, 'op' => 'valve', 'id' => $old[count($old) - 1]['id'], 'on' => false];
                if (count($valves) > 1) {
                    $ops[] = ['at' => $t + $ov, 'op' => 'valve', 'id' => $valves[1]['id'], 'on' => true];
                }
                $t += $ov;
            }
            $ops[] = ['at' => $t, 'op' => 'status', 'text' => $prefix . 'bewässere ' . $stage['label']];
            $t += $stage['dur'];
            foreach ($stage['marks'] as $key) {
                $ops[] = ['at' => $t, 'op' => 'mark', 'key' => $key];
            }
            $prev = $stage;
        }

        if ($prev !== null) {
            $ops[] = ['at' => $t, 'op' => 'status', 'text' => $prefix . 'beende'];
            $t = $this->planStop($ops, array_column($prev['valves'], 'id'), $t);
        }
        $ops[] = ['at' => $t, 'op' => 'done', 'text' => $doneText, 'seq' => $seq];
        return $ops;
    }

    /** Plant "Pumpe aus -> Verfahrzeit -> Ventile zu" und liefert den Endzeitpunkt. */
    private function planStop(array &$ops, array $valveIDs, int $t): int
    {
        $ops[] = ['at' => $t, 'op' => 'pump', 'on' => false];
        $end = $t + $this->maxTravel($valveIDs);
        foreach ($valveIDs as $id) {
            $ops[] = ['at' => $end, 'op' => 'valve', 'id' => (int)$id, 'on' => false];
        }
        return $end;
    }

    /**
     * Liefert eine heute fällige, noch nicht gestartete Sequenz und merkt
     * sie als gestartet vor. Ein verpasster Termin (Last, Neustart) wird
     * bis START_WINDOW nachgeholt; eine laufende Sequenz wird nicht
     * unterbrochen, eine manuelle Bewässerung schon. Termine bei
     * ausgeschaltetem Master oder im Wartungsmodus verfallen.
     */
    private function claimDueSequence(): int
    {
        if ((int)$this->GetValue('SeqControl') !== 0) {
            return 0;
        }
        $blocked = !$this->GetValue('Active') || $this->maintenance();
        $now = $this->now();
        $started = $this->readJson('Started');
        $due = [];
        for ($s = 1; $s <= self::MAX_SEQUENCES; $s++) {
            $slot = $this->slotToday($s);
            if ($slot !== null && $now >= $slot && $now - $slot < self::START_WINDOW
                && ($started[$s] ?? '') !== date('Y-m-d H:i', $slot)) {
                $due[$s] = $slot;
            }
        }
        if (!$due) {
            return 0;
        }
        asort($due);
        foreach ($blocked ? $due : array_slice($due, 0, 1, true) as $s => $slot) {
            $started[$s] = date('Y-m-d H:i', $slot);
        }
        $this->writeJson('Started', $started);
        return $blocked ? 0 : (int)array_key_first($due);
    }

    /** Ein bereits verstrichener Termin von heute wird nach einer Änderung nicht nachgeholt. */
    private function skipPassedSlot(int $seq): void
    {
        $slot = $this->slotToday($seq);
        if ($slot !== null && $slot <= $this->now()) {
            $started = $this->readJson('Started');
            $started[$seq] = date('Y-m-d H:i', $slot);
            $this->writeJson('Started', $started);
        }
    }

    /** Heutiger Startzeitpunkt einer aktiven Sequenz oder null. */
    private function slotToday(int $seq): ?int
    {
        if (!$this->GetValue('Auto' . $seq)) {
            return null;
        }
        $ts = (int)$this->GetValue('StartTime' . $seq);
        if ($ts <= 0) {
            return null;
        }
        return (int)strtotime(date('Y-m-d', $this->now()) . ' ' . date('H:i', $ts), $this->now());
    }

    // ======================================================================
    // Manuelle Bedienung
    // ======================================================================

    /** @return string leer = ausgeführt, sonst Grund der Ablehnung */
    private function startManualLocked(int $idx): string
    {
        $zones = $this->zones();
        if (!isset($zones[$idx])) {
            return '';
        }
        $zone = $zones[$idx];
        if ($this->maintenance()) {
            return 'Wartungsmodus aktiv – Kugelhähne unter „Wartung – Kugelhähne“ schalten.';
        }
        if (!$this->GetValue('Active')) {
            return 'Master-Schalter ist aus – Kugelhähne ohne Pumpe lassen sich im Wartungsmodus schalten.';
        }
        if ((int)$this->GetValue('SeqControl') !== 0) {
            return 'Automatik-Sequenz läuft – bitte zuerst stoppen.';
        }
        if (!$zone['usable']) {
            return $zone['name'] . ': kein gültiges Ventil konfiguriert.';
        }

        $manual = $this->readJson('Manual');
        if (in_array($zone['ref'], $manual, true) || $this->zoneIsOpen($zone)) {
            return '';
        }
        // Belegt sind reservierte und noch offene (z. B. gerade schließende) Zonen.
        $occupied = array_unique(array_merge($manual, $this->openZoneRefs()));
        if ($zone['lawn'] && $occupied) {
            return $zone['name'] . ' benötigt den Pumpenkreis exklusiv – bitte zuerst alle anderen Zonen schließen.';
        }
        if (in_array(self::LAWN, $occupied, true)) {
            return 'Aktuell läuft ' . $this->lawnName() . ' – bitte warten, bis die Bewässerung fertig ist.';
        }
        if (count($occupied) >= $this->maxParallel()) {
            return 'Maximal ' . $this->maxParallel() . ' Zonen gleichzeitig erlaubt.';
        }

        $durID = $this->catVarID('ManualDurCategory', 'ManualDurationZ' . $idx);
        $minutes = $durID > 0 ? (int)GetValue($durID) : 0;
        $seconds = max(1, $minutes > 0 ? $minutes : $zone['duration']) * 60;

        $manual[] = $zone['ref'];
        $this->writeJson('Manual', $manual);
        $src = 'm' . $zone['ref'];
        $now = $this->now();

        if ($zone['lawn']) {
            $stages = [];
            foreach ($zone['valves'] as $j => $valve) {
                $stages[] = [
                    'valves' => [$valve], 'dur' => $seconds, 'marks' => [], 'break' => false,
                    'label'  => $zone['name'] . ' – Teil ' . ($j + 1) . '/' . count($zone['valves'])
                ];
            }
            $this->enqueue($this->compileStages($stages, $now, 'Manuell: ', 'Bereit', 0), $src);
        } else {
            $valve = $zone['valves'][0];
            $ready = $now + $valve['travel'];
            $this->enqueue([
                ['at' => $now, 'op' => 'valve', 'id' => $valve['id'], 'on' => true],
                ['at' => $now, 'op' => 'status', 'text' => 'Manuell: öffne ' . $zone['name']],
                ['at' => $ready, 'op' => 'pump', 'on' => true, 'need' => [$valve['id']]],
                ['at' => $ready, 'op' => 'status', 'text' => 'Manuell: ' . $zone['name'] . ' aktiv'],
                ['at' => $ready + $seconds, 'op' => 'close', 'ref' => $zone['ref']]
            ], $src);
        }
        $this->syncZoneSwitches();
        $this->processLocked();
        return '';
    }

    /** @return string leer = ausgeführt, sonst Grund der Ablehnung */
    private function stopManualLocked(int $idx): string
    {
        $zones = $this->zones();
        if (!isset($zones[$idx])) {
            return '';
        }
        $zone = $zones[$idx];
        if ($this->maintenance()) {
            return 'Wartungsmodus aktiv – Kugelhähne unter „Wartung – Kugelhähne“ schalten.';
        }
        if ((int)$this->GetValue('SeqControl') !== 0) {
            return 'Automatik-Sequenz läuft – bitte über die Sequenz-Auswahl stoppen.';
        }
        $this->cancelOps('m' . $zone['ref']);
        $this->releaseManual($zone['ref']);
        $this->closeZoneLocked($zone);
        $this->syncZoneSwitches();
        $this->processLocked();
        return '';
    }

    /**
     * Schließt eine Zone passend zum aktuellen Zustand:
     * letzte offene Zone -> Pumpe aus -> Verfahrzeit -> Ventil zu;
     * sonst Ventil zu, sobald die übrigen offenen Ventile vollständig offen sind.
     */
    private function closeZoneLocked(array $zone): void
    {
        $open = $this->openValves();
        $ids = array_values(array_intersect(array_column($zone['valves'], 'id'), array_keys($open)));
        if (!$ids) {
            return;
        }
        $now = $this->now();
        $others = array_diff_key($open, array_flip($ids));
        $ops = [];
        if (!$others) {
            $end = $this->planStop($ops, $ids, $now);
            $ops[] = ['at' => $end, 'op' => 'status', 'text' => 'Bereit'];
        } else {
            $at = max($now, ...array_values($others));
            foreach ($ids as $id) {
                $ops[] = ['at' => $at, 'op' => 'valve', 'id' => $id, 'on' => false];
            }
        }
        $this->enqueue($ops, 'sys');
    }

    private function releaseManual(int $ref): void
    {
        $this->writeJson('Manual', array_values(array_diff($this->readJson('Manual'), [$ref])));
    }

    // ======================================================================
    // Wartungsmodus
    // ======================================================================

    private function maintenance(): bool
    {
        return $this->hasIdent('Maintenance') && (bool)$this->GetValue('Maintenance');
    }

    private function setMaintenanceLocked(bool $on): void
    {
        if ($on === $this->maintenance()) {
            return;
        }
        $this->SetValue('Maintenance', $on);
        $catID = @$this->GetIDForIdent('MaintenanceCategory');
        if ($catID !== false) {
            IPS_SetHidden($catID, !$on);
        }
        $this->LogMessage('Wartungsmodus ' . ($on ? 'eingeschaltet' : 'ausgeschaltet'), KL_NOTIFY);

        if ($on) {
            $this->shutdownLocked('Wartungsmodus aktiv – Pumpe gesperrt');
            return;
        }
        // Alle Kugelhähne schließen, frühestens nach noch laufenden Abschaltschritten.
        $at = max([$this->now(), ...array_column($this->readJson('Plan'), 'at')]);
        $ops = [];
        foreach (array_keys($this->openValves()) as $id) {
            $ops[] = ['at' => $at, 'op' => 'valve', 'id' => $id, 'on' => false];
        }
        $ops[] = ['at' => $at, 'op' => 'done', 'text' => 'Bereit', 'seq' => 0];
        $this->enqueue($ops, 'sys');
        $this->processLocked();
    }

    /** Schaltet im Wartungsmodus einen physischen Kugelhahn direkt (ohne Pumpe). */
    private function serviceValveLocked(int $phys, bool $open): string
    {
        if (!$this->maintenance()) {
            return 'Kugelhähne lassen sich hier nur im Wartungsmodus schalten.';
        }
        $zone = $this->physicalZones()[$phys] ?? null;
        $id = (int)($zone['ValveInstanceID'] ?? 0);
        if ($zone === null || $id <= 0 || !IPS_InstanceExists($id)) {
            return 'Wartung: ' . ($zone['Name'] ?? 'Kugelhahn') . ' hat kein gültiges Ventil.';
        }
        if ($this->readJson('Plan')) {
            return 'Anlage wird noch abgeschaltet – bitte einige Sekunden warten.';
        }
        if (!$this->switchValve($id, $open)) {
            return 'Wartung: ' . $zone['Name'] . ' konnte nicht ' . ($open ? 'geöffnet' : 'geschlossen') . ' werden.';
        }
        $this->setIfChanged('Status', 'Wartung: ' . $zone['Name'] . ($open ? ' geöffnet' : ' geschlossen'));
        return '';
    }

    /** Zeigt den Grund einer abgelehnten Bedienung im Status an. */
    private function report(?string $message): void
    {
        $message ??= 'Steuerung ist gerade beschäftigt – bitte erneut versuchen.';
        if ($message === '') {
            return;
        }
        $this->setIfChanged('Status', $message);
        $this->LogMessage($message, KL_NOTIFY);
        $this->syncZoneSwitches();
        $notice = json_decode($this->GetBuffer('Notice'), true) ?: ['id' => 0];
        $this->SetBuffer('Notice', json_encode(['id' => $notice['id'] + 1, 'text' => $message]));
    }

    // ======================================================================
    // Abschalten und Neustart
    // ======================================================================

    /**
     * Geordnetes Abschalten aus jedem Zustand: Warteschlange leeren, Pumpe
     * (nach $delay) aus, nach der Verfahrzeit Ventile zu. Mit $allValves
     * werden alle konfigurierten Ventile geschlossen (Zustand unbekannt).
     */
    private function shutdownLocked(string $text, int $delay = 0, bool $allValves = false): void
    {
        $this->writeJson('Plan', []);
        $this->writeJson('Manual', []);
        $this->setIfChanged('SeqControl', 0);

        $ids = array_keys($this->openValves());
        if ($allValves) {
            foreach ($this->zones() as $zone) {
                $ids = array_merge($ids, array_filter(array_column($zone['valves'], 'id')));
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $ops = [];
        if ($delay > 0) {
            $end = $this->planStop($ops, $ids, $this->now() + $delay);
        } else {
            $this->setPump(false);
            $end = $this->now() + $this->maxTravel($ids);
            foreach ($ids as $id) {
                $ops[] = ['at' => $end, 'op' => 'valve', 'id' => $id, 'on' => false];
            }
        }
        $ops[] = ['at' => $end, 'op' => 'done', 'text' => $text, 'seq' => 0];
        $this->enqueue($ops, 'sys');
        $this->setIfChanged('Status', $ids ? 'Stoppe Bewässerung …' : $text);
        $this->syncZoneSwitches();
    }

    private function fault(string $text, bool $allValves = false): void
    {
        $this->LogMessage($text, KL_ERROR);
        $this->shutdownLocked('Fehler: ' . $text, 0, $allValves);
    }

    /** Systemstart: geplante Schritte sind veraltet und werden nie ausgeführt. */
    private function prepareRecovery(): void
    {
        if ($this->isBusy()) {
            $this->writeJson('Plan', []);
            $this->writeJson('Manual', []);
            $this->WriteAttributeBoolean('RecoveryPending', true);
        }
    }

    private function recoverAfterStart(): void
    {
        $this->withLock(function (): void {
            if (!$this->ReadAttributeBoolean('RecoveryPending')) {
                return;
            }
            $this->WriteAttributeBoolean('RecoveryPending', false);
            $this->LogMessage('Neustart während laufender Bewässerung – Anlage wird sicher abgeschaltet.', KL_WARNING);
            $this->shutdownLocked('Neustart: Bewässerung beendet.', self::RECOVERY_DELAY, true);
        });
    }

    // ======================================================================
    // Warteschlange
    // ======================================================================

    private function enqueue(array $ops, string $src): void
    {
        $plan = $this->readJson('Plan');
        if (!$plan) {
            $this->SetBuffer('RunStart', (string)$this->now());
        }
        foreach ($ops as $op) {
            $plan[] = $op + ['src' => $src];
        }
        usort($plan, fn (array $a, array $b): int => $a['at'] <=> $b['at']); // stabil: gleiche Zeit behält die Reihenfolge
        $this->writeJson('Plan', $plan);
        $this->armQueueTimer();
    }

    private function cancelOps(string $src): void
    {
        $this->writeJson('Plan', array_values(array_filter($this->readJson('Plan'), fn (array $op): bool => $op['src'] !== $src)));
        $this->armQueueTimer();
    }

    private function processLocked(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        for ($guard = 0; $guard < 1000; $guard++) {
            $plan = $this->readJson('Plan');
            if (!$plan || $plan[0]['at'] > $this->now()) {
                break;
            }
            $op = array_shift($plan);
            $this->writeJson('Plan', $plan);
            $this->execute($op);
        }
        $this->armQueueTimer();
        $this->updateRemainingDisplay();
    }

    private function execute(array $op): void
    {
        switch ($op['op']) {
            case 'valve':
                $ok = $this->switchValve((int)$op['id'], (bool)$op['on']);
                if (!$ok && $op['on']) {
                    $this->fault('Ventil #' . $op['id'] . ' konnte nicht geöffnet werden – Bewässerung abgebrochen.', true);
                } elseif (!$ok && empty($op['retry'])) {
                    $this->enqueue([['at' => $this->now() + self::CLOSE_RETRY, 'op' => 'valve', 'id' => $op['id'], 'on' => false, 'retry' => 1]], 'sys');
                }
                break;

            case 'pump':
                if (!$op['on']) {
                    $this->setPump(false);
                    break;
                }
                $open = array_keys($this->openValves());
                $need = $op['need'] ?? $open;
                $stale = !empty($op['reassert']) && $this->ReadAttributeInteger('PumpOnSince') === 0;
                if ($this->maintenance() || $stale || !array_intersect($need, $open)) {
                    break; // Zugehöriges Ventil inzwischen geschlossen: Pumpe nicht starten
                }
                if (!$this->setPump(true)) {
                    $this->fault('Pumpe konnte nicht eingeschaltet werden – Bewässerung abgebrochen.');
                }
                break;

            case 'status':
                $this->setIfChanged('Status', (string)$op['text']);
                break;

            case 'mark':
                $lastRun = $this->readJson('LastRun');
                $lastRun[$op['key']] = date('Y-m-d', $this->now());
                $this->writeJson('LastRun', $lastRun);
                break;

            case 'close':
                $this->releaseManual((int)$op['ref']);
                $idx = $this->findZone((int)$op['ref']);
                if ($idx !== null) {
                    $this->closeZoneLocked($this->zones()[$idx]);
                }
                $this->syncZoneSwitches();
                break;

            case 'done':
                if ($op['seq'] > 0) {
                    $this->setIfChanged('SeqControl', 0);
                    $this->LogMessage($this->seqName((int)$op['seq']) . ' beendet', KL_NOTIFY);
                }
                if (str_starts_with($op['src'], 'm')) {
                    $this->releaseManual((int)substr($op['src'], 1));
                    $this->syncZoneSwitches();
                }
                $this->setIfChanged('Status', (string)$op['text']);
                $this->updateNextRunDisplays(true);
                break;
        }
    }

    private function armQueueTimer(): void
    {
        $plan = $this->readJson('Plan');
        $this->SetTimerInterval('Queue', $plan ? max(1, $plan[0]['at'] - $this->now()) * 1000 : 0);
    }

    // ======================================================================
    // Aktoren
    // ======================================================================

    private function switchValve(int $id, bool $on): bool
    {
        $this->accumulateWater();
        $ok = $this->knx($id, $on);
        $valves = $this->openValves();
        if ($on) {
            if (!$ok) {
                return false;
            }
            if (!isset($valves[$id])) {
                $valves[$id] = $this->now() + $this->maxTravel([$id]);
                $phys = $this->physOfValve($id);
                if ($phys !== null) {
                    $cyclesID = $this->statVarID('CyclesP' . $phys);
                    if ($cyclesID > 0) {
                        SetValue($cyclesID, GetValue($cyclesID) + 1);
                    }
                }
            }
        } else {
            unset($valves[$id]);
        }
        $this->writeJson('Valves', $valves);
        $this->WriteAttributeInteger('WaterLastTs', $valves ? ($this->ReadAttributeInteger('WaterLastTs') ?: $this->now()) : 0);
        $this->syncZoneSwitches();
        return $ok;
    }

    private function setPump(bool $on): bool
    {
        if ($on && $this->maintenance()) {
            $this->LogMessage('Pumpe im Wartungsmodus gesperrt – Einschaltbefehl verworfen', KL_ERROR);
            return false;
        }
        $ok = $this->knx($this->ReadPropertyInteger('PumpInstanceID'), $on);
        $since = $this->ReadAttributeInteger('PumpOnSince');
        if ($on) {
            if ($ok && $since === 0) {
                $this->WriteAttributeInteger('PumpOnSince', $this->now());
                $this->SetBuffer('PumpAssert', (string)$this->now());
                $this->WriteAttributeFloat('WaterRunAccum', 0);
                $this->updateWaterDisplay();
            }
            return $ok;
        }
        if ($since > 0) {
            $delta = $this->now() - $since;
            $this->WriteAttributeInteger('DayAccum', $this->ReadAttributeInteger('DayAccum') + $delta);
            $this->WriteAttributeInteger('TotalAccum', $this->ReadAttributeInteger('TotalAccum') + $delta);
            $this->WriteAttributeInteger('PumpOnSince', 0);
            $this->updateRuntimeDisplay();
        }
        return $ok;
    }

    /** Schreibt DPT1 an eine KNX-Instanz; Fehler werden geloggt, nie geworfen. */
    private function knx(int $instanceID, bool $value): bool
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            $this->LogMessage('KNX-Instanz #' . $instanceID . ' existiert nicht', KL_ERROR);
            return false;
        }
        if (!function_exists('KNX_WriteDPT1')) {
            $this->LogMessage('KNX_WriteDPT1 nicht verfügbar – ist das KNX-Modul installiert?', KL_ERROR);
            return false;
        }
        try {
            $ok = @KNX_WriteDPT1($instanceID, $value);
        } catch (Throwable $e) {
            $ok = false;
        }
        if ($ok === false) {
            $this->LogMessage('KNX-Schaltbefehl an #' . $instanceID . ' (' . ($value ? 'an' : 'aus') . ') fehlgeschlagen', KL_ERROR);
            return false;
        }
        return true;
    }

    /**
     * Pumpenüberwachung (Takt 10 s), solange bewässert wird:
     * - Rückmeldung "aus": einmal erneut einschalten; bleibt die
     *   Rückmeldung aus (z. B. Trockenlaufschutz), wird abgebrochen.
     * - sonst jede Minute erneut einschalten (verlorene Telegramme, Spannungsausfall).
     * Eine Rückmeldung "an" bei abgeschalteter Pumpe wird nur protokolliert.
     * Im Wartungsmodus wird stattdessen regelmäßig "aus" gesendet.
     */
    private function superviseLocked(): void
    {
        $now = $this->now();
        $since = $this->ReadAttributeInteger('PumpOnSince');
        $fbID = $this->ReadPropertyInteger('PumpStatusID');
        $fb = ($fbID > 0 && IPS_VariableExists($fbID)) ? (bool)GetValue($fbID) : null;

        if ($this->maintenance()) {
            // Pumpe gesperrt: Aus-Befehl jede Minute wiederholen, bei Rückmeldung "an" sofort.
            if ($fb === true && $this->GetBuffer('PumpUnexpected') !== '1') {
                $this->LogMessage('Pumpe meldet im Wartungsmodus AN – sende Aus-Befehl', KL_WARNING);
            }
            $this->SetBuffer('PumpUnexpected', $fb === true ? '1' : '0');
            if ($fb === true || $now - (int)$this->GetBuffer('PumpAssert') >= self::PUMP_REASSERT) {
                $this->knx($this->ReadPropertyInteger('PumpInstanceID'), false);
                $this->SetBuffer('PumpAssert', (string)$now);
            }
            return;
        }

        $unexpectedOn = $since === 0 && $fb === true && !$this->readJson('Valves');
        if ($unexpectedOn !== ($this->GetBuffer('PumpUnexpected') === '1')) {
            $this->SetBuffer('PumpUnexpected', $unexpectedOn ? '1' : '0');
            if ($unexpectedOn) {
                $this->LogMessage('Pumpe meldet AN, obwohl das Modul sie nicht eingeschaltet hat', KL_WARNING);
            }
        }
        if ($since === 0) {
            $this->SetBuffer('PumpFbOff', '');
            return;
        }

        if ($fb === false && $now - $since > self::PUMP_GRACE) {
            $offSince = (int)$this->GetBuffer('PumpFbOff');
            if ($offSince === 0) {
                $this->SetBuffer('PumpFbOff', (string)$now);
                $this->LogMessage('Pumpe meldet AUS, obwohl sie laufen soll – schalte erneut ein', KL_WARNING);
                $this->reassertRun();
            } elseif ($now - $offSince >= self::PUMP_GRACE) {
                $this->fault('Pumpe meldet trotz Einschaltbefehl AUS – Bewässerung abgebrochen.');
            }
            return;
        }
        $this->SetBuffer('PumpFbOff', '');
        if ($now - (int)$this->GetBuffer('PumpAssert') >= self::PUMP_REASSERT) {
            $this->reassertRun();
        }
    }

    /**
     * Wiederholt den Laufzustand nach dem Einschaltschema: offene Ventile
     * erneut auf, Pumpe erst nach der Verfahrzeit. Stromlos schließende
     * Kugelhähne können nach einem Spannungsausfall zu sein, ohne dass das
     * Modul es erfährt; die Pumpe darf dann nicht sofort anlaufen.
     */
    private function reassertRun(): void
    {
        $open = array_keys($this->openValves());
        foreach ($open as $id) {
            $this->knx($id, true);
        }
        $this->enqueue([['at' => $this->now() + $this->maxTravel($open), 'op' => 'pump', 'on' => true, 'need' => $open, 'reassert' => true]], 'sys');
        $this->SetBuffer('PumpAssert', (string)$this->now());
    }

    // ======================================================================
    // Zonenmodell
    // ======================================================================

    /** Physische Zonen in Listenreihenfolge, normalisiert. */
    private function physicalZones(): array
    {
        $rows = json_decode($this->ReadPropertyString('Zones'), true) ?: [];
        $zones = [];
        foreach (array_values($rows) as $i => $z) {
            $travel = (int)($z['TravelTime'] ?? 0);
            $duration = (int)($z['DefaultDuration'] ?? 0);
            $zones[] = [
                'Name'            => trim((string)($z['Name'] ?? '')) ?: 'Zone ' . ($i + 1),
                'ValveInstanceID' => (int)($z['ValveInstanceID'] ?? 0),
                'TravelTime'      => $travel > 0 ? $travel : self::DEFAULT_TRAVEL,
                'DefaultDuration' => $duration > 0 ? $duration : 10,
                'Lawn'            => !empty($z['Lawn']),
                'UseSensor'       => !empty($z['UseSensor']),
                'SensorID'        => (int)($z['SensorID'] ?? 0),
                'Threshold'       => (float)($z['Threshold'] ?? 60),
                'Invert'          => !empty($z['Invert'])
            ];
        }
        return array_slice($zones, 0, self::MAX_ZONES);
    }

    /**
     * Logische Zonen: jede Nicht-Rasen-Zeile einzeln, alle Rasen-Zeilen
     * gemeinsam an der Position der ersten. "ref" ist die stabile Referenz
     * (Ventil-Instanz bzw. LAWN).
     */
    private function zones(): array
    {
        if ($this->zoneCache !== null) {
            return $this->zoneCache;
        }
        $physical = $this->physicalZones();
        $lawn = array_filter($physical, fn (array $z): bool => $z['Lawn']);
        $zones = [];
        $lawnAdded = false;
        foreach ($physical as $i => $z) {
            if (!$z['Lawn']) {
                $zones[] = $this->buildZone($z['Name'], $z['ValveInstanceID'], [$i => $z]);
            } elseif (!$lawnAdded) {
                $zones[] = $this->buildZone($this->lawnName(), self::LAWN, $lawn);
                $lawnAdded = true;
            }
        }
        return $this->zoneCache = $zones;
    }

    private function buildZone(string $name, int $ref, array $members): array
    {
        $valves = [];
        $sensors = [];
        foreach ($members as $phys => $m) {
            $valves[] = ['id' => $m['ValveInstanceID'], 'phys' => $phys, 'travel' => $m['TravelTime']];
            if ($m['UseSensor']) {
                $sensors[] = ['id' => $m['SensorID'], 'threshold' => $m['Threshold'], 'invert' => $m['Invert']];
            }
        }
        $usable = true;
        foreach ($valves as $v) {
            $usable = $usable && $v['id'] > 0 && IPS_InstanceExists($v['id']);
        }
        return [
            'name'     => $name,
            'ref'      => $ref,
            'valves'   => $valves,
            'sensors'  => $sensors,
            'duration' => reset($members)['DefaultDuration'],
            'lawn'     => count($valves) > 1,
            'usable'   => $usable
        ];
    }

    private function findZone(int $ref): ?int
    {
        if ($ref === 0) {
            return null;
        }
        foreach ($this->zones() as $i => $z) {
            if ($z['ref'] === $ref) {
                return $i;
            }
        }
        return null;
    }

    private function physOfValve(int $id): ?int
    {
        foreach ($this->physicalZones() as $i => $z) {
            if ($z['ValveInstanceID'] === $id) {
                return $i;
            }
        }
        return null;
    }

    /** Größte Verfahrzeit der angegebenen Ventile (unbekannte: Standard). */
    private function maxTravel(array $valveIDs): int
    {
        $travel = [];
        foreach ($this->physicalZones() as $z) {
            $travel[$z['ValveInstanceID']] = $z['TravelTime'];
        }
        $max = 1;
        foreach ($valveIDs as $id) {
            $max = max($max, $travel[(int)$id] ?? self::DEFAULT_TRAVEL);
        }
        return $max;
    }

    /** @return array<int,int> offene Ventile => Zeitpunkt "vollständig offen" */
    private function openValves(): array
    {
        $valves = [];
        foreach ($this->readJson('Valves') as $id => $ready) {
            $valves[(int)$id] = (int)$ready;
        }
        return $valves;
    }

    private function zoneIsOpen(array $zone, ?array $openIDs = null): bool
    {
        return (bool)array_intersect(array_column($zone['valves'], 'id'), $openIDs ?? array_keys($this->openValves()));
    }

    private function openZoneRefs(): array
    {
        $openIDs = array_keys($this->openValves());
        $refs = [];
        foreach ($this->zones() as $zone) {
            if ($this->zoneIsOpen($zone, $openIDs)) {
                $refs[] = $zone['ref'];
            }
        }
        return $refs;
    }

    private function isBusy(): bool
    {
        return $this->readJson('Plan') || $this->readJson('Valves') || $this->readJson('Manual')
            || $this->ReadAttributeInteger('PumpOnSince') > 0;
    }

    private function maxParallel(): int
    {
        return min(self::MAX_OPEN, max(1, $this->ReadPropertyInteger('MaxParallel')));
    }

    /** Ohne Sensor: immer gießen. Mehrere Sensoren (Rasen): gießen, wenn einer "trocken" meldet. */
    private function zoneNeedsWater(array $zone): bool
    {
        if (!$zone['sensors']) {
            return true;
        }
        foreach ($zone['sensors'] as $s) {
            if ($s['id'] <= 0 || !IPS_VariableExists($s['id'])) {
                continue;
            }
            $value = (float)GetValue($s['id']);
            $moist = $s['invert'] ? $value <= $s['threshold'] : $value >= $s['threshold'];
            if (!$moist) {
                return true;
            }
        }
        return false;
    }

    private function sequenceRows(int $seq): array
    {
        return json_decode($this->ReadPropertyString('Sequence' . $seq), true) ?: [];
    }

    private function lastRunKey(int $seq, int $ref): string
    {
        return 's' . $seq . 'k' . $ref;
    }

    private function lawnName(): string
    {
        return trim($this->ReadPropertyString('LawnName')) ?: 'Rasen';
    }

    private function seqName(int $seq): string
    {
        return trim($this->ReadPropertyString('Seq' . $seq . 'Name')) ?: 'Sequenz ' . $seq;
    }

    private function seqProfile(): string
    {
        return 'BWS.SeqCtrl' . $this->InstanceID;
    }

    /**
     * Stellt Sequenzzeilen und Intervall-Speicher von Listenpositionen auf
     * stabile Zonen-Referenzen um (einmalig). Liefert true, wenn die
     * Konfiguration geändert und neu übernommen wurde.
     */
    private function migrateZoneReferences(): bool
    {
        if ($this->ReadAttributeBoolean('ZoneRefsMigrated')) {
            return false;
        }
        $this->WriteAttributeBoolean('ZoneRefsMigrated', true);
        $zones = $this->zones();

        $lastRun = [];
        foreach ($this->readJson('LastRun') as $key => $date) {
            if (preg_match('/^s(\d+)z(\d+)$/', (string)$key, $m)) {
                if (isset($zones[(int)$m[2]]) && $zones[(int)$m[2]]['ref'] !== 0) {
                    $lastRun[$this->lastRunKey((int)$m[1], $zones[(int)$m[2]]['ref'])] = $date;
                }
            } else {
                $lastRun[$key] = $date;
            }
        }
        $this->writeJson('LastRun', $lastRun);

        $changed = false;
        for ($s = 1; $s <= self::MAX_SEQUENCES; $s++) {
            $rows = $this->sequenceRows($s);
            $legacy = false;
            foreach ($rows as &$row) {
                $zone = (int)($row['Zone'] ?? 0);
                if ($zone >= 0 && $zone < self::LEGACY_REF_LIMIT) {
                    $row['Zone'] = $zones[$zone]['ref'] ?? 0;
                    $legacy = true;
                }
            }
            unset($row);
            if ($legacy) {
                IPS_SetProperty($this->InstanceID, 'Sequence' . $s, json_encode($rows));
                $changed = true;
            }
        }
        if ($changed) {
            $this->LogMessage('Sequenzen auf stabile Zonen-Referenzen umgestellt', KL_NOTIFY);
            IPS_ApplyChanges($this->InstanceID);
        }
        return $changed;
    }

    private function validateConfiguration(): void
    {
        $pumpID = $this->ReadPropertyInteger('PumpInstanceID');
        if ($pumpID <= 0 || !IPS_InstanceExists($pumpID)) {
            $this->SetStatus(201);
            return;
        }
        $ids = array_filter(array_column($this->physicalZones(), 'ValveInstanceID'));
        if (count($ids) !== count(array_unique($ids))) {
            $this->SetStatus(203);
            return;
        }
        foreach ($this->zones() as $zone) {
            if (!$zone['usable']) {
                $this->SetStatus(202);
                return;
            }
        }
        for ($s = 1; $s <= self::MAX_SEQUENCES; $s++) {
            foreach ($this->sequenceRows($s) as $row) {
                if (!empty($row['Active']) && $this->findZone((int)($row['Zone'] ?? 0)) === null) {
                    $this->SetStatus(204);
                    return;
                }
            }
        }
        $this->SetStatus(IS_ACTIVE);
    }

    // ======================================================================
    // Variablen
    // ======================================================================

    private function maintainVariables(): void
    {
        $isNew = !$this->hasIdent('Active');
        $this->RegisterVariableBoolean('Active', 'Master-Schalter', '~Switch', 10);
        $this->EnableAction('Active');
        if ($isNew) {
            $this->SetValue('Active', true);
        }
        $this->RegisterVariableBoolean('Maintenance', 'Wartungsmodus (Pumpe gesperrt)', '~Switch', 15);
        $this->EnableAction('Maintenance');

        $profile = $this->seqProfile();
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon($profile, 'Execute');
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Stopp', '', 0xFF4040);
        $colors = [0x40A0FF, 0x4040FF, 0x40C0A0, 0xA060FF];
        for ($s = 1; $s <= self::MAX_SEQUENCES; $s++) {
            IPS_SetVariableProfileAssociation($profile, $s, $this->seqName($s), '', $colors[$s - 1]);
        }
        $this->RegisterVariableInteger('SeqControl', 'Automatik-Sequenz', $profile, 20);
        $this->EnableAction('SeqControl');

        $isNew = !$this->hasIdent('Status');
        $this->RegisterVariableString('Status', 'Status', '', 30);
        if ($isNew) {
            $this->SetValue('Status', 'Bereit');
        }
        $this->RegisterVariableBoolean('PumpStatus', 'Pumpenstatus (Rückmeldung)', '~Switch', 31);
        $this->RegisterVariableFloat('Pressure', 'Wasserdruck', 'BWS.Pressure', 32);
        $this->RegisterVariableFloat('Flow', 'Durchfluss', 'BWS.Flow', 34);
        $this->RegisterVariableInteger('Remaining', 'Restlaufzeit', 'BWS.Minutes', 36);

        $defaultTimes = ['06:00', '19:00', '12:00', '22:00'];
        for ($s = 1; $s <= self::MAX_SEQUENCES; $s++) {
            $isNew = !$this->hasIdent('Auto' . $s);
            $this->RegisterVariableBoolean('Auto' . $s, 'Automatik ' . $this->seqName($s), '~Switch', 40);
            $this->EnableAction('Auto' . $s);
            $this->renameIfChanged('Auto' . $s, 'Automatik ' . $this->seqName($s));
            if ($isNew) {
                $this->SetValue('Auto' . $s, $s <= 2);
            }

            $isNew = !$this->hasIdent('StartTime' . $s);
            $this->RegisterVariableInteger('StartTime' . $s, 'Startzeit ' . $this->seqName($s), '~UnixTimestampTime', 41);
            $this->EnableAction('StartTime' . $s);
            $this->renameIfChanged('StartTime' . $s, 'Startzeit ' . $this->seqName($s));
            if ($isNew) {
                $this->SetValue('StartTime' . $s, (int)strtotime($defaultTimes[$s - 1], $this->now()));
                $this->skipPassedSlot($s);
            }
        }
        $this->updateSequenceOrder();

        $stats = $this->ensureCategory('StatsCategory', 'Statistik', 900);
        $manualCat = $this->ensureCategory('ManualDurCategory', 'Manuelle Laufzeit', 150);
        $planCat = $this->ensureCategory('PlanCategory', 'Nächste Laufzeiten', 60);
        $serviceCat = $this->ensureCategory('MaintenanceCategory', 'Wartung – Kugelhähne', 160);
        IPS_SetHidden($serviceCat, !$this->maintenance());

        $physical = $this->physicalZones();
        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            $used = isset($physical[$i]);
            $name = $used ? $physical[$i]['Name'] : '';
            $this->maintainCatVariable($stats, 'CyclesP' . $i, $name . ' – Zyklen Kugelhahn', VARIABLETYPE_INTEGER, 'BWS.Cycles', 200 + $i, $used, false);
            $this->maintainCatVariable($serviceCat, 'ServiceV' . $i, $name, VARIABLETYPE_BOOLEAN, '~Switch', 160 + $i, $used, true);
        }

        $zones = $this->zones();
        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            $used = isset($zones[$i]);
            $name = $used ? $zones[$i]['name'] : '';
            $this->MaintainVariable('ManualZ' . $i, $name, VARIABLETYPE_BOOLEAN, '~Switch', 100 + $i, $used);
            if ($used) {
                $this->EnableAction('ManualZ' . $i);
                $this->renameIfChanged('ManualZ' . $i, $name);
            }
            $isNew = $used && @IPS_GetObjectIDByIdent('ManualDurationZ' . $i, $manualCat) === false;
            $durID = $this->maintainCatVariable($manualCat, 'ManualDurationZ' . $i, $name, VARIABLETYPE_INTEGER, 'BWS.Minutes', 150 + $i, $used, true);
            if ($isNew && $durID > 0) {
                SetValue($durID, $zones[$i]['duration']);
            }
            $this->maintainCatVariable($planCat, 'NextRunZ' . $i, $name, VARIABLETYPE_STRING, '', 60 + $i, $used, false);
        }

        $this->maintainCatVariable($stats, 'RuntimeDay', 'Pumpenlaufzeit heute', VARIABLETYPE_INTEGER, 'BWS.Minutes', 300, true, false);
        $this->maintainCatVariable($stats, 'RuntimeTotal', 'Pumpenlaufzeit gesamt', VARIABLETYPE_FLOAT, 'BWS.Hours', 310, true, false);
        $this->maintainCatVariable($stats, 'WaterLastRun', 'Wasserverbrauch letzte Laufzeit', VARIABLETYPE_FLOAT, 'BWS.Liters', 320, true, false);
        $this->maintainCatVariable($stats, 'WaterTotal', 'Wasserverbrauch gesamt', VARIABLETYPE_FLOAT, 'BWS.Liters', 330, true, false);
        $this->syncZoneSwitches();
    }

    /** Sequenzblöcke nach Startzeit sortieren, inaktive ans Ende (Startzeit dann ausgeblendet). */
    private function updateSequenceOrder(): void
    {
        $seqs = [];
        for ($s = 1; $s <= self::MAX_SEQUENCES; $s++) {
            if (!$this->hasIdent('Auto' . $s) || !$this->hasIdent('StartTime' . $s)) {
                continue;
            }
            $ts = (int)$this->GetValue('StartTime' . $s);
            $seqs[] = [
                'seq'     => $s,
                'active'  => (bool)$this->GetValue('Auto' . $s),
                'minutes' => $ts > 0 ? (int)date('G', $ts) * 60 + (int)date('i', $ts) : 0
            ];
        }
        usort($seqs, fn (array $a, array $b): int => [$b['active'], $a['minutes'], $a['seq']] <=> [$a['active'], $b['minutes'], $b['seq']]);

        $pos = 40;
        foreach ($seqs as $e) {
            IPS_SetPosition($this->GetIDForIdent('Auto' . $e['seq']), $pos++);
            $timeID = $this->GetIDForIdent('StartTime' . $e['seq']);
            IPS_SetPosition($timeID, $pos++);
            IPS_SetHidden($timeID, !$e['active']);
        }
    }

    /** Zonen-Schalter: manuell belegt oder Ventil offen; Wartungs-Schalter: Kugelhahn offen. */
    private function syncZoneSwitches(): void
    {
        $manual = $this->readJson('Manual');
        $openIDs = array_keys($this->openValves());
        foreach ($this->zones() as $i => $zone) {
            $this->setIfChanged('ManualZ' . $i, in_array($zone['ref'], $manual, true) || $this->zoneIsOpen($zone, $openIDs));
        }
        foreach ($this->physicalZones() as $i => $z) {
            $this->setVarIfChanged($this->catVarID('MaintenanceCategory', 'ServiceV' . $i), in_array($z['ValveInstanceID'], $openIDs, true));
        }
    }

    private function updateRuntimeDisplay(): void
    {
        $since = $this->ReadAttributeInteger('PumpOnSince');
        $running = $since > 0 ? $this->now() - $since : 0;
        $this->setVarIfChanged($this->statVarID('RuntimeDay'), (int)round(($this->ReadAttributeInteger('DayAccum') + $running) / 60));
        $this->setVarIfChanged($this->statVarID('RuntimeTotal'), round(($this->ReadAttributeInteger('TotalAccum') + $running) / 3600, 2));
    }

    private function updateRemainingDisplay(): void
    {
        $plan = $this->readJson('Plan');
        $end = $plan ? max(array_column($plan, 'at')) : 0;
        $this->setIfChanged('Remaining', (int)ceil(max(0, $end - $this->now()) / 60));
    }

    private function rollDay(): void
    {
        $today = date('Y-m-d', $this->now());
        if ($this->ReadAttributeString('DayDate') === $today) {
            return;
        }
        $since = $this->ReadAttributeInteger('PumpOnSince');
        if ($since > 0) {
            $this->WriteAttributeInteger('TotalAccum', $this->ReadAttributeInteger('TotalAccum') + $this->now() - $since);
            $this->WriteAttributeInteger('PumpOnSince', $this->now());
        }
        $this->WriteAttributeInteger('DayAccum', 0);
        $this->WriteAttributeString('DayDate', $today);
    }

    /** "Nächste Laufzeiten": höchstens einmal pro Minute neu berechnet (oder erzwungen). */
    private function updateNextRunDisplays(bool $force): void
    {
        $minute = date('Y-m-d H:i', $this->now());
        if (!$force && $this->GetBuffer('NextRunMinute') === $minute) {
            return;
        }
        $this->SetBuffer('NextRunMinute', $minute);

        $zones = $this->zones();
        $lastRun = $this->readJson('LastRun');
        $slots = $this->activeSlots();
        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            $text = isset($zones[$i]) ? $this->nextRunText($zones[$i]['ref'], $slots, $lastRun) : '–';
            $this->setVarIfChanged($this->catVarID('PlanCategory', 'NextRunZ' . $i), $text);
        }
    }

    private function nextRunText(int $ref, array $slots, array $lastRun): string
    {
        [$best, $bestSeq] = $this->nextRun($ref, $slots, $lastRun) ?? [null, 0];
        return $best === null ? '–' : $this->dayLabel($best) . ', ' . date('H:i', $best) . ' (' . $this->seqName($bestSeq) . ')';
    }

    /** Frühester kommender Termin einer Zone: [Zeitpunkt, Sequenz] oder null. */
    private function nextRun(int $ref, array $slots, array $lastRun): ?array
    {
        $now = $this->now();
        $today = (int)strtotime('today', $now);
        $best = null;
        $bestSeq = 0;
        foreach ($slots as $s => $slot) {
            foreach ($slot['rows'] as $row) {
                if (empty($row['Active']) || (int)($row['Zone'] ?? 0) !== $ref) {
                    continue;
                }
                $last = isset($lastRun[$this->lastRunKey($s, $ref)]) ? (int)strtotime((string)$lastRun[$this->lastRunKey($s, $ref)]) : 0;
                $dueDay = $last > 0 ? (int)strtotime('+' . max(1, (int)($row['Interval'] ?? 1)) . ' days', $last) : $today;
                $candidate = (int)strtotime(date('Y-m-d', max($dueDay, $today)) . ' ' . $slot['hm'], $now);
                if ($candidate <= $now) {
                    $candidate = (int)strtotime('+1 day', $candidate);
                }
                if ($best === null || $candidate < $best) {
                    $best = $candidate;
                    $bestSeq = $s;
                }
            }
        }
        return $best === null ? null : [$best, $bestSeq];
    }

    private function dayLabel(int $ts): string
    {
        $now = $this->now();
        return match (date('Y-m-d', $ts)) {
            date('Y-m-d', $now)                           => 'heute',
            date('Y-m-d', (int)strtotime('+1 day', $now)) => 'morgen',
            default => ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][(int)date('w', $ts)]
        };
    }

    /** Aktive Sequenzen mit Startzeit und Zeilen, Grundlage der Vorschau. */
    private function activeSlots(): array
    {
        $slots = [];
        for ($s = 1; $s <= self::MAX_SEQUENCES; $s++) {
            if ($this->hasIdent('Auto' . $s) && $this->GetValue('Auto' . $s) && $this->GetValue('StartTime' . $s) > 0) {
                $slots[$s] = ['hm' => date('H:i', (int)$this->GetValue('StartTime' . $s)), 'rows' => $this->sequenceRows($s)];
            }
        }
        return $slots;
    }

    // ======================================================================
    // Sensoren und Wasserverbrauch
    // ======================================================================

    private function watchSensors(): void
    {
        foreach ($this->GetMessageList() as $sender => $messages) {
            if (in_array(VM_UPDATE, $messages, true)) {
                $this->UnregisterMessage($sender, VM_UPDATE);
            }
        }
        foreach (['PumpStatusID', 'PressureSensorID', 'FlowSensorID'] as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
    }

    private function onSensorUpdate(int $sender, array $data): void
    {
        if ($sender === $this->ReadPropertyInteger('FlowSensorID')) {
            $this->accumulateWater((float)$data[2]); // bis jetzt galt der alte Wert
            $this->setIfChanged('Flow', (float)$data[0]);
        }
        if ($sender === $this->ReadPropertyInteger('PressureSensorID')) {
            $this->setIfChanged('Pressure', (float)$data[0]);
        }
        if ($sender === $this->ReadPropertyInteger('PumpStatusID')) {
            $this->setIfChanged('PumpStatus', (bool)$data[0]);
        }
    }

    private function mirrorSensors(): void
    {
        foreach (['PumpStatusID' => 'PumpStatus', 'PressureSensorID' => 'Pressure', 'FlowSensorID' => 'Flow'] as $property => $ident) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && IPS_VariableExists($id)) {
                $value = GetValue($id);
                $this->setIfChanged($ident, $ident === 'PumpStatus' ? (bool)$value : (float)$value);
            }
        }
    }

    /**
     * Integriert den Durchfluss seit der letzten Abrechnung. Aufgerufen vor
     * jeder Ventiländerung, bei jeder neuen Durchfluss-Messung und im Takt.
     */
    private function accumulateWater(?float $flow = null): void
    {
        $now = $this->now();
        $last = $this->ReadAttributeInteger('WaterLastTs');
        $flowID = $this->ReadPropertyInteger('FlowSensorID');
        if ($last > 0 && $now > $last && $flowID > 0 && IPS_VariableExists($flowID) && !$this->maintenance()) {
            $liters = max(0.0, $flow ?? (float)GetValue($flowID)) / 60 * ($now - $last);
            if ($liters > 0) {
                $this->WriteAttributeFloat('WaterRunAccum', $this->ReadAttributeFloat('WaterRunAccum') + $liters);
                $this->WriteAttributeFloat('WaterGlobalTotal', $this->ReadAttributeFloat('WaterGlobalTotal') + $liters);
                $this->updateWaterDisplay();
            }
        }
        $ts = $this->readJson('Valves') ? $now : 0;
        if ($ts !== $last) {
            $this->WriteAttributeInteger('WaterLastTs', $ts);
        }
    }

    private function updateWaterDisplay(): void
    {
        $this->setVarIfChanged($this->statVarID('WaterLastRun'), round($this->ReadAttributeFloat('WaterRunAccum'), 2));
        $this->setVarIfChanged($this->statVarID('WaterTotal'), round($this->ReadAttributeFloat('WaterGlobalTotal'), 2));
    }

    // ======================================================================
    // Regensperre (Weather Underground)
    // ======================================================================

    /** true, wenn die Regensperre aktiv ist und die Station Regen meldet. Störungen blockieren nicht. */
    private function wuRainBlocks(?string &$reason = null): bool
    {
        $reason = '';
        if (!$this->ReadPropertyBoolean('WUEnabled')) {
            return false;
        }
        $apiKey = trim($this->ReadPropertyString('WUApiKey'));
        $station = trim($this->ReadPropertyString('WUStationID'));
        if ($apiKey === '' || $station === '') {
            $this->LogMessage('Regensperre aktiv, aber API-Key oder Station-ID fehlt – Start wird nicht blockiert', KL_WARNING);
            return false;
        }
        $rate = $this->wuPrecipRate($station, $apiKey, $err);
        if ($rate === null) {
            $this->LogMessage('Regensperre: Wetterdaten nicht abrufbar (' . $err . ') – Start wird nicht blockiert', KL_WARNING);
            return false;
        }
        $threshold = max(0.0, $this->ReadPropertyFloat('WURainThreshold'));
        $blocks = $threshold > 0 ? $rate >= $threshold : $rate > 0;
        if ($blocks) {
            $reason = 'Regensperre: Station ' . $station . ' meldet ' . $rate . ' mm/h (Schwelle ' . ($threshold > 0 ? $threshold . ' mm/h' : 'jeder Regen') . ')';
        }
        return $blocks;
    }

    private function wuPrecipRate(string $station, string $apiKey, ?string &$err = null): ?float
    {
        $err = '';
        $url = 'https://api.weather.com/v2/pws/observations/current?stationId=' . rawurlencode($station)
            . '&format=json&units=m&numericPrecision=decimal&apiKey=' . rawurlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            $err = $curlErr !== '' ? $curlErr : 'HTTP ' . $code;
            return null;
        }
        $obs = json_decode((string)$body, true)['observations'][0] ?? null;
        $rate = $obs['metric']['precipRate'] ?? $obs['precipRate'] ?? null;
        if (!is_numeric($rate)) {
            $err = 'kein Regenraten-Wert in der Antwort';
            return null;
        }
        return (float)$rate;
    }

    // ======================================================================
    // Hilfsfunktionen
    // ======================================================================

    /** Führt $fn unter der Instanzsperre aus; liefert null, wenn die Sperre belegt ist. */
    private function withLock(callable $fn)
    {
        $name = 'BWS_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($name, 3000)) {
            return null;
        }
        try {
            return $fn();
        } finally {
            IPS_SemaphoreLeave($name);
            $this->pushVisualization();
        }
    }

    private function readJson(string $attribute): array
    {
        $data = json_decode($this->ReadAttributeString($attribute), true);
        return is_array($data) ? $data : [];
    }

    private function writeJson(string $attribute, array $data): void
    {
        $this->WriteAttributeString($attribute, json_encode($data));
    }

    private function hasIdent(string $ident): bool
    {
        return @$this->GetIDForIdent($ident) !== false;
    }

    private function setIfChanged(string $ident, $value): void
    {
        if ($this->hasIdent($ident) && $this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function setVarIfChanged(int $id, $value): void
    {
        if ($id > 0 && GetValue($id) !== $value) {
            SetValue($id, $value);
        }
    }

    private function renameIfChanged(string $ident, string $name): void
    {
        $id = $this->GetIDForIdent($ident);
        if (IPS_GetName($id) !== $name) {
            IPS_SetName($id, $name);
        }
    }

    private function ensureProfile(string $name, int $type, string $suffix, int $digits = 0, string $icon = ''): void
    {
        if (IPS_VariableProfileExists($name)) {
            return;
        }
        IPS_CreateVariableProfile($name, $type);
        IPS_SetVariableProfileText($name, '', $suffix);
        if ($type === VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileDigits($name, $digits);
        }
        if ($icon !== '') {
            IPS_SetVariableProfileIcon($name, $icon);
        }
    }

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
     * Pflegt eine Variable unterhalb einer Unterkategorie und liefert ihre ID
     * (0, wenn sie entfernt wurde). Mit $action landet die Bedienung in
     * RequestAction().
     */
    private function maintainCatVariable(int $categoryID, string $ident, string $name, int $type, string $profile, int $position, bool $used, bool $action): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $categoryID);
        if (!$used) {
            if ($id !== false) {
                IPS_DeleteVariable($id);
            }
            return 0;
        }
        if ($id === false) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $categoryID);
            IPS_SetIdent($id, $ident);
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        if ($profile !== '') {
            IPS_SetVariableCustomProfile($id, $profile);
        }
        if ($action) {
            IPS_SetVariableCustomAction($id, $this->InstanceID);
        }
        return $id;
    }

    private function catVarID(string $categoryIdent, string $ident): int
    {
        $catID = @$this->GetIDForIdent($categoryIdent);
        $id = $catID === false ? false : @IPS_GetObjectIDByIdent($ident, $catID);
        return $id === false ? 0 : $id;
    }

    private function statVarID(string $ident): int
    {
        return $this->catVarID('StatsCategory', $ident);
    }

    private function defaultZones(): array
    {
        $names = ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5', 'Rasen links', 'Rasen rechts'];
        return array_map(fn (string $name): array => [
            'Name' => $name, 'ValveInstanceID' => 0, 'TravelTime' => self::DEFAULT_TRAVEL, 'DefaultDuration' => 10,
            'Lawn' => str_starts_with($name, 'Rasen'), 'UseSensor' => false, 'SensorID' => 0, 'Threshold' => 60, 'Invert' => false
        ], $names);
    }

    // ======================================================================
    // Kachel-Visualisierung (HTML-SDK)
    // ======================================================================

    /** Liefert die Kachel samt aktuellem Zustand. */
    public function GetVisualizationTile(): string
    {
        $html = (string)file_get_contents(__DIR__ . '/visualization.html');
        return $html . '<script>handleMessage(' . json_encode(json_encode($this->visualizationState())) . ');</script>';
    }

    /** Sendet den Zustand an geöffnete Kacheln, sofern er sich geändert hat. */
    private function pushVisualization(): void
    {
        $state = $this->visualizationState();
        $key = md5(json_encode(array_diff_key($state, ['now' => 0])));
        if ($this->GetBuffer('VisuHash') === $key) {
            return;
        }
        $this->SetBuffer('VisuHash', $key);
        $this->UpdateVisualizationValue(json_encode($state));
    }

    private function visualizationState(): array
    {
        if (!$this->hasIdent('Status')) {
            return [];
        }
        $now = $this->now();
        $open = $this->openValves();
        $plan = $this->readJson('Plan');
        $manual = $this->readJson('Manual');

        // Schließzeitpunkt je Ventil (nächstes geplantes "Ventil zu")
        $closeAt = [];
        foreach ($plan as $op) {
            if ($op['op'] === 'valve' && !$op['on'] && !isset($closeAt[$op['id']])) {
                $closeAt[$op['id']] = $op['at'];
            } elseif ($op['op'] === 'close') {
                foreach ($this->zones()[$this->findZone((int)$op['ref']) ?? -1]['valves'] ?? [] as $v) {
                    $closeAt[$v['id']] ??= $op['at'] + $v['travel'];
                }
            }
        }

        $slots = $this->activeSlots();
        $lastRun = $this->readJson('LastRun');
        $upcoming = null;
        $zones = [];
        foreach ($this->zones() as $i => $z) {
            $next = $z['usable'] ? $this->nextRun($z['ref'], $slots, $lastRun) : null;
            if ($next !== null && ($upcoming === null || $next[0] < $upcoming['at'])) {
                $upcoming = ['at' => $next[0], 'seq' => $next[1], 'zones' => []];
            }
            if ($next !== null && $upcoming !== null && $next[0] === $upcoming['at']) {
                $upcoming['zones'][] = $z['name'];
            }
            $ids = array_column($z['valves'], 'id');
            $openIDs = array_values(array_intersect($ids, array_keys($open)));
            $since = $until = null;
            if ($openIDs) {
                $first = $openIDs[0];
                $since = $open[$first] - $this->maxTravel([$first]);
                $until = $closeAt[$first] ?? null;
            }
            $durID = $this->catVarID('ManualDurCategory', 'ManualDurationZ' . $i);
            $nextID = $this->catVarID('PlanCategory', 'NextRunZ' . $i);
            $zones[] = [
                'idx'      => $i,
                'name'     => $z['name'],
                'lawn'     => $z['lawn'],
                'usable'   => $z['usable'],
                'open'     => (bool)$openIDs,
                'manual'   => in_array($z['ref'], $manual, true),
                'minutes'  => $durID > 0 ? (int)GetValue($durID) : $z['duration'],
                'next'     => $nextID > 0 ? (string)GetValue($nextID) : '–',
                'since'    => $since,
                'until'    => $until
            ];
        }

        $sequences = [];
        for ($s = 1; $s <= self::MAX_SEQUENCES; $s++) {
            $ts = (int)$this->GetValue('StartTime' . $s);
            $sequences[] = [
                'n'     => $s,
                'name'  => $this->seqName($s),
                'auto'  => (bool)$this->GetValue('Auto' . $s),
                'start' => $ts > 0 ? date('H:i', $ts) : '',
                'rows'  => count(array_filter($this->sequenceRows($s), fn (array $r): bool => !empty($r['Active'])))
            ];
        }

        $valves = [];
        foreach ($this->physicalZones() as $i => $z) {
            $valves[] = ['phys' => $i, 'name' => $z['Name'], 'open' => isset($open[$z['ValveInstanceID']]), 'cycles' => (int)$this->statValue('CyclesP' . $i)];
        }

        $sensor = function (string $property, string $ident) {
            $id = $this->ReadPropertyInteger($property);
            return ($id > 0 && IPS_VariableExists($id)) ? $this->GetValue($ident) : null;
        };
        $notice = json_decode($this->GetBuffer('Notice'), true);

        return [
            'now'         => $now,
            'status'      => (string)$this->GetValue('Status'),
            'master'      => (bool)$this->GetValue('Active'),
            'maintenance' => $this->maintenance(),
            'sequence'    => (int)$this->GetValue('SeqControl'),
            'busy'        => $this->isBusy(),
            'startedAt'   => $plan ? ((int)$this->GetBuffer('RunStart') ?: null) : null,
            'endsAt'      => $plan ? max([...array_column($plan, 'at'), ...array_filter(array_column($zones, 'until'))]) : null,
            'upcoming'    => $upcoming === null ? null : [
                'at'    => $upcoming['at'],
                'day'   => $this->dayLabel($upcoming['at']),
                'time'  => date('H:i', $upcoming['at']),
                'name'  => $this->seqName($upcoming['seq']),
                'zones' => $upcoming['zones']
            ],
            'pump'        => $this->ReadAttributeInteger('PumpOnSince') > 0,
            'pumpFeedback' => $sensor('PumpStatusID', 'PumpStatus'),
            'pressure'    => $sensor('PressureSensorID', 'Pressure'),
            'flow'        => $sensor('FlowSensorID', 'Flow'),
            'maxParallel' => $this->maxParallel(),
            'zones'       => $zones,
            'sequences'   => $sequences,
            'valves'      => $valves,
            'stats'       => [
                'runtimeDay'   => (int)$this->statValue('RuntimeDay'),
                'runtimeTotal' => (float)$this->statValue('RuntimeTotal'),
                'waterLast'    => (float)$this->statValue('WaterLastRun'),
                'waterTotal'   => (float)$this->statValue('WaterTotal'),
                'hasFlow'      => $sensor('FlowSensorID', 'Flow') !== null
            ],
            'notice'      => is_array($notice) ? $notice : null
        ];
    }

    private function statValue(string $ident)
    {
        $id = $this->statVarID($ident);
        return $id > 0 ? GetValue($id) : 0;
    }

    private function isTime(string $value): bool
    {
        return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    // ======================================================================
    // Konfigurationsformular
    // ======================================================================

    public function GetConfigurationForm()
    {
        $zoneOptions = [['caption' => '– bitte wählen –', 'value' => 0]];
        foreach ($this->zones() as $i => $z) {
            if ($z['ref'] !== 0) {
                $zoneOptions[] = ['caption' => ($i + 1) . ': ' . $z['name'] . ($z['usable'] ? '' : ' (kein Ventil)'), 'value' => $z['ref']];
            }
        }
        $intervals = [['caption' => 'täglich', 'value' => 1]];
        for ($d = 2; $d <= 6; $d++) {
            $intervals[] = ['caption' => 'alle ' . $d . ' Tage', 'value' => $d];
        }
        $intervals[] = ['caption' => 'wöchentlich (alle 7 Tage)', 'value' => 7];

        $sequenceColumns = [
            ['caption' => 'Zone', 'name' => 'Zone', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $zoneOptions]],
            ['caption' => 'Dauer (0 = Standard der Zone verwenden)', 'name' => 'Duration', 'width' => '230px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'suffix' => ' min', 'minimum' => 0]],
            ['caption' => 'Intervall', 'name' => 'Interval', 'width' => '160px', 'add' => 1, 'edit' => ['type' => 'Select', 'options' => $intervals]],
            ['caption' => 'Parallel zur vorherigen Zone', 'name' => 'Parallel', 'width' => '210px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
            ['caption' => 'Aktiv', 'name' => 'Active', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']]
        ];

        $examples = ['Morgens', 'Abends', 'Mittags', 'Nachts'];
        $nameFields = [];
        $panels = [];
        $buttons = [];
        for ($s = 1; $s <= self::MAX_SEQUENCES; $s++) {
            $nameFields[] = ['type' => 'ValidationTextBox', 'name' => 'Seq' . $s . 'Name', 'caption' => 'Name Sequenz ' . $s . ' (z. B. "' . $examples[$s - 1] . '")'];
            $panels[] = [
                'type'    => 'ExpansionPanel',
                'caption' => $this->seqName($s) . ' – Reihenfolge = Bewässerungsreihenfolge',
                'items'   => [['type' => 'List', 'name' => 'Sequence' . $s, 'rowCount' => 7, 'add' => true, 'delete' => true, 'columns' => $sequenceColumns]]
            ];
            $buttons[] = ['type' => 'Button', 'caption' => $this->seqName($s) . ' jetzt starten', 'onClick' => 'BWS_StartSequence($id, ' . $s . ');'];
        }

        $zoneColumns = [
            ['caption' => 'Name', 'name' => 'Name', 'width' => '140px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
            ['caption' => 'Motorkugelhahn – KNX-Instanz ("Schalten", DPT1)', 'name' => 'ValveInstanceID', 'width' => '280px', 'add' => 0, 'edit' => ['type' => 'SelectInstance']],
            ['caption' => 'Verfahrzeit', 'name' => 'TravelTime', 'width' => '100px', 'add' => self::DEFAULT_TRAVEL, 'edit' => ['type' => 'NumberSpinner', 'suffix' => ' s', 'minimum' => 1]],
            ['caption' => 'Standard-Bewässerungsdauer', 'name' => 'DefaultDuration', 'width' => '190px', 'add' => 10, 'edit' => ['type' => 'NumberSpinner', 'suffix' => ' min', 'minimum' => 1]],
            ['caption' => 'Teil von „Rasen“ (läuft nacheinander)', 'name' => 'Lawn', 'width' => '210px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
            ['caption' => 'Bodenfeuchtesensor nutzen', 'name' => 'UseSensor', 'width' => '150px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
            ['caption' => 'Sensor-Variable', 'name' => 'SensorID', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
            ['caption' => 'Schwelle „feucht genug“', 'name' => 'Threshold', 'width' => '160px', 'add' => 60, 'edit' => ['type' => 'NumberSpinner', 'suffix' => ' %', 'minimum' => 0, 'maximum' => 100]],
            ['caption' => 'Skala invertiert', 'name' => 'Invert', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']]
        ];

        return json_encode([
            'elements' => [
                ['type' => 'SelectInstance', 'name' => 'PumpInstanceID', 'caption' => 'Pumpe – KNX-Instanz ("Schalten", DPT1)'],
                ['type' => 'SelectVariable', 'name' => 'PumpStatusID', 'caption' => 'Pumpenstatus-Rückmeldung (optional, vorhandene Variable)'],
                ['type' => 'SelectVariable', 'name' => 'PressureSensorID', 'caption' => 'Wasserdrucksensor (optional, vorhandene Variable)'],
                ['type' => 'SelectVariable', 'name' => 'FlowSensorID', 'caption' => 'Durchflusssensor (optional, vorhandene Variable)'],
                ['type' => 'ValidationTextBox', 'name' => 'LawnName', 'caption' => 'Anzeigename der zusammengelegten Rasen-Zone'],
                ...$nameFields,
                ['type' => 'List', 'name' => 'Zones', 'caption' => 'Physische Beregnungszonen', 'rowCount' => 7, 'add' => true, 'delete' => true, 'columns' => $zoneColumns],
                ...$panels,
                ['type' => 'NumberSpinner', 'name' => 'MaxParallel', 'caption' => 'Maximal gleichzeitig geöffnete Zonen', 'suffix' => ' Zonen', 'minimum' => 1, 'maximum' => self::MAX_OPEN],
                ['type' => 'NumberSpinner', 'name' => 'OverlapTime', 'caption' => 'Überlapp beim Zonenwechsel (mindestens die Verfahrzeit)', 'suffix' => ' s', 'minimum' => 0],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Regensperre (Weather Underground, optional)',
                    'items'   => [
                        ['type' => 'CheckBox', 'name' => 'WUEnabled', 'caption' => 'Regensperre aktiv – Sequenzstart bei Regen unterbinden'],
                        ['type' => 'Label', 'caption' => 'Regnet es beim Start einer Sequenz laut der gewählten PWS-Station, wird der Start unterbunden. Kostenlosen API-Key unter wunderground.com/member/api-keys anlegen (PWS erforderlich).'],
                        ['type' => 'ValidationTextBox', 'name' => 'WUStationID', 'caption' => 'PWS-Station-ID (z. B. IEUSKIRC12)'],
                        ['type' => 'PasswordTextBox', 'name' => 'WUApiKey', 'caption' => 'API-Key'],
                        ['type' => 'NumberSpinner', 'name' => 'WURainThreshold', 'caption' => 'Regenraten-Schwelle (0 = jeder Regen sperrt)', 'suffix' => ' mm/h', 'digits' => 1, 'minimum' => 0],
                        ['type' => 'Button', 'caption' => 'Verbindung testen', 'onClick' => 'BWS_WUTestConnection($id);']
                    ]
                ]
            ],
            'actions' => [
                ['type' => 'RowLayout', 'items' => $buttons],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Alles stoppen', 'onClick' => 'BWS_StopAll($id);'],
                    ['type' => 'Button', 'caption' => 'Zähler zurücksetzen', 'onClick' => 'BWS_ResetCounters($id);']
                ]]
            ],
            'status' => [
                ['code' => 201, 'icon' => 'error', 'caption' => 'Pumpe: KNX-Instanz fehlt oder existiert nicht.'],
                ['code' => 202, 'icon' => 'error', 'caption' => 'Mindestens eine Zone hat kein gültiges Ventil und wird nicht bewässert.'],
                ['code' => 203, 'icon' => 'error', 'caption' => 'Eine Ventil-Instanz ist mehreren Zonen zugeordnet.'],
                ['code' => 204, 'icon' => 'error', 'caption' => 'Eine aktive Sequenzzeile verweist auf keine vorhandene Zone.']
            ]
        ]);
    }
}
