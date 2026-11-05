<?php

declare(strict_types=1);

/**
 * Minimale Nachbildung der IP-Symcon-Umgebung für Offline-Tests.
 * KNX_WriteDPT1 prüft bei jedem Telegramm das Schaltschema.
 */

date_default_timezone_set('Europe/Berlin');

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KL_NOTIFY = 1;
const KL_WARNING = 3;
const KL_ERROR = 4;
const IS_ACTIVE = 102;
const IPS_KERNELMESSAGE = 10100;
const KR_INIT = 10101;
const KR_READY = 10103;
const VM_UPDATE = 10603;

final class Sim
{
    public static int $t = 0;
    public static int $runlevel = KR_READY;
    public static array $obj = [];
    public static int $nextId = 30000;
    public static array $instances = [];
    public static array $modules = [];
    public static array $profiles = [];
    public static array $watchers = [];     // varId => [instanceId]
    public static array $knx = [];          // [t, id, state]
    public static array $state = [];        // id => bool
    public static array $openedAt = [];     // valve id => t
    public static int $pumpOffAt = 0;
    public static array $fail = [];         // id => true: Telegramm schlägt fehl
    public static array $violations = [];
    public static int $writes = 0;
    public static int $pump = 10;
    public static array $valves = [];       // id => Label
    public static int $travel = 7;

    public static function reset(): void
    {
        self::$runlevel = KR_READY;
        self::$obj = [];
        self::$instances = [self::$pump => true];
        self::$modules = [];
        self::$watchers = [];
        self::$knx = [];
        self::$state = [];
        self::$openedAt = [];
        self::$pumpOffAt = 0;
        self::$fail = [];
        self::$violations = [];
        self::$writes = 0;
        self::$valves = [];
    }

    public static function create(int $parent, string $ident, string $name, $value): int
    {
        $id = self::$nextId++;
        self::$obj[$id] = ['parent' => $parent, 'ident' => $ident, 'name' => $name, 'value' => $value];
        return $id;
    }

    public static function child(int $parent, string $ident)
    {
        foreach (self::$obj as $id => $o) {
            if ($o['parent'] === $parent && $o['ident'] === $ident) {
                return $id;
            }
        }
        return false;
    }

    public static function label(int $id): string
    {
        return $id === self::$pump ? 'P' : (self::$valves[$id] ?? '#' . $id);
    }

    public static function openValves(): array
    {
        return array_keys(array_filter(array_intersect_key(self::$state, self::$valves)));
    }
}

function IPS_VariableProfileExists(string $n): bool { return isset(Sim::$profiles[$n]); }
function IPS_CreateVariableProfile(string $n, int $t): void { Sim::$profiles[$n] = $t; }
function IPS_DeleteVariableProfile(string $n): void { unset(Sim::$profiles[$n]); }
function IPS_SetVariableProfileText(string $n, string $p, string $s): void {}
function IPS_SetVariableProfileIcon(string $n, string $i): void {}
function IPS_SetVariableProfileValues(string $n, float $a, float $b, float $c): void {}
function IPS_SetVariableProfileDigits(string $n, int $d): void {}
function IPS_SetVariableProfileAssociation(string $n, $v, string $c, string $i, int $col): void {}
function IPS_SetVariableCustomProfile(int $id, string $p): void {}
function IPS_SetVariableCustomAction(int $id, int $a): void {}
function IPS_SetName(int $id, string $n): void { Sim::$obj[$id]['name'] = $n; }
function IPS_GetName(int $id): string { return Sim::$obj[$id]['name']; }
function IPS_SetPosition(int $id, int $p): void {}
function IPS_SetHidden(int $id, bool $h): void {}
function IPS_CreateCategory(): int { return Sim::create(0, '', '', null); }
function IPS_CreateVariable(int $t): int { return Sim::create(0, '', '', [false, 0, 0.0, ''][$t]); }
function IPS_SetParent(int $id, int $p): void { Sim::$obj[$id]['parent'] = $p; }
function IPS_SetIdent(int $id, string $i): void { Sim::$obj[$id]['ident'] = $i; }
function IPS_DeleteVariable(int $id): void { unset(Sim::$obj[$id]); }
function IPS_InstanceExists(int $id): bool { return isset(Sim::$instances[$id]); }
function IPS_VariableExists(int $id): bool { return isset(Sim::$obj[$id]); }
function IPS_GetKernelRunlevel(): int { return Sim::$runlevel; }
function IPS_SemaphoreEnter(string $n, int $ms): bool { return true; }
function IPS_SemaphoreLeave(string $n): void {}
function IPS_SetProperty(int $id, string $n, $v): void { Sim::$modules[$id]->props[$n] = $v; }
function IPS_ApplyChanges(int $id): void { Sim::$modules[$id]->ApplyChanges(); }
function GetValue(int $id) { return Sim::$obj[$id]['value']; }

