# Bewässerungssteuerung für IP-Symcon 9 (PHP 8.5) – KNX-Variante

PHP-Modul für eine Beregnungsanlage mit KNX-geschalteter Pumpe und
KNX-Motorkugelhähnen, optionalem Bodenfeuchtesensor je Zone und einer
Rasen-Zusammenlegung von zwei physischen Zonen zu einem logischen Kreis.

## Funktionen

- Bis zu 12 physische Beregnungszonen (Standard-Belegung: 7), Name frei wählbar
- Verfahrzeit des Motorkugelhahns **pro Zone** einstellbar (Standard 7 s)
- **KNX-Ansteuerung**: Pumpe und Motorkugelhähne werden über
  `KNX_WriteDPT1($InstanzID, $Wert)` geschaltet. `$InstanzID` ist dabei die
  KNX-Geräteinstanz („Schalten", DPT 1.001), die im KNX-Konfigurator bzw.
  per ETS-Gruppenadress-Import bereits für die jeweilige Gruppenadresse
  angelegt wurde – im Instanz-Editor einfach per Objektbaum auswählen
- **Rasen-Zusammenlegung**: zwei der physischen Zonen (Standard: „Rasen
  links" und „Rasen rechts") lassen sich per Haken „Teil von Rasen" zu
  einem einzigen logischen Kreis zusammenfassen. Im WebFront und in den
  Sequenzen erscheint dafür nur noch ein Schalter/Eintrag „Rasen" (Name
  über „Anzeigename der zusammengelegten Rasen-Zone" änderbar). Schema:
  **Ventil 1 auf → Verfahrzeit warten → Pumpe an → Ventil 2 auf →
  Überlapp warten → Ventil 1 zu → Pumpe aus → Verfahrzeit warten →
  Ventil 2 zu** — entspricht exakt dem normalen Sequenz-Übergang
  zwischen zwei unterschiedlichen Zonen, nur innerhalb einer einzigen
  logischen Zone angewendet: die beiden Teilventile sind während der
  konfigurierten Überlapp-Zeit kurz gemeinsam offen, davor und danach
  läuft immer nur ein Teilventil. „Rasen" läuft dabei immer exklusiv,
  ohne dass gleichzeitig eine andere Zone geöffnet ist (der eigene
  Überlapp beansprucht bereits bis zu 2 Ventile gleichzeitig). Grenzt
  „Rasen" in einer Sequenz an eine andere Zone, wird diese davor ganz
  regulär beendet bzw. danach ganz regulär neu gestartet – ein
  „Parallel"-Haken einer benachbarten Zeile wird für „Rasen" ignoriert,
  da eine Kopplung mit einer DRITTEN Zone die maximale Anzahl gleichzeitig
  offener Ventile überschreiten würde. Die Zyklenzähler bleiben trotzdem
  **pro physischem Kugelhahn** getrennt,
  weil beide Ventile unabhängig voneinander verschleißen.
- **Optionaler Bodenfeuchtesensor je physischer Zone**: Haken „Bodenfeuchte-
  sensor nutzen" aktivieren, vorhandene Sensor-Variable auswählen und
  Schwellenwert („feucht genug ab …") festlegen. Ist der Boden laut Sensor
  feucht genug, wird die Zone in der **Automatik** automatisch
  übersprungen — die **manuelle** Bewässerung ignoriert den Sensor bewusst
  (wenn man von Hand gießt, will man das auch tun). Bei der zusammen-
  gelegten Rasen-Zone genügt es, wenn **eine** der beiden Teilflächen
  trocken meldet, dann wird gegossen (siehe „Sensor-Logik im Detail" unten).
- Master-Schalter
- Optionale Anzeige des **echten Pumpenstatus** (KNX-Rückmeldung des
  Pumpenaktors) im WebFront direkt unter „Status" – zeigt, ob die Pumpe
  wirklich läuft, unabhängig von der internen Annahme des Moduls;
  Abweichungen werden zusätzlich im Meldungsfenster protokolliert
- Optionale Anzeige von **Wasserdruck** und **Durchfluss** im WebFront
  (zwischen „Status" und „Startzeit Sequenz 1"), gespeist aus je einer
  frei wählbaren, bereits vorhandenen Sensor-Variable
- **Restlaufzeit** im WebFront (direkt unter „Durchfluss"): verbleibende
  Zeit bis zum vollständigen Abschluss der aktuell laufenden
  Automatik-Sequenz oder manuellen Bewässerung
- Manuelle Steuerung jeder (logischen) Zone im WebFront, **mit direkt im
  WebFront einstellbarer Bewässerungsdauer je Kreis** – die Zone schaltet
  nach Ablauf automatisch wieder ab, statt unbegrenzt offen zu bleiben
- Zwei Automatik-Sequenzen pro Tag (morgens/abends), Startzeit im WebFront einstellbar
- Reihenfolge der Zonen **pro Sequenz** frei definierbar (= Zeilenreihenfolge in der Liste)
- **Bewässerungsdauer je Kreis konfigurierbar**: jede Zone hat eine eigene
  Standard-Bewässerungsdauer (in der Zonenliste). Diese wird in Sequenz 1
  und 2 automatisch vorbelegt, lässt sich dort aber pro Zeile für einzelne
  Läufe überschreiben (z. B. morgens kürzer, abends länger)
- Bewässerungs-**Intervall pro Zone und Sequenz**, als klare Auswahl von
  „täglich" bis „wöchentlich" (alle 2, 3, 4, 5, 6 oder 7 Tage); die Sequenz
  überspringt automatisch alle Zonen, die heute nicht fällig sind, und
  rückt in der konfigurierten Reihenfolge zur nächsten fälligen Zone vor
- Maximal 2 Zonen gleichzeitig geöffnet — **auch innerhalb einer Sequenz**:
  Zonen lassen sich per Haken „Parallel zur vorherigen Zone" zu Zweier-
  gruppen koppeln, die gemeinsam bewässert werden; der Gruppenwechsel ist
  so gestaffelt, dass nie mehr als 2 Ventile gleichzeitig offen sind
- Einschalt-Schema: **Ventil auf → Verfahrzeit warten → Pumpe an**
- Ausschalt-Schema: **Pumpe aus → Verfahrzeit warten → Ventil zu**
- Zonenwechsel in der Sequenz: **Ventil n+1 auf → 10 s Überlapp → Ventil n zu**
  (die Pumpe läuft dabei durch, sie arbeitet nie gegen komplett geschlossene Ventile)
- Pumpenlaufzeit **heute** (Minuten) und **gesamt** (Stunden) im WebFront
- **Laufzeit je Kreis** (heute in Minuten, gesamt in Stunden): gezählt wird
  die Zeit, in der die Pumpe läuft **und** die jeweilige Zone offen ist –
  also die tatsächliche Bewässerungszeit, nicht die reine Ventil-Offen-Zeit
  inklusive Verfahrzeiten. Bei „Rasen" wird die Laufzeit als Ganzes für
  den zusammengelegten Kreis erfasst (inklusive der kurzen Überlapp-Zeit
  beim Wechsel zwischen den Teilflächen).
- **Wasserverbrauch** (Liter): Summe der letzten Sequenz/manuellen
  Bewässerung sowie Gesamtsumme über alle Läufe — berechnet aus
  Durchfluss × Zeit, sekündlich für 20 s nach jedem Ventil-Öffnen, danach
  alle 10 s (siehe unten)
- Zyklenzähler pro Motorkugelhahn (jedes Öffnen = 1 Zyklus)

Alle Wartezeiten laufen **nicht blockierend** über eine Timer-gesteuerte
Schritt-Warteschlange – kein `IPS_Sleep`, keine hängenden PHP-Threads.

## Voraussetzungen

- IP-Symcon 9 (PHP 8.5)
- Das offizielle **KNX-Modul** von Symcon ist installiert und ein
  KNX-Gateway/-Schnittstelle ist eingerichtet und verbunden
- Für jede Gruppenadresse, die dieses Modul schalten soll (Pumpe, jeder
  Motorkugelhahn), existiert bereits eine eigene KNX-Geräteinstanz vom Typ
  „Schalten" (DPT 1.001) im Objektbaum — entweder per XML-/ETS-Import oder
  manuell über den KNX-Konfigurator angelegt
- Optional: Bodenfeuchtesensoren sind bereits als gewöhnliche IP-Symcon-
  Variable eingebunden (z. B. über eine eigene KNX-DPT-9-Geräteinstanz, die
  automatisch eine Statusvariable mit dem Messwert führt)

## Installation

1. Ordner `Bewaesserung` in das Symcon-Modulverzeichnis kopieren, z. B.
   - Windows: `C:\ProgramData\Symcon\modules\Bewaesserung`
   - Linux/Raspberry: `/var/lib/symcon/modules/Bewaesserung`
   - alternativ: als Git-Repository über die Modulverwaltung
     (Kern-Instanzen → Modules → „+" → Modulquelle hinzufügen → Repo-URL)
     einbinden, dann sind spätere Updates ein Klick
2. Symcon-Objektbaum: **Modules** neu laden lassen (die Modulverwaltung
   erledigt das i. d. R. automatisch nach dem Kopieren)
3. **Instanz hinzufügen → „Bewaesserungssteuerung"** an gewünschter Stelle
   im Objektbaum anlegen

## Konfiguration (Instanz-Editor)

1. **Pumpe – KNX-Instanz**: die zuvor angelegte „Schalten"-Geräteinstanz
   (DPT1) der Pumpen-Gruppenadresse im Objektbaum auswählen
2. **Pumpenstatus-Rückmeldung** (optional): vorhandene IP-Symcon-Variable
   auswählen, die den tatsächlichen Ein/Aus-Zustand des Pumpenaktors per
   KNX-Rückmeldung führt. Damit lässt sich im WebFront jederzeit prüfen,
   ob die Pumpe wirklich läuft — unabhängig von der internen Annahme des
   Moduls. Weicht der gemeldete Wert vom intern angenommenen Zustand ab,
   wird das zusätzlich im Meldungsfenster protokolliert. Ohne Auswahl
   bleibt die Anzeige beim zuletzt bekannten Wert stehen.
3. **Wasserdrucksensor / Durchflusssensor** (beide optional): vorhandene
   IP-Symcon-Variable auswählen, die den jeweiligen Messwert führt (z. B.
   eine KNX-DPT9-Geräteinstanz mit eigener Statusvariable). Der Wert wird
   alle 10 Sekunden in die WebFront-Anzeigen „Wasserdruck"/„Durchfluss"
   gespiegelt. Ohne Auswahl bleiben beide Anzeigen bei 0.
4. **Anzeigename der zusammengelegten Rasen-Zone**: Standard „Rasen",
   nach Wunsch änderbar
5. **Physische Beregnungszonen**: pro Zeile
   - **Name** — frei wählbar
   - **Motorkugelhahn – KNX-Instanz** — die „Schalten"-Geräteinstanz (DPT1)
     dieses Ventils auswählen
   - **Verfahrzeit** — Verfahrzeit des Motorkugelhahns in Sekunden
   - **Standard-Bewässerungsdauer** — die übliche Dauer, mit der diese
     Zone bewässert wird. Dient als Vorgabe für neue Sequenz-Zeilen (dort
     lässt sie sich pro Zeile überschreiben) und wird auch für die
     manuelle Bedienung von „Rasen" verwendet (siehe unten)
   - **Teil von „Rasen"** — ankreuzen, wenn diese Zone mit anderen
     „Rasen"-Zonen zu einem gemeinsamen Kreis zusammengefasst werden soll
     (im Standard sind „Rasen links" und „Rasen rechts" schon markiert).
     Bei „Rasen" gilt die Standard-Bewässerungsdauer der **ersten** als
     „Lawn" markierten Zeile für den ganzen zusammengelegten Kreis (je
     Teilfläche, siehe unten)
   - **Bodenfeuchtesensor nutzen** — optional aktivieren
   - **Sensor-Variable** — vorhandene IP-Symcon-Variable mit dem
     Feuchtemesswert auswählen (nur relevant, wenn Sensor genutzt wird)
   - **Schwelle „feucht genug"** — ab diesem Wert gilt der Boden als
     ausreichend feucht und die Zone wird in der Automatik übersprungen
   - **Skala invertiert** — aktivieren, wenn beim eingesetzten Sensor ein
     **niedrigerer** Messwert **feuchteren** Boden bedeutet (z. B. manche
     resistiven/Rohwert-Sensoren); Standard ist „hoher Wert = feucht"
   - Die Zeilenreihenfolge bestimmt die spätere Zonennummer (nach der
     Rasen-Zusammenlegung)
6. **Sequenz 1 / Sequenz 2**: pro Zeile logische Zone, Dauer, Intervall
   (Tage), „Parallel zur vorherigen Zone" und Aktiv-Haken. Die
   Zeilenreihenfolge ist die Bewässerungsreihenfolge. Die Zonen-Auswahl
   zeigt bereits die logischen Kreise nach der Rasen-Zusammenlegung.
   **Dauer**: der Wert **0** bedeutet „Standard-Bewässerungsdauer der Zone
   verwenden" (so ist jede neu angelegte Zeile voreingestellt); ein Wert
   **> 0** überschreibt die Dauer nur für diese eine Zeile — praktisch,
   wenn dieselbe Zone morgens kürzer und abends länger laufen soll.
   Dieselbe Zone darf in beiden Sequenzen zusätzlich mit unterschiedlichen
   Intervallen stehen. **Wichtig bei „Rasen"**: die wirksame Dauer gilt
   **je Teilfläche** (Rasen links UND Rasen rechts laufen jeweils so
   lange) – die tatsächliche Gesamtlaufzeit ist also etwa doppelt so lang
   plus Verfahrzeiten. Der „Parallel"-Haken hat für „Rasen" keine Wirkung.
7. Übernehmen. Die WebFront-Variablen werden automatisch angelegt.

## Bedienung im WebFront

Die Instanz gliedert sich in zwei Bereiche: die **Steuerung** direkt bei der
Instanz und eine Unterkategorie **„Statistik"** mit allen Laufzeiten und
Zyklenzählern – so sind Bedienelemente und Auswertung im WebFront klar
getrennt.

### Steuerung

| Variable | Funktion |
|---|---|
| Master-Schalter | Aus = sofortiger geordneter Stopp, Automatik gesperrt |
| Automatik-Sequenz | Buttons: Stopp / Sequenz 1 / Sequenz 2 (manueller Start) |
| Status | Klartext, was gerade passiert (inkl. wegen Feuchte übersprungener Zonen) |
| Pumpenstatus (Rückmeldung) | reine Anzeige (an/aus); zeigt die tatsächliche KNX-Rückmeldung des Pumpenaktors, alle 10 s aktualisiert. Weicht sie vom intern angenommenen Zustand ab, wird das zusätzlich im Meldungsfenster protokolliert (siehe „Pumpen-Status: Absicherung gegen Status-Desync" unten) |
| Wasserdruck | reine Anzeige (bar); zeigt den Wert der unter „Wasserdrucksensor" verknüpften Variable, alle 10 s aktualisiert |
| Durchfluss | reine Anzeige (l/min); zeigt den Wert der unter „Durchflusssensor" verknüpften Variable, alle 10 s aktualisiert |
| Restlaufzeit | reine Anzeige (Minuten, aufgerundet); verbleibende Zeit bis zum vollständigen Abschluss der aktuell laufenden Automatik-Sequenz **oder** manuellen Bewässerung. 0, wenn nichts aktiv ist. Aktualisiert sich bei jeder Zustandsänderung sofort sowie zusätzlich alle 10 s |
| Startzeit Sequenz 1/2 | Uhrzeit-Eingabe für den Automatikstart |
| Automatik Sequenz 1/2 | Automatikstart einzeln aktivieren/deaktivieren |
| „…" – manuelle Dauer | pro Kreis direkt im WebFront einstellbar (Minuten); bestimmt, wie lange die Zone beim nächsten manuellen Einschalten bewässert wird. Voreingestellt mit der Standard-Bewässerungsdauer aus der Zonenkonfiguration, hier aber jederzeit ohne Formular anpassbar |
| Zonen-Schalter | manuelle Bewässerung: öffnet die Zone und schließt sie **automatisch** nach Ablauf der oben eingestellten Dauer wieder (Pumpe wird dabei geordnet mitgeschaltet). Vorzeitiges Ausschalten bricht sauber ab. „Rasen" arbeitet dabei die komplette Kette ab (Teilfläche 1 → kurzer Überlapp → Teilfläche 2, jeweils für die eingestellte Dauer je Teilfläche) und schaltet sich danach selbst wieder aus |

### Statistik (Unterkategorie)

| Variable | Funktion |
|---|---|
| Pumpenlaufzeit heute/gesamt | wird live aktualisiert |
| „…" – Laufzeit heute/gesamt | je Kreis, tatsächliche Bewässerungszeit (Pumpe an + Ventil offen) |
| Zyklen Kugelhahn | Öffnungszyklen je physischem Ventil (auch innerhalb „Rasen" getrennt) |
| Wasserverbrauch letzte Laufzeit | Summe über alle Kreise der letzten Sequenz bzw. manuellen Bewässerung (Liter); zählt live mit, solange bewässert wird |
| Wasserverbrauch gesamt | Summe über alle Kreise und alle Laufzeiten (Liter) |

Details zur Berechnung und zum Messtakt des Wasserverbrauchs siehe
Abschnitt „Wasserverbrauch" weiter unten.

## Sensor-Logik im Detail

- Ohne aktivierten Sensor wird eine Zone immer bewässert, wenn sie laut
  Intervall fällig ist.
- Mit aktiviertem Sensor: die Zone wird nur übersprungen, wenn der
  Messwert die konfigurierte Schwelle in Richtung „feucht genug" erreicht.
- Bei der zusammengelegten Rasen-Zone mit zwei Sensoren gilt eine
  ODER-Verknüpfung: sobald **eine** der beiden Teilflächen als trocken
  gemeldet wird, wird die gesamte Rasen-Zone bewässert (beide Ventile
  öffnen gemeinsam, es gibt keine getrennte Bewässerung nur einer Hälfte).
- Die manuelle Bedienung im WebFront ignoriert den Sensor immer – ein
  manueller Wunsch zu gießen wird nicht durch die Sensorik blockiert.
- Empfehlung: den Schwellenwert und ggf. den Invertiert-Haken einmal mit
  dem tatsächlichen Sensor-Messbereich abgleichen (z. B. über die
  Variablenhistorie beobachten, wie sich der Wert bei Regen/Trockenheit
  verhält), bevor die Automatik unbeaufsichtigt läuft.

## Hinweise zur Ablauflogik

- **Intervall**: Für jede logische Zone wird je Sequenz der letzte
  Bewässerungstag gespeichert. Eine Zone ist fällig, wenn seit dem
  letzten Lauf mindestens `Intervall` Tage vergangen sind (Intervall 1 =
  täglich, 2 = jeden 2. Tag …).
- **Parallelbetrieb in der Sequenz**: Zonen mit „Parallel zur vorherigen
  Zone" laufen als Zweiergruppe (Gruppendauer = längere der beiden
  Dauern). Beim Gruppenwechsel wird zuerst ein Ventil der alten Gruppe
  geschlossen, dann das erste neue geöffnet, nach dem Überlapp das letzte
  alte geschlossen und das zweite neue geöffnet — so sind nie mehr als
  2 Ventile gleichzeitig offen und die Pumpe hat immer mindestens ein
  offenes Ventil.
- **„Rasen" ist exklusiv**: Der eigene Überlapp-Übergang von „Rasen"
  beansprucht kurzzeitig bis zu 2 gleichzeitig offene Ventile – deshalb
  lässt sich „Rasen" manuell nur starten, wenn gerade **keine** andere
  Zone offen ist, und solange „Rasen" läuft, lässt sich keine andere Zone
  manuell zusätzlich öffnen (das würde die maximale Anzahl gleichzeitig
  offener Ventile überschreiten). Beide Fälle quittiert das Modul mit
  einer klaren Fehlermeldung im WebFront/Skript statt stillschweigend
  etwas Falsches zu tun. In der Automatik-Sequenz wird das automatisch
  berücksichtigt (siehe oben).
- **Manuell + Automatik**: Startet eine Sequenz, während manuell bewässert
  wird, wird zuerst geordnet gestoppt (Pumpe aus → Verfahrzeit → Ventile
  zu) und dann die Sequenz gefahren. Während einer laufenden Sequenz ist
  die manuelle Bedienung gesperrt (erst „Stopp" drücken).
- **Tageswechsel**: Die Tageslaufzeit wird um Mitternacht automatisch
  zurückgesetzt; die Gesamtlaufzeit läuft weiter.
- Im Instanz-Editor gibt es Buttons zum sofortigen Starten/Stoppen der
  Sequenzen und zum Zurücksetzen der Zähler. Per Skript:
  `BWS_StartSequence(<InstanzID>, 1|2);`, `BWS_StopAll(<InstanzID>);`,
  `BWS_ResetCounters(<InstanzID>);`

## Pumpen-/Ventil-Schema: Prüfung und Absicherung

Auf ausdrücklichen Wunsch wurde die gesamte Schaltlogik daraufhin geprüft,
dass in **jedem** Pfad (manuell einzeln, manuell mehrfach, Automatik-
Sequenz, Abbruch, automatisches Abschalten) durchgängig gilt:

- **Einschalten**: Ventil auf → Verfahrzeit warten → Pumpe an
- **Ausschalten**: Pumpe aus → Verfahrzeit warten → Ventil zu
- Sind **mehrere Zonen gleichzeitig offen**, wird die Pumpe **erst
  ausgeschaltet, wenn die jeweils letzte noch offene Zone geschlossen
  wird** – nie vorher, egal in welcher Reihenfolge oder zu welchem
  Zeitpunkt die einzelnen Zonen fertig werden.

Dabei wurde ein echter Fehler gefunden und behoben: Bewässerungsdauern
liefen bisher über dieselbe serielle, instanzweite Warteschlange wie die
kurzen Verfahrzeit-Wartezeiten. Wurde während eine Zone noch bewässerte
eine **zweite** Zone manuell geöffnet, blieb deren Schaltbefehl bis zum
Ablauf der Wartezeit der ersten Zone in der Warteschlange hängen, statt
sofort ausgeführt zu werden.

**Lösung**: Bewässerungsdauern laufen jetzt vollständig unabhängig von der
seriellen Warteschlange. Diese wird nur noch für die kurzen, tatsächlich
seriellen Schalt-Choreografien genutzt (Verfahrzeiten, Sequenz-Überlapp,
„Rasen"-Kette). Ob eine Zone nach Ablauf ihrer Dauer automatisch schließen
soll, wird stattdessen alle 10 Sekunden unabhängig geprüft (Funktion
`checkManualDeadlines`) und ermittelt bei jedem Schließvorgang frisch, ob
gerade noch andere Zonen offen sind – nur wenn nicht, wird zusätzlich die
Pumpe (mit Verfahrzeit-Wartezeit davor) mit abgeschaltet. Damit können
mehrere manuell geöffnete Zonen unabhängig voneinander zur richtigen Zeit
schließen, ohne dass sich die Pumpe verfrüht abschaltet oder eine zweite
Zone blockiert bleibt. Toleranz dieser Prüfung: bis zu 10 Sekunden – für
Bewässerungsdauern im Minutenbereich unkritisch.

## Pumpen-Status: Absicherung gegen Status-Desync

Das Modul merkt sich intern (Attribut „PumpOnSince"), ob die Pumpe gerade
läuft, um unnötige Ein-/Ausschaltbefehle zu vermeiden und die Laufzeit
korrekt zu berechnen. Da KNX-Telegramme ohne Rückmeldung gesendet werden
(„Fire and forget"), kann dieser interne Merker im Ausnahmefall von der
Realität abweichen – z. B. wenn ein Vorgang durch einen Symcon-Neustart
mitten in der Ausführung unterbrochen wurde oder ein Telegramm den Bus
nicht erreicht hat. Ohne Gegenmaßnahme hätte das zur Folge, dass die Pumpe
bei der nächsten Zone gar nicht mehr angeschaltet wird (das Modul denkt
„läuft schon"), oder umgekehrt beim Stoppen nicht mehr ausgeschaltet wird
(„läuft ja laut Merker gar nicht").

Deshalb gilt seit dieser Version:

- Der Pumpe-**an**-Befehl wird bei jeder manuellen Zonenaktivierung und bei
  jedem Sequenzstart **immer** gesendet, unabhängig vom internen Merker.
  Ein zusätzliches Ein-Telegramm an eine bereits laufende Pumpe ist
  wirkungslos, schließt aber zuverlässig die Lücke, falls der Merker
  einmal nicht stimmt.
- Der Pumpe-**aus**-Befehl bei „Alles stoppen" bzw. beim Schließen der
  jeweils letzten offenen Zone wird ebenfalls **immer** gesendet – „Alles
  stoppen" ist damit ein zuverlässiger Notausschalter, auch wenn der
  interne Merker fälschlich „aus" anzeigt.

Sollte einmal der Eindruck entstehen, dass die Pumpe bei einer bestimmten
Zone nicht reagiert, hilft in jedem Fall ein Klick auf „Alles stoppen" (im
Instanz-Editor als Button verfügbar, im WebFront über die Automatik-
Sequenz-Auswahl „Stopp") – das setzt den internen Zustand zuverlässig
zurück.

### Fast gleichzeitiges Einschalten mehrerer Zonen

Werden zwei Zonen manuell innerhalb weniger Sekunden nacheinander
eingeschaltet (bevor die erste Zone ihre eigene Verfahrzeit-Wartezeit
abgeschlossen hat), prüft das Modul seit dieser Version anhand der Liste
der bereits offenen Zonen – nicht mehr anhand des zeitlich nachlaufenden
„PumpOnSince"-Merkers – ob schon eine Öffnungs-Choreografie läuft. Das
verhindert, dass zwei fast zeitgleich gestartete Zonen sich gegenseitig
mit doppelten, in falscher Reihenfolge verarbeiteten Schalt-Schritten in
die Quere kommen.

### Diagnose im Meldungsfenster

Für den Fall, dass es trotzdem einmal zu unerwartetem Pumpenverhalten
kommt, protokolliert das Modul jetzt jeden Pumpen-Ein-/Ausschaltbefehl
sowie jedes automatische Schließen einer abgelaufenen manuellen Zone im
Symcon-Meldungsfenster (inkl. der zu diesem Zeitpunkt offenen Zonen bzw.
der verstrichenen/eingestellten Dauer). Damit lässt sich der genaue
Auslöser im Nachhinein nachvollziehen.

## Pumpen-Watchdog, Laufzeit-Vorschau, Sequenznamen

**Pumpen-Watchdog (Reaktion auf "Sequenz lief, Pumpe aber nicht"):**
Solange laut interner Buchführung bewässert wird, überwacht ein Watchdog
im 10-Sekunden-Takt den Pumpenzustand: Meldet die (optionale)
Rückmeldevariable "aus", obwohl die Pumpe seit über 15 Sekunden laufen
müsste, wird der Einschaltbefehl sofort erneut gesendet und eine Warnung
protokolliert. Zusätzlich wird der Einschaltbefehl während der Bewässerung
vorsorglich einmal pro Minute wiederholt — bei laufender Pumpe wirkungslos,
fängt aber ein verlorenes KNX-Telegramm auch ohne Rückmeldevariable ab.
Falls das Verhalten erneut auftritt: bitte den Meldungsfenster-Auszug rund
um den Startzeitpunkt notieren (Einträge "Pumpe AN/AUS" und Watchdog-
Warnungen zeigen exakt, was passiert ist).

**Nächste Laufzeiten je Kreis:** Neue Unterkategorie "Nächste Laufzeiten"
(im WebFront oberhalb der Zonen-Schalter) mit einer Zeile pro Kreis, z. B.
"heute, 19:00 (Abends)" oder "Freitag, 06:00 (Morgens)". Berechnet aus
Intervall, letztem Lauf und den Startzeiten der aktivierten Sequenzen —
also der spätestmögliche nächste Termin; ein Bodenfeuchtesensor kann die
Zone an dem Tag weiterhin überspringen (nicht vorhersagbar). Deaktivierte
Automatik-Sequenzen werden nicht berücksichtigt; ohne aktiven
Sequenz-Eintrag zeigt der Kreis "–".

**Frei wählbare Sequenznamen:** Im Instanz-Editor lassen sich beide
Sequenzen benennen (z. B. "Morgens"/"Abends"). Der Name erscheint überall:
auf den Auswahl-Buttons im WebFront, bei "Startzeit …"/"Automatik …", in
den Status-Texten, in der Nächste-Laufzeit-Anzeige, in den Panel-
Überschriften und Buttons des Instanz-Editors. Technisch nutzt die
Sequenz-Auswahl dafür ein instanzspezifisches Variablenprofil, das beim
Löschen der Instanz automatisch mit entfernt wird.

**Manuelle Laufzeiten gruppiert:** Die Dauer-Felder liegen jetzt in einer
eigenen Unterkategorie "Manuelle Laufzeit" und tragen nur noch den
Zonennamen — im WebFront also genau die gewünschte Darstellung
(Überschrift "Manuelle Laufzeit", darunter Buchenhecke, Ölweide, Rasen, …).
Bestehende Werte werden beim ersten Übernehmen automatisch aus den alten
Variablen übernommen (Migration), die alten Variablen werden entfernt.

## Wasserverbrauch

Zwei neue Statistik-Größen — bewusst **nicht** pro Zone, sondern als zwei
Summenwerte:

- **„Wasserverbrauch letzte Laufzeit"** (Liter): Summe über alle Kreise,
  die an der letzten Automatik-Sequenz oder der letzten manuellen
  Bewässerung beteiligt waren. Läuft gerade bewässert, zählt der Wert
  live mit; danach bleibt er stehen, bis die nächste Sequenz oder
  manuelle Bewässerung beginnt (dann startet er wieder bei 0).
- **„Wasserverbrauch gesamt"** (Liter): Summe über alle Kreise und alle
  Laufzeiten seit Installation bzw. letztem Zurücksetzen.

Eine „Laufzeit" in diesem Sinne ist der durchgehende Zeitraum, in dem die
Pumpe läuft — von „Pumpe an" bis „Pumpe wieder aus". Das deckt sowohl eine
komplette Automatik-Sequenz (inkl. aller Zonenwechsel darin) als auch eine
manuelle Bewässerung ab, auch wenn dabei mehrere Zonen nacheinander oder
gleichzeitig dazugeschaltet wurden — alle zählen in dieselbe Summe.

**Berechnung:** Verbrauch = Durchfluss (l/min) × Zeit, auf Basis der unter
„Durchflusssensor" verknüpften Variable (siehe oben). Ohne konfigurierten
Durchflusssensor bleiben beide Anzeigen bei 0.

**Zeittakt der Probenahme:** Ein eigener, sich selbst steuernder Timer
nimmt bei jedem Ventil-Öffnen-Ereignis (auch beim Zonenwechsel innerhalb
einer Sequenz und bei der „Rasen"-Kette) für die folgenden 20 Sekunden
**sekündlich** eine Probe, danach alle 10 Sekunden — bis keine Zone mehr
offen ist, dann schaltet er sich automatisch ab. Das gilt unabhängig
davon, ob die Zone manuell oder automatisch geöffnet wurde.

*Warum dieser Takt?* Nach dem Öffnen eines Motorkugelhahns braucht der
Durchfluss eine Weile, bis er sich stabilisiert hat — erfahrungsgemäß rund
20 Sekunden. In dieser Einschwingphase schwankt der Messwert noch spürbar,
deshalb wird dort **fein (sekündlich)** gemessen, damit der tatsächlich
geflossene Verbrauch trotz der Schwankung genau erfasst wird. Sobald sich
der Durchfluss nach etwa 20 Sekunden eingependelt hat, genügt der gröbere
10-Sekunden-Takt, um Rechenlast zu sparen, ohne an Genauigkeit zu
verlieren. Jede Probe rechnet dabei den aktuellen Durchfluss mit der
tatsächlich seit der letzten Probe vergangenen Zeit hoch — der 1-Sekunden-
und der 10-Sekunden-Takt liefern also denselben aufsummierten Verbrauch,
nur mit unterschiedlich feiner Auflösung. Weil jeder Zonenwechsel (neues
Ventil öffnet) den 20-Sekunden-Zähler neu startet, wird auch die
Einschwingphase jedes folgenden Kreises sekündlich erfasst.

Der Reset-Button „Zähler zurücksetzen" setzt die Gesamtsumme zurück; die
Summe der aktuellen/letzten Laufzeit bleibt unberührt, da sie sich beim
nächsten Sequenz- bzw. manuellen Start ohnehin automatisch auf 0 setzt.

## Bekannte Annahmen / Grenzen

- Es wird angenommen, dass jede zu schaltende Gruppenadresse als eigene
  KNX-Geräteinstanz mit Unit „1.001 Schalten" vorliegt und
  `KNX_WriteDPT1($InstanzID, $Wert)` unterstützt (Standardverhalten des
  aktuellen Symcon-KNX-Moduls). Sollte eine andere Symcon-KNX-Modul-
  version eine abweichende Funktionssignatur verwenden, muss nur die
  private Methode `knxSwitch()` in `module.php` angepasst werden – der
  Rest der Ablauflogik bleibt unberührt.
- Rückmeldeadressen/Ist-Zustände der Ventile werden nicht ausgewertet;
  das Modul geht von erfolgreichem Schalten aus und führt den Zustand
  intern nach (Attribut „Open"). Bei Bedarf lässt sich das um eine
  Rückmeldeauswertung erweitern.

## Änderungshistorie (Kurzfassung)

- **3.2.x** – Wasserverbrauch als zwei Summen (letzte Laufzeit / gesamt),
  Messung sekündlich in den ersten 20 s nach Ventilöffnung (Einschwingen
  des Durchflusses), danach alle 10 s.
- **3.0** – Pumpen-Watchdog, „Nächste Laufzeiten"-Vorschau je Kreis, frei
  wählbare Sequenznamen, manuelle Laufzeiten in eigener Unterkategorie.
- **2.9** – Vollständiges Code-Audit. Behobene Race-Condition beim
  Schließen der letzten Zone (Entscheidung über das Pumpe-aus fällt jetzt
  erst zur Ausführungszeit über den „close_zone"-Schritt, siehe Abschnitt
  „Pumpen-/Ventil-Schema"). Zonen-Konfiguration wird pro Aufruf nur noch
  einmal geparst (Cache).
- **2.8** – Pumpenstatus-Rückmeldung (optionale KNX-Variable) mit
  Abweichungswarnung; Fix des Wertebereichs für editierbare Minuten-Felder.
- **2.x** – Grundfunktionen: Sequenzsteuerung, Rasen-Zusammenlegung,
  Intervall- und Sensorlogik, Statistik, manuelle Steuerung.
