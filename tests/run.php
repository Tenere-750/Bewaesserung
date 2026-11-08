<?php

declare(strict_types=1);

/**
 * Offline-Tests für das Bewässerungsmodul.
 * Aufruf:  php tests/run.php            (Exit-Code 0 = alle Tests bestanden)
 */

require __DIR__ . '/stubs.php';
require getenv('BWS_MODULE') ?: __DIR__ . '/../Bewaesserungssteuerung/module.php';

error_reporting(E_ALL & ~E_USER_WARNING);

final class TestModule extends Bewaesserungssteuerung
{
    protected function now(): int
    {
        return Sim::$t;
    }
}

const PUMP = 20010;
const T0 = 1794200400; // Montag, 09.11.2026 06:00 (Europe/Berlin)

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : "\n         " . $detail) . "\n";
}

function section(string $title): void
{
    echo "\n$title\n";
}

/** Ventil-Instanz der physischen Zone n (1-basiert). */
function valve(int $n): int
{
    return PUMP + $n;
}

function row(int $ref, int $minutes = 1, int $interval = 1, bool $parallel = false): array
{
    return ['Zone' => $ref, 'Duration' => $minutes, 'Interval' => $interval, 'Parallel' => $parallel, 'Active' => true];
}

/**
 * Neue Instanz mit 7 Zonen (5 Einzelzonen + Rasen aus Zone 6/7), Pumpe und
 * Sequenz 1 um 06:00. Logische Zonen: 0..4 = Zone 1..5, 5 = Rasen.
 */
function setup(array $seq1 = [], array $props = [], ?callable $zones = null, bool $auto = true, array $attrs = []): TestModule
{
    Sim::$pump = PUMP;
    Sim::reset();
    Sim::$t = T0 - 3600;
    $m = new TestModule(100);
    $m->Create();
    $rows = json_decode($m->props['Zones'], true);
    foreach ($rows as $i => &$z) {
        $z['ValveInstanceID'] = valve($i + 1);
        Sim::$instances[valve($i + 1)] = true;
        Sim::$valves[valve($i + 1)] = 'V' . ($i + 1);
    }
    unset($z);
    if ($zones) {
        $zones($rows);
    }
    $m->props['Zones'] = json_encode($rows);
    $m->props['PumpInstanceID'] = PUMP;
    $m->props['Sequence1'] = json_encode($seq1);
    $m->attrs['ZoneRefsMigrated'] = true;
    foreach ($props as $k => $v) {
        $m->props[$k] = $v;
    }
    foreach ($attrs as $k => $v) {
        $m->attrs[$k] = $v;
    }
    $m->ApplyChanges();
    setVar($m, 'StartTime1', T0);
    setVar($m, 'Auto1', $auto);
    setVar($m, 'Auto2', false);
    Sim::$knx = [];
    Sim::$writes = 0;
    return $m;
}

function varID(IPSModule $m, string $ident, string $category = ''): int
{
    $parent = $category === '' ? $m->InstanceID : Sim::child($m->InstanceID, $category);
    return (int)Sim::child($parent, $ident);
}

function val(IPSModule $m, string $ident, string $category = '')
{
    return GetValue(varID($m, $ident, $category));
}

function setVar(IPSModule $m, string $ident, $value): void
{
    Sim::$obj[varID($m, $ident)]['value'] = $value;
}

/** Schaltfolge relativ zu $t0; Wiederholungen gleichen Werts (Überwachung) entfallen. */
function events(int $t0, ?int $from = null): string
{
    $out = [];
    $last = [];
    foreach (Sim::$knx as [$t, $id, $state]) {
        $repeat = ($last[$id] ?? null) === $state;
        $last[$id] = $state;
        if ($repeat || ($from !== null && $t < $from)) {
            continue;
        }
        $out[] = Sim::label($id) . '@' . ($t - $t0) . '=' . (int)$state;
    }
    return implode(' ', $out);
}

function noViolations(): void
{
    check('Schaltschema eingehalten', !Sim::$violations, implode(' | ', array_unique(Sim::$violations)));
}

function idle(): bool
{
    return !Sim::openValves() && empty(Sim::$state[PUMP]);
}