function IPS_GetObjectIDByIdent(string $ident, int $parent)
{
    $id = Sim::child($parent, $ident);
    if ($id === false) {
        trigger_error('Ident ' . $ident . ' nicht gefunden', E_USER_WARNING);
    }
    return $id;
}

function SetValue(int $id, $v): bool
{
    Sim::$writes++;
    $old = Sim::$obj[$id]['value'];
    Sim::$obj[$id]['value'] = $v;
    foreach (Sim::$watchers[$id] ?? [] as $instance) {
        Sim::$modules[$instance]->MessageSink(Sim::$t, $id, VM_UPDATE, [$v, $v !== $old, $old, Sim::$t]);
    }
    return true;
}

function KNX_WriteDPT1(int $id, bool $state): bool
{
    $t = Sim::$t;
    Sim::$knx[] = [$t, $id, $state];
    if (!empty(Sim::$fail[$id])) {
        trigger_error('KNX: keine Antwort vom Gateway', E_USER_WARNING);
        return false;
    }
    $isPump = $id === Sim::$pump;
    $pumpOn = !empty(Sim::$state[Sim::$pump]);
    $fullyOpen = fn (array $ids): array => array_filter($ids, fn (int $v): bool => $t - Sim::$openedAt[$v] >= Sim::$travel);

    if ($isPump && $state && !$pumpOn && !$fullyOpen(Sim::openValves())) {
        Sim::$violations[] = "t=$t: Pumpe EIN ohne vollständig offenes Ventil";
    }
    if (!$isPump && !$state && !empty(Sim::$state[$id])) {
        $rest = array_diff(Sim::openValves(), [$id]);
        if ($pumpOn && !$fullyOpen($rest)) {
            Sim::$violations[] = "t=$t: " . Sim::label($id) . ' zu, obwohl die Pumpe läuft und kein anderes Ventil vollständig offen ist';
        }
        if (!$rest && !$pumpOn && $t - Sim::$pumpOffAt < Sim::$travel) {
            Sim::$violations[] = "t=$t: letztes Ventil " . Sim::label($id) . ' nur ' . ($t - Sim::$pumpOffAt) . ' s nach Pumpe aus geschlossen';
        }
    }
    if (!$isPump && $state && empty(Sim::$state[$id])) {
        Sim::$openedAt[$id] = $t;
    }
    if ($isPump && !$state && $pumpOn) {
        Sim::$pumpOffAt = $t;
    }
    Sim::$state[$id] = $state;
    if (count(Sim::openValves()) > 2) {
        Sim::$violations[] = "t=$t: " . count(Sim::openValves()) . ' Ventile gleichzeitig offen';
    }
    return true;
}

class IPSModule
{
    public int $InstanceID;
    public array $props = [];
    public array $attrs = [];
    public array $buffers = [];
    public array $timers = [];
    public array $messages = [];
    public array $log = [];
    public int $status = 0;

    public function __construct(int $id)
    {
        $this->InstanceID = $id;
        Sim::$instances[$id] = true;
        Sim::$modules[$id] = $this;
        Sim::$obj[$id] = ['parent' => 0, 'ident' => '', 'name' => 'Bewässerung', 'value' => null];
    }

    public function Create() {}
    public function ApplyChanges() {}
    public function Destroy() {}
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {}

    protected function RegisterPropertyInteger(string $n, int $v) { $this->props[$n] ??= $v; }
    protected function RegisterPropertyString(string $n, string $v) { $this->props[$n] ??= $v; }
    protected function RegisterPropertyBoolean(string $n, bool $v) { $this->props[$n] ??= $v; }
    protected function RegisterPropertyFloat(string $n, float $v) { $this->props[$n] ??= $v; }
    protected function ReadPropertyInteger(string $n): int { return (int)$this->props[$n]; }
    protected function ReadPropertyString(string $n): string { return (string)$this->props[$n]; }
    protected function ReadPropertyBoolean(string $n): bool { return (bool)$this->props[$n]; }
    protected function ReadPropertyFloat(string $n): float { return (float)$this->props[$n]; }