/** Führt eine Bedienung aus und liefert die gemeldete Ablehnung ('' = ausgeführt). Exceptions sind ein Fehler. */
function rejects(IPSModule $m, callable $fn): string
{
    $before = count($m->log);
    try {
        $fn();
    } catch (Throwable $e) {
        return 'EXCEPTION: ' . $e->getMessage();
    }
    $messages = array_column(array_filter(array_slice($m->log, $before), fn ($l) => $l[1] === KL_NOTIFY), 2);
    return implode(' | ', $messages);
}

function logged(IPSModule $m, int $level, string $needle): bool
{
    foreach ($m->log as [, $l, $text]) {
        if ($l === $level && str_contains($text, $needle)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
section('1  Automatik-Sequenz: Zone 1, Zone 2, Rasen, Zone 4 (je 1 min)');
$m = setup([row(valve(1)), row(valve(2)), row(-1), row(valve(4))]);
run($m, 3600 + 600);
check(
    'exakte Schaltfolge',
    events(T0) === 'V1@0=1 P@7=1 V2@67=1 V1@77=0 P@137=0 V2@144=0 V6@144=1 P@151=1 V7@211=1 V6@221=0 P@281=0 V7@288=0 V4@288=1 P@295=1 P@355=0 V4@362=0',
    events(T0)
);
noViolations();
check('danach Leerlauf, Status "Bereit", Restlaufzeit 0', idle() && val($m, 'SeqControl') === 0 && val($m, 'Status') === 'Bereit' && val($m, 'Remaining') === 0);
check('Pumpenlaufzeit 320 s = 5 min', val($m, 'RuntimeDay', 'StatsCategory') === 5, (string)val($m, 'RuntimeDay', 'StatsCategory'));
check('je Kugelhahn genau 1 Zyklus', val($m, 'CyclesP0', 'StatsCategory') === 1 && val($m, 'CyclesP5', 'StatsCategory') === 1 && val($m, 'CyclesP6', 'StatsCategory') === 1);
check('Intervall-Speicher für alle 4 Zonen', count(json_decode($m->attrs['LastRun'], true)) === 4, $m->attrs['LastRun']);

section('2  Parallel-Gruppen (Zone 1+2, dann Zone 3+4)');
$m = setup([row(valve(1)), row(valve(2), 1, 1, true), row(valve(3)), row(valve(4), 1, 1, true)]);
run($m, 3600 + 300);
check('Schaltfolge mit höchstens 2 offenen Ventilen', events(T0) === 'V1@0=1 V2@0=1 P@7=1 V1@67=0 V3@67=1 V2@77=0 V4@77=1 P@137=0 V3@144=0 V4@144=0', events(T0));
noViolations();

section('3  Neustart von IP-Symcon mitten in der Sequenz');
$m = setup([row(valve(1), 5), row(valve(2), 5)]);
run($m, 3600 + 100);
check('vor dem Neustart läuft Zone 1', Sim::openValves() === [valve(1)] && !empty(Sim::$state[PUMP]));
restart($m);
$before = count(Sim::$knx);
run($m, 30);
check('vor KR_READY kein einziges Telegramm', count(Sim::$knx) === $before);
Sim::$runlevel = KR_READY;
$t = Sim::$t;
$m->MessageSink($t, 0, IPS_KERNELMESSAGE, [KR_READY]);
run($m, 60);
$after = events($t, $t);
check('nach KR_READY: Pumpe aus nach 10 s, alle Ventile zu nach 17 s', str_starts_with($after, 'P@10=0 V1@17=0 V2@17=0') && substr_count($after, '@17=0') === 7, $after);
check('Anlage aus, Sequenz zurückgesetzt', idle() && val($m, 'SeqControl') === 0 && val($m, 'Status') === 'Neustart: Bewässerung beendet.');
noViolations();
Sim::$t = T0 + 86400 - 5;
run($m, 30);
check('Automatik startet am nächsten Tag wieder', in_array(valve(1), Sim::openValves(), true));

section('4  Manuelle Zonen: Grenze von 2, auch während eine Zone schließt');
$m = setup([], ['ManualMaxMinutes' => 0], null, false);
Sim::$t = T0;
$m->RequestAction('ManualZ0', true);
run($m, 2);
$m->RequestAction('ManualZ1', true);
run($m, 2);
check('3. Zone abgelehnt', str_contains(rejects($m, fn () => $m->RequestAction('ManualZ2', true)), 'Maximal 2'));
run($m, 2);
$m->RequestAction('ManualZ0', false);
check('3. Zone abgelehnt, solange Zone 1 noch schließt', str_contains(rejects($m, fn () => $m->RequestAction('ManualZ2', true)), 'Maximal 2'));
run($m, 10);
check('danach angenommen', rejects($m, fn () => $m->RequestAction('ManualZ2', true)) === '');
run($m, 30);
$m->RequestAction('ManualZ1', false);
run($m, 5);
$m->RequestAction('ManualZ2', false);
run($m, 30);
check('Zone 1 schließt erst, wenn Zone 2 vollständig offen ist (t=9)', str_contains(events(T0), 'V1@9=0'), events(T0));
check('Pumpe erst mit der letzten Zone aus', idle() && substr_count(events(T0), 'P@') === 2, events(T0));
check('Schalter im WebFront zurückgesetzt', !val($m, 'ManualZ0') && !val($m, 'ManualZ1') && !val($m, 'ManualZ2'));
noViolations();

section('5  Manuelle Zone: Ablauf der Dauer, Schalter aus, Alles stoppen');
$m = setup([], [], null, false);
SetValue(varID($m, 'ManualDurationZ0', 'ManualDurCategory'), 1);
Sim::$t = T0;
$m->RequestAction('ManualZ0', true);
check('Schalter sofort an', val($m, 'ManualZ0') === true);
run($m, 120);
check('Dauer abgelaufen: Pumpe aus -> 7 s -> Ventil zu', events(T0) === 'V1@0=1 P@7=1 P@67=0 V1@74=0', events(T0));
check('Schalter danach aus', val($m, 'ManualZ0') === false);
noViolations();
$m = setup([], [], null, false);
Sim::$t = T0;
$m->RequestAction('ManualZ0', true);
run($m, 30);
$m->RequestAction('ManualZ0', false);
run($m, 30);
check('Schalter aus: Pumpe aus -> 7 s -> Ventil zu', events(T0) === 'V1@0=1 P@7=1 P@30=0 V1@37=0', events(T0));
$m = setup([], [], null, false);
Sim::$t = T0;
$m->RequestAction('ManualZ0', true);
run($m, 30);
$m->StopAll();
run($m, 30);
check('Alles stoppen: Pumpe aus -> 7 s -> Ventil zu', events(T0) === 'V1@0=1 P@7=1 P@30=0 V1@37=0', events(T0));
noViolations();

section('6  Manueller Rasen');
$m = setup([], [], null, false);
SetValue(varID($m, 'ManualDurationZ5', 'ManualDurCategory'), 1);
Sim::$t = T0;
$m->RequestAction('ManualZ5', true);
check('andere Zone während Rasen abgelehnt', str_contains(rejects($m, fn () => $m->RequestAction('ManualZ0', true)), 'Rasen'));
run($m, 200);
check('Kette rechts/links mit Überlapp', events(T0) === 'V6@0=1 P@7=1 V7@67=1 V6@77=0 P@137=0 V7@144=0', events(T0));
check('danach frei', val($m, 'ManualZ5') === false && $m->attrs['Manual'] === '[]');
noViolations();
$m = setup([], [], null, false);
Sim::$t = T0;
$m->RequestAction('ManualZ0', true);
check('Rasen abgelehnt, solange eine andere Zone läuft', str_contains(rejects($m, fn () => $m->RequestAction('ManualZ5', true)), 'exklusiv'));
$m = setup([], [], null, false);
SetValue(varID($m, 'ManualDurationZ5', 'ManualDurCategory'), 1);
Sim::$t = T0;
$m->RequestAction('ManualZ5', true);
run($m, 70);
$m->RequestAction('ManualZ5', false);
run($m, 30);
check('Abbruch im Überlapp: Pumpe aus, beide Teilventile zu', events(T0) === 'V6@0=1 P@7=1 V7@67=1 P@70=0 V6@77=0 V7@77=0', events(T0));
noViolations();

section('7  Zone ohne gültiges Ventil');
$m = setup([row(29999)], [], function (array &$rows): void {
    $rows[0]['ValveInstanceID'] = 29999; // Instanz existiert nicht
});
check('Statuscode 202', $m->status === 202, (string)$m->status);
run($m, 3600 + 60);
check('Sequenz überspringt die Zone, Pumpe bleibt aus', empty(Sim::$state[PUMP]) && str_contains(val($m, 'Status'), 'nicht konfiguriert'), val($m, 'Status'));
check('manueller Start abgelehnt', str_contains(rejects($m, fn () => $m->RequestAction('ManualZ0', true)), 'kein gültiges Ventil'));
check('Pumpe wurde nie eingeschaltet', !array_filter(Sim::$knx, fn ($e) => $e[1] === PUMP && $e[2]));

section('8  KNX-Fehler');
$m = setup([row(valve(1)), row(valve(2))]);
Sim::$fail[valve(1)] = true;
run($m, 3600 + 60);
check('Ventil öffnet nicht -> Pumpe nie an', !array_filter(Sim::$knx, fn ($e) => $e[1] === PUMP && $e[2]));
check('Abbruch mit Fehlerstatus und Log', str_starts_with(val($m, 'Status'), 'Fehler: Ventil') && logged($m, KL_ERROR, 'fehlgeschlagen') && val($m, 'SeqControl') === 0, val($m, 'Status'));
check('alle Ventile geschlossen, zweiter Versuch für das gestörte Ventil', !Sim::openValves() && count(array_filter(Sim::$knx, fn ($e) => $e[1] === valve(1) && !$e[2])) === 2);
noViolations();
$m = setup([row(valve(1))]);
Sim::$fail[PUMP] = true;
run($m, 3600 + 60);
check('Pumpe schaltet nicht -> Abbruch, Ventil zu', !Sim::openValves() && str_starts_with(val($m, 'Status'), 'Fehler: Pumpe'), val($m, 'Status'));

section('9  Pumpen-Rückmeldung');
$m = setup([row(valve(1), 10)]);
$fb = Sim::create(1, 'fb', 'Pumpe Rückmeldung', false);
$m->props['PumpStatusID'] = $fb;
$m->ApplyChanges();
run($m, 3600 + 120);
check('Rückmeldung bleibt AUS -> Befehl wiederholt, dann Abbruch', logged($m, KL_WARNING, 'schalte erneut ein') && str_contains(val($m, 'Status'), 'Pumpe meldet') && idle(), val($m, 'Status'));
noViolations();
$m = setup([row(valve(1), 3)]);
$fb = Sim::create(1, 'fb', 'Pumpe Rückmeldung', false);
$m->props['PumpStatusID'] = $fb;
$m->ApplyChanges();
run($m, 3600 + 8);
SetValue($fb, true);
run($m, 300);
check('Rückmeldung AN -> kein Abbruch, Anzeige gespiegelt', !logged($m, KL_ERROR, 'Pumpe') && val($m, 'Status') === 'Bereit' && val($m, 'PumpStatus') === true);

section('10 Überlapp 0 s eingestellt');
$m = setup([row(valve(1)), row(valve(2))], ['OverlapTime' => 0]);
run($m, 3600 + 200);
check('altes Ventil schließt trotzdem erst nach der Verfahrzeit (t=74)', str_contains(events(T0), 'V2@67=1 V1@74=0'), events(T0));
noViolations();

section('11 Intervall');
$m = setup([row(valve(1), 1, 3)], [], null, true);
$days = [];
for ($d = 0; $d < 7; $d++) {
    Sim::$t = T0 + $d * 86400 - 5;
    $n = count(Sim::$knx);
    run($m, 200);
    if (array_filter(array_slice(Sim::$knx, $n), fn ($e) => $e[1] === valve(1) && $e[2])) {
        $days[] = $d;
    }
}
check('alle 3 Tage: Tag 0, 3, 6', $days === [0, 3, 6], json_encode($days));
$m = setup([row(valve(1), 10, 2), row(valve(2), 10, 2)]);
run($m, 3600 + 20);
$m->StopAll();
run($m, 30);
check('Abbruch nach 20 s zählt nicht als bewässert', $m->attrs['LastRun'] === '[]' || $m->attrs['LastRun'] === '{}', $m->attrs['LastRun']);
Sim::$t = T0 + 86400 - 5;
run($m, 30);
check('Zone 1 läuft deshalb am nächsten Tag', in_array(valve(1), Sim::openValves(), true));

section('12 Zeitplan');
$m = setup([row(valve(1))]);
Sim::$t = T0 + 200; // Timer-Ticks verpasst
run($m, 10);
check('verpasster Start wird nachgeholt (06:03)', in_array(valve(1), Sim::openValves(), true));
$m->StopAll();
run($m, 60);
check('kein zweiter Start desselben Termins', idle());
$m->RequestAction('StartTime1', T0 + 300);
run($m, 30);
check('auf verstrichene Uhrzeit geändert: kein Sofortstart', idle());
$m->RequestAction('StartTime1', T0 + 900);
Sim::$t = T0 + 895;
run($m, 20);
check('auf spätere Uhrzeit geändert: startet dort', in_array(valve(1), Sim::openValves(), true));
$m = setup([row(valve(1))]);
Sim::$t = T0 + 905;
run($m, 20);
check('außerhalb des Nachholfensters kein Start', idle());

section('13 Sequenzstart während manueller Bewässerung');
$m = setup([row(valve(2))], [], null, true);
Sim::$t = T0 - 30;
$m->RequestAction('ManualZ0', true);
run($m, 30 + 60);
$ok = preg_match('/^V1@-30=1 P@-23=1 P@(\d+)=0 V1@(\d+)=0 V2@(\d+)=1 P@(\d+)=1/', events(T0), $mm) === 1
    && $mm[2] - $mm[1] === 7 && $mm[3] === $mm[2] && $mm[4] - $mm[3] === 7;
check('manuell erst geordnet beendet (Pumpe aus, 7 s, Ventil zu), dann Sequenz', $ok, events(T0));
noViolations();

section('14 Konfiguration geändert während Bewässerung');
$m = setup([row(valve(1), 5)]);
run($m, 3600 + 60);
$t = Sim::$t;
$m->ApplyChanges();
run($m, 20);
check('geordnet beendet', events($t, $t) === 'P@0=0 V1@7=0' && idle(), events($t, $t));

section('15 Stabile Zonen-Referenzen und Migration');
$m = setup([], [], null, true, ['ZoneRefsMigrated' => false, 'LastRun' => json_encode(['s1z0' => '2026-11-08', 's1z5' => '2026-11-07'])]);
// setup() setzt ZoneRefsMigrated; Altbestand daher erneut einspielen
$m->attrs['ZoneRefsMigrated'] = false;
$m->attrs['LastRun'] = json_encode(['s1z0' => '2026-11-08', 's1z5' => '2026-11-07']);
$m->props['Sequence1'] = json_encode([row(0), row(1), row(5)]);
$m->ApplyChanges();
$rows = json_decode($m->props['Sequence1'], true);
check('Sequenzzeilen von Position auf Ventil-Instanz umgestellt', array_column($rows, 'Zone') === [valve(1), valve(2), -1], json_encode(array_column($rows, 'Zone')));
check('Intervall-Speicher umgestellt', $m->attrs['LastRun'] === json_encode(['s1k' . valve(1) => '2026-11-08', 's1k-1' => '2026-11-07']), $m->attrs['LastRun']);
$m->ApplyChanges();
check('Migration läuft nur einmal', array_column(json_decode($m->props['Sequence1'], true), 'Zone') === [valve(1), valve(2), -1]);
$zones = json_decode($m->props['Zones'], true);
$zones[1]['Lawn'] = true; // Zone 2 wird Teil des Rasens
$m->props['Zones'] = json_encode($zones);
$m->ApplyChanges();
check('Zeile für Zone 2 ist danach ungültig (Status 204) statt auf eine andere Zone zu zeigen', $m->status === 204, (string)$m->status);
Sim::$t = T0 - 5;
run($m, 600);
$opened = array_values(array_unique(array_map(fn ($e) => Sim::label($e[1]), array_filter(Sim::$knx, fn ($e) => $e[1] !== PUMP && $e[2]))));
check('Zone 1 und Rasen (jetzt 3 Teile) laufen, nichts Falsches', $opened === ['V1', 'V2', 'V6', 'V7'], json_encode($opened));

section('16 Wasserverbrauch');
$m = setup([], [], null, false);
$flow = Sim::create(1, 'flow', 'Durchfluss', 0.0);
$m->props['FlowSensorID'] = $flow;
$m->ApplyChanges();
SetValue(varID($m, 'ManualDurationZ0', 'ManualDurCategory'), 1);
Sim::$t = T0;
$m->RequestAction('ManualZ0', true);
run($m, 7);
SetValue($flow, 12.0);
run($m, 60);
SetValue($flow, 0.0);
run($m, 30);
check('12 l/min über 60 s = 12 l', abs(val($m, 'WaterLastRun', 'StatsCategory') - 12.0) < 0.01 && abs(val($m, 'WaterTotal', 'StatsCategory') - 12.0) < 0.01, (string)val($m, 'WaterLastRun', 'StatsCategory'));
check('Durchfluss im WebFront gespiegelt', val($m, 'Flow') === 0.0);

section('17 Effizienz');
$m = setup([], [], null, true);
foreach (['PumpStatusID', 'PressureSensorID', 'FlowSensorID'] as $p) {
    $m->props[$p] = Sim::create(1, $p, $p, $p === 'PumpStatusID' ? false : 1.5);
}
$m->ApplyChanges();
Sim::$t = T0 + 3600;
Sim::$writes = 0;
run($m, 3600);
check('Leerlauf 1 h mit 3 Sensoren: höchstens 2 Variablen-Schreibzugriffe', Sim::$writes <= 2, Sim::$writes . ' Schreibzugriffe');

section('18 Konfigurationsformular');
$m = setup([row(valve(1))]);
$form = json_decode($m->GetConfigurationForm(), true);
check('gültiges JSON mit Statuscodes 201–204', is_array($form) && array_column($form['status'], 'code') === [201, 202, 203, 204]);
$panel = array_values(array_filter($form['elements'], fn ($e) => ($e['items'][0]['name'] ?? '') === 'Sequence1'))[0] ?? [];
$options = $panel['items'][0]['columns'][0]['edit']['options'] ?? [];
check('Zonenauswahl liefert stabile Referenzen', array_column($options, 'value') === [0, valve(1), valve(2), valve(3), valve(4), valve(5), -1], json_encode(array_column($options, 'value')));
$m = setup([], [], function (array &$rows): void {
    $rows[1]['ValveInstanceID'] = $rows[0]['ValveInstanceID'];
});
check('doppelte Ventil-Instanz -> Status 203', $m->status === 203);

section('19 Master-Schalter aus: keine Fehler, verständliche Rückmeldung');
$m = setup([row(valve(1))], [], null, false);
Sim::$t = T0;
$m->RequestAction('Active', false);
$msg = rejects($m, fn () => $m->RequestAction('ManualZ0', true));
check('Zone einschalten: keine Exception, Hinweis auf Wartungsmodus', !str_starts_with($msg, 'EXCEPTION') && str_contains($msg, 'Wartungsmodus'), $msg);
$switchedOn = fn () => (bool)array_filter(Sim::$knx, fn ($e) => $e[2]);
check('Hinweis steht im Status, Schalter bleibt aus, nichts eingeschaltet', str_contains(val($m, 'Status'), 'Master-Schalter ist aus') && val($m, 'ManualZ0') === false && !$switchedOn());
check('Rasen einschalten: keine Exception', !str_starts_with(rejects($m, fn () => $m->RequestAction('ManualZ5', true)), 'EXCEPTION'));
check('Zone ausschalten: keine Exception', rejects($m, fn () => $m->RequestAction('ManualZ0', false)) === '');
check('Sequenz starten: keine Exception, nichts eingeschaltet', !str_starts_with(rejects($m, fn () => $m->RequestAction('SeqControl', 1)), 'EXCEPTION') && !$switchedOn());
$m = setup([], [], null, false);
Sim::$t = T0;
$m->RequestAction('ManualZ0', true);
run($m, 20);
$m->RequestAction('Active', false);
$msg = rejects($m, fn () => $m->RequestAction('ManualZ0', true));
check('Zone wieder einschalten, während sie nach Master aus schließt: keine Exception', !str_starts_with($msg, 'EXCEPTION') && $msg !== '', $msg);
run($m, 30);
check('Master aus: Pumpe aus -> 7 s -> Ventil zu', events(T0) === 'V1@0=1 P@7=1 P@20=0 V1@27=0', events(T0));
noViolations();
$m = setup([row(valve(1), 5)]);
run($m, 3600 + 30);
$msg = rejects($m, fn () => $m->RequestAction('ManualZ0', false));
check('Zonen-Schalter während Sequenz: keine Exception, Hinweis', !str_starts_with($msg, 'EXCEPTION') && str_contains($msg, 'Sequenz'), $msg);

$m = setup([row(valve(1))]);
$m->RequestAction('Active', false);
Sim::$t = T0 - 5;
run($m, 300);
$m->RequestAction('Active', true);
run($m, 300);
check('Termin bei Master aus verfällt (kein Nachholen nach dem Einschalten)', idle() && !array_filter(Sim::$knx, fn ($e) => $e[2]));

section('20 Wartungsmodus (Leitungen ausblasen)');
$m = setup([row(valve(1), 5), row(valve(2), 5)]);
$fb = Sim::create(1, 'fb', 'Pumpe Rückmeldung', true);
$m->props['PumpStatusID'] = $fb;
$m->ApplyChanges();
run($m, 3600 + 60);
$t = Sim::$t;
$m->RequestAction('Maintenance', true);
SetValue($fb, false);
Sim::$allowManyOpen = true;
check('laufende Sequenz geordnet beendet: Pumpe sofort aus', events($t, $t) === 'P@0=0' && val($m, 'SeqControl') === 0, events($t, $t));
check('Wartungs-Schalter erst nach dem Abschalten bedienbar', str_contains(rejects($m, fn () => $m->RequestAction('ServiceV0', true)), 'warten'));
run($m, 10);
check('Ventil nach Verfahrzeit zu, Status "Wartungsmodus aktiv"', events($t, $t) === 'P@0=0 V1@7=0' && str_starts_with(val($m, 'Status'), 'Wartungsmodus aktiv'), events($t, $t) . ' / ' . val($m, 'Status'));
check('Wartungs-Kategorie sichtbar', empty(Sim::$hidden[Sim::child($m->InstanceID, 'MaintenanceCategory')]));
$t = Sim::$t;
foreach ([0, 1, 2, 5, 6] as $phys) {
    $m->RequestAction('ServiceV' . $phys, true);
}
$opened = array_map(fn ($id) => Sim::label($id), Sim::openValves());
check('Kugelhähne einzeln schaltbar, auch mehr als 2 und Rasen-Teilventile', $opened === ['V1', 'V2', 'V3', 'V6', 'V7'], json_encode($opened));
check('Wartungs-Schalter zeigen den Zustand', val($m, 'ServiceV0', 'MaintenanceCategory') === true && val($m, 'ServiceV3', 'MaintenanceCategory') === false);
check('Zyklen werden gezählt', val($m, 'CyclesP0', 'StatsCategory') === 2);
run($m, 600);
check('Pumpe bleibt aus (10 min, inkl. Überwachung)', !array_filter(Sim::$knx, fn ($e) => $e[0] >= $t && $e[1] === PUMP && $e[2]));
check('Pumpe wird regelmäßig ausgeschaltet', count(array_filter(Sim::$knx, fn ($e) => $e[0] >= $t && $e[1] === PUMP && !$e[2])) >= 9);
check('Ventile bleiben offen (kein Zeitlimit)', count(Sim::openValves()) === 5);
check('normale Zonen-Schalter gesperrt', str_contains(rejects($m, fn () => $m->RequestAction('ManualZ3', true)), 'Wartungsmodus'));
check('Sequenzstart gesperrt', !str_starts_with(rejects($m, fn () => $m->RequestAction('SeqControl', 1)), 'EXCEPTION') && str_contains(val($m, 'Status'), 'Wartungsmodus'));
Sim::$t = T0 + 86400 - 5;
run($m, 30);
check('Automatik startet im Wartungsmodus nicht', count(Sim::openValves()) === 5 && val($m, 'SeqControl') === 0);
SetValue($fb, true);
$n = count(Sim::$knx);
run($m, 10);
check('Rückmeldung "Pumpe an" -> sofort Aus-Befehl', (bool)array_filter(array_slice(Sim::$knx, $n), fn ($e) => $e[1] === PUMP && !$e[2]) && logged($m, KL_WARNING, 'Wartungsmodus AN'));
SetValue($fb, false);
$m->RequestAction('Active', false);
check('Master aus im Wartungsmodus schließt die Kugelhähne nicht', count(Sim::openValves()) === 5);
$m->RequestAction('Active', true);
$m->RequestAction('ServiceV1', false);
check('einzelnen Kugelhahn schließen', !in_array(valve(2), Sim::openValves(), true));
$t = Sim::$t;
$m->RequestAction('Maintenance', false);
Sim::$allowManyOpen = false;
run($m, 600);
check('Wartungsmodus aus: alle Kugelhähne zu, Pumpe aus, Sequenz von 06:00 nicht nachgeholt', idle() && !array_filter(Sim::$knx, fn ($e) => $e[0] >= $t && $e[1] === PUMP && $e[2]) && val($m, 'Status') === 'Bereit', events($t, $t));
check('Wartungs-Schalter danach außerhalb des Modus gesperrt', str_contains(rejects($m, fn () => $m->RequestAction('ServiceV0', true)), 'nur im Wartungsmodus') && idle());
$t = Sim::$t;
$m->RequestAction('ManualZ0', true);
run($m, 10);
check('danach normaler Betrieb mit Pumpe', events($t, $t) === 'V1@0=1 P@7=1', events($t, $t));
noViolations();
$m->StopAll();
run($m, 10);

$m = setup([], [], null, false);
Sim::$t = T0;
$m->RequestAction('Maintenance', true);
run($m, 2);
$m->RequestAction('ServiceV0', true);
restart($m);
Sim::$runlevel = KR_READY;
$t = Sim::$t;
$m->MessageSink($t, 0, IPS_KERNELMESSAGE, [KR_READY]);
run($m, 30);
check('Neustart im Wartungsmodus: Kugelhähne zu, Modus bleibt aktiv', idle() && val($m, 'Maintenance') === true && !array_filter(Sim::$knx, fn ($e) => $e[0] >= $t && $e[1] === PUMP && $e[2]));
check('Skriptfunktion BWS_SetMaintenance', (function () use ($m) { $m->SetMaintenance(false); return val($m, 'Maintenance') === false; })());

section('21 Stromausfall an Ventilen und Pumpe (NC-Kugelhähne)');
// Hähne fahren stromlos zu, die Aktoren kommen auf "Aus" zurück; das Modul erfährt davon nichts.
$m = setup([row(valve(1), 10)]);
run($m, 3600 + 120);
$t = Sim::$t;
Sim::$state = [];
Sim::$knx = [];
Sim::$violations = [];
run($m, 120);
$after = events($t, $t);
check('Überwachung öffnet erst die Ventile, Pumpe frühestens nach der Verfahrzeit', preg_match('/^V1@(\d+)=1 P@(\d+)=1/', $after, $mm) === 1 && $mm[2] - $mm[1] >= 7, $after);
noViolations();
$m = setup([], ['ManualMaxMinutes' => 0], null, false);
Sim::$t = T0;
$m->RequestAction('ManualZ0', true);
for ($i = 0; $i < 200 && !array_filter(json_decode($m->attrs['Plan'], true), fn ($op) => $op['src'] === 'sys'); $i++) {
    run($m, 1);
}
$t = Sim::$t;
$m->RequestAction('ManualZ0', false);
run($m, 30);
check('Zone direkt nach einer Wiederholung gestoppt: Pumpe startet nicht erneut', events($t, $t) === 'P@0=0 V1@7=0', events($t, $t));
noViolations();

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