    protected function RegisterAttributeInteger(string $n, int $v) { $this->attrs[$n] ??= $v; }
    protected function RegisterAttributeString(string $n, string $v) { $this->attrs[$n] ??= $v; }
    protected function RegisterAttributeFloat(string $n, float $v) { $this->attrs[$n] ??= $v; }
    protected function RegisterAttributeBoolean(string $n, bool $v) { $this->attrs[$n] ??= $v; }
    protected function ReadAttributeInteger(string $n): int { return (int)$this->attrs[$n]; }
    protected function ReadAttributeString(string $n): string { return (string)$this->attrs[$n]; }
    protected function ReadAttributeFloat(string $n): float { return (float)$this->attrs[$n]; }
    protected function ReadAttributeBoolean(string $n): bool { return (bool)$this->attrs[$n]; }
    protected function WriteAttributeInteger(string $n, int $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeString(string $n, string $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeFloat(string $n, float $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeBoolean(string $n, bool $v) { $this->attrs[$n] = $v; }

    protected function GetBuffer(string $n): string { return $this->buffers[$n] ?? ''; }
    protected function SetBuffer(string $n, string $v) { $this->buffers[$n] = $v; }

    protected function RegisterTimer(string $n, int $ms, string $s) { $this->timers[$n] = ['ms' => $ms, 'next' => Sim::$t + intdiv($ms, 1000)]; }
    protected function SetTimerInterval(string $n, int $ms)
    {
        if (!isset($this->timers[$n])) {
            throw new Exception('Timer ' . $n . ' nicht registriert');
        }
        $this->timers[$n] = ['ms' => $ms, 'next' => Sim::$t + max(1, intdiv($ms, 1000))];
    }

    protected function RegisterMessage(int $sender, int $message)
    {
        $this->messages[$sender][$message] = true;
        if ($message === VM_UPDATE) {
            Sim::$watchers[$sender][$this->InstanceID] = $this->InstanceID;
        }
    }
    protected function UnregisterMessage(int $sender, int $message)
    {
        unset($this->messages[$sender][$message], Sim::$watchers[$sender][$this->InstanceID]);
    }
    protected function GetMessageList(): array
    {
        return array_map(fn (array $m): array => array_keys($m), $this->messages);
    }

    private function register(string $ident, string $name, $default): void
    {
        if (Sim::child($this->InstanceID, $ident) === false) {
            Sim::create($this->InstanceID, $ident, $name, $default);
        }
    }
    protected function RegisterVariableBoolean(string $i, string $n, string $p = '', int $pos = 0) { $this->register($i, $n, false); }
    protected function RegisterVariableInteger(string $i, string $n, string $p = '', int $pos = 0) { $this->register($i, $n, 0); }
    protected function RegisterVariableFloat(string $i, string $n, string $p = '', int $pos = 0) { $this->register($i, $n, 0.0); }
    protected function RegisterVariableString(string $i, string $n, string $p = '', int $pos = 0) { $this->register($i, $n, ''); }
    protected function MaintainVariable(string $i, string $n, int $type, string $p, int $pos, bool $keep)
    {
        $id = Sim::child($this->InstanceID, $i);
        if ($keep && $id === false) {
            Sim::create($this->InstanceID, $i, $n, [false, 0, 0.0, ''][$type]);
        } elseif (!$keep && $id !== false) {
            unset(Sim::$obj[$id]);
        }
    }
    protected function EnableAction(string $i) {}
    protected function GetIDForIdent(string $i)
    {
        $id = Sim::child($this->InstanceID, $i);
        if ($id === false) {
            trigger_error('Ident ' . $i . ' nicht gefunden', E_USER_WARNING);
        }
        return $id;
    }
    protected function SetValue(string $i, $v) { Sim::$writes++; Sim::$obj[Sim::child($this->InstanceID, $i)]['value'] = $v; return true; }
    protected function GetValue(string $i) { return Sim::$obj[Sim::child($this->InstanceID, $i)]['value']; }
    protected function SetStatus(int $s) { $this->status = $s; }
    protected function LogMessage(string $m, int $l) { $this->log[] = [Sim::$t, $l, $m]; }
}

/** Simuliert $seconds Sekunden Laufzeit inkl. Timer. */
function run(IPSModule $m, int $seconds): void
{
    $map = ['Queue' => 'ProcessQueue', 'Schedule' => 'CheckSchedule'];
    for ($i = 0; $i < $seconds; $i++) {
        Sim::$t++;
        foreach ($map as $timer => $method) {
            $t = $m->timers[$timer] ?? null;
            if ($t && $t['ms'] > 0 && Sim::$t >= $t['next']) {
                $m->timers[$timer]['next'] = Sim::$t + intdiv($t['ms'], 1000);
                $m->$method();
            }
        }
    }
}

/** IP-Symcon-Neustart: Buffer gehen verloren, Attribute und Variablen bleiben. */
function restart(IPSModule $m): void
{
    $m->buffers = [];
    Sim::$runlevel = KR_INIT;
    $m->Create();
    $m->ApplyChanges();
}
