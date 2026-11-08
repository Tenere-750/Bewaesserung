# Bewässerungssteuerung für IP-Symcon 9 (PHP 8.5) – KNX-Variante

PHP-Modul für eine Beregnungsanlage mit KNX-geschalteter Pumpe und
KNX-Motorkugelhähnen, optionalem Bodenfeuchtesensor je Zone und einer
Rasen-Zusammenlegung mehrerer physischer Zonen zu einem logischen Kreis.

## Funktionen

- Bis zu 12 physische Beregnungszonen (Standard-Belegung: 7), Name frei wählbar
- Verfahrzeit des Motorkugelhahns **pro Zone** einstellbar (Standard 7 s)
- **KNX-Ansteuerung**: Pumpe und Motorkugelhähne werden über
  `KNX_WriteDPT1($InstanzID, $Wert)` geschaltet. `$InstanzID` ist die
  KNX-Geräteinstanz („Schalten", DPT 1.001) der jeweiligen Gruppenadresse
- **Rasen-Zusammenlegung**: alle Zonen mit Haken „Teil von Rasen" bilden
  einen logischen Kreis (Standard: „Rasen links" und „Rasen rechts"). Die
  Teilventile laufen nacheinander mit Überlapp; „Rasen" läuft immer
  exklusiv. Die Zyklenzähler bleiben pro physischem Kugelhahn getrennt
- **Optionaler Bodenfeuchtesensor je Zone**: ist der Boden feucht genug,
  wird die Zone in der Automatik übersprungen (manuell nicht)
- Master-Schalter
- **Wartungsmodus** zum Ausblasen der Leitungen: Pumpe gesperrt, jeder
  Kugelhahn einzeln schaltbar
- **Vier Automatik-Sequenzen** pro Tag mit frei wählbarem Namen, eigener
  Startzeit und eigenem Automatik-Schalter; Zonenreihenfolge, Dauer,
  Intervall (täglich bis wöchentlich) und „Parallel"-Kopplung je Zeile
- Manuelle Steuerung jeder Zone im WebFront mit einstellbarer Dauer, danach
  automatisches Abschalten
- Höchstens 2 Ventile gleichzeitig offen, manuell wie in der Sequenz
- Optionale **Regensperre über Weather Underground**
- Optionale Anzeige von Pumpen-Rückmeldung, Wasserdruck und Durchfluss
- Restlaufzeit, Pumpenlaufzeit (heute/gesamt), Wasserverbrauch (letzter
  Lauf/gesamt), Zyklenzähler je Kugelhahn, nächste Laufzeit je Kreis

### Schaltschema

| Vorgang | Ablauf |
|---|---|
| Einschalten | Ventil auf → Verfahrzeit → Pumpe an |
| Zonenwechsel in der Sequenz | nächstes Ventil auf → Überlapp → vorheriges Ventil zu (Pumpe läuft durch) |
| Ausschalten (Sequenzende, manuelles Ende, Stopp, Fehler) | Pumpe aus → Verfahrzeit → Ventil zu |

Der Überlapp beträgt mindestens die Verfahrzeit des neuen Ventils, auch
wenn ein kleinerer Wert eingestellt ist – das alte Ventil schließt erst,
wenn das neue vollständig offen ist. Die Pumpe läuft nie ohne vollständig
offenes Ventil.

## Voraussetzungen

- IP-Symcon 9 (PHP 8.5)
- Das offizielle **KNX-Modul** von Symcon ist installiert und ein
  KNX-Gateway ist eingerichtet und verbunden
- Für jede Gruppenadresse, die das Modul schalten soll (Pumpe, jeder
  Motorkugelhahn), existiert eine eigene KNX-Geräteinstanz vom Typ
  „Schalten" (DPT 1.001) – per ETS-Import oder KNX-Konfigurator angelegt
- Optional: Bodenfeuchte-, Druck- und Durchflusssensoren sowie die
  Pumpen-Rückmeldung als gewöhnliche IP-Symcon-Variablen

## Installation

1. Über die Modulverwaltung (Kern-Instanzen → Modules → „+") die URL
   dieses Repositorys hinzufügen, oder den Ordner in das Modulverzeichnis
   kopieren (`C:\ProgramData\Symcon\modules\` bzw. `/var/lib/symcon/modules/`)
2. **Instanz hinzufügen → „Bewaesserungssteuerung"**

Ein Update bitte nur einspielen, während nicht bewässert wird.

## Konfiguration (Instanz-Editor)

1. **Pumpe – KNX-Instanz**: „Schalten"-Geräteinstanz der Pumpe
2. **Pumpenstatus-Rückmeldung** (optional): Variable mit dem tatsächlichen
   Zustand des Pumpenaktors. Wird für Anzeige und Überwachung genutzt
   (siehe „Überwachung und Fehler")
3. **Wasserdrucksensor / Durchflusssensor** (optional): Variablen mit den
   Messwerten. Sie werden bei jeder Messwertänderung gespiegelt, der
   Durchfluss ergibt den Wasserverbrauch
4. **Anzeigename der Rasen-Zone**: Standard „Rasen"
5. **Physische Beregnungszonen**: pro Zeile
   - **Name**
   - **Motorkugelhahn – KNX-Instanz** (jede Instanz nur einmal verwenden)
   - **Verfahrzeit** in Sekunden
   - **Standard-Bewässerungsdauer** in Minuten (Vorgabe für Sequenzzeilen
     mit Dauer 0 und für die manuelle Bedienung; bei „Rasen" gilt der Wert
     der ersten Rasen-Zeile je Teilfläche)
   - **Teil von „Rasen"**
   - **Bodenfeuchtesensor nutzen**, **Sensor-Variable**, **Schwelle
     „feucht genug"**, **Skala invertiert** (niedriger Wert = feucht)
6. **Sequenz 1 bis 4**: pro Zeile Zone, Dauer (0 = Standard der Zone),
   Intervall, „Parallel zur vorherigen Zone" und Aktiv. Die
   Zeilenreihenfolge ist die Bewässerungsreihenfolge. Bei „Rasen" gilt die
   Dauer je Teilfläche; „Parallel" hat für „Rasen" keine Wirkung.
7. **Maximal gleichzeitig geöffnete Zonen** (1 oder 2) und **Überlapp**
8. Übernehmen – die WebFront-Variablen werden angelegt.

Sequenzzeilen merken sich die Zone über deren Ventil-Instanz („Rasen"
über eine feste Kennung), nicht über die Position in der Zonenliste.
Zeilen umsortieren oder eine Zone zum Rasen hinzufügen verschiebt daher
keine Sequenz auf eine andere Zone; eine Zeile, deren Zone nicht mehr
existiert, wird übersprungen und im Instanzstatus gemeldet.

### Instanzstatus

| Code | Bedeutung |
|---|---|
| 201 | Pumpen-Instanz fehlt oder existiert nicht |
| 202 | Mindestens eine Zone hat kein gültiges Ventil (wird nicht bewässert) |
| 203 | Eine Ventil-Instanz ist mehreren Zonen zugeordnet |
| 204 | Eine aktive Sequenzzeile verweist auf keine vorhandene Zone |

## Bedienung im WebFront

### Steuerung

| Variable | Funktion |
|---|---|
| Master-Schalter | Aus = sofortiger geordneter Stopp, Automatik gesperrt |
| Wartungsmodus (Pumpe gesperrt) | siehe „Wartungsmodus" |
| Automatik-Sequenz | Stopp / Sequenz 1–4 sofort starten |
| Status | Klartext, was gerade passiert |
| Pumpenstatus (Rückmeldung) | Anzeige der verknüpften Rückmelde-Variable |
| Wasserdruck, Durchfluss | Anzeige der verknüpften Sensoren |
| Restlaufzeit | Minuten bis zum Ende der laufenden Bewässerung |
| Automatik / Startzeit je Sequenz | Automatikstart ein/aus, Uhrzeit |
| Zonen-Schalter | manuelle Bewässerung für die eingestellte Dauer; Ausschalten bricht geordnet ab. „Rasen" läuft die komplette Kette ab |

Abgelehnte Bedienungen (z. B. Zone einschalten bei ausgeschaltetem
Master-Schalter) erscheinen mit Begründung im Status und im
Meldungsfenster; der Schalter bleibt aus.

Die Sequenzblöcke sind nach Startzeit sortiert; Sequenzen mit
ausgeschalteter Automatik stehen am Ende, ihre Startzeit ist ausgeblendet.

Unterkategorien:

- **Manuelle Laufzeit**: Dauer je Kreis (Minuten) für den Zonen-Schalter
- **Nächste Laufzeiten**: frühester kommender Termin je Kreis aus
  Intervall, letztem Lauf und den aktiven Sequenzen (ohne Bodenfeuchte)
- **Statistik**: Pumpenlaufzeit heute/gesamt, Zyklen je Kugelhahn,
  Wasserverbrauch letzte Laufzeit/gesamt
- **Wartung – Kugelhähne**: ein Schalter je physischem Kugelhahn, nur im
  Wartungsmodus sichtbar

## Wartungsmodus (Leitungen ausblasen)

Zum Ausblasen der Leitungen vor dem Winter lassen sich die Kugelhähne
verfahren, ohne dass die Pumpe anläuft:

1. **Wartungsmodus** einschalten. Eine laufende Bewässerung wird geordnet
   beendet (Pumpe aus → Verfahrzeit → Ventile zu); danach erscheint die
   Unterkategorie „Wartung – Kugelhähne".
2. Kugelhähne dort einzeln öffnen und schließen – ohne Pumpe, ohne
   Zeitlimit und ohne Grenze für gleichzeitig offene Hähne, auch die
   Teilventile von „Rasen" einzeln. Die Zyklenzähler zählen weiter.
3. **Wartungsmodus** ausschalten: alle Kugelhähne schließen, danach
   normaler Betrieb.

Solange der Wartungsmodus aktiv ist:

- Die Pumpe wird nie eingeschaltet; das Modul sendet jede Minute einen
  Aus-Befehl, bei Rückmeldung „an" sofort.
- Automatik, Sequenzstart und die normalen Zonen-Schalter sind gesperrt.
  Termine in dieser Zeit verfallen und werden nicht nachgeholt.
- Der Master-Schalter schließt keine Kugelhähne.
- Durchfluss (Luft) zählt nicht zum Wasserverbrauch.
- Nach einem Neustart von IP-Symcon werden alle Kugelhähne geschlossen;
  der Wartungsmodus bleibt eingeschaltet.

Per Skript: `BWS_SetMaintenance(<InstanzID>, true|false);`

## Ablauflogik

- **Intervall**: Je Sequenz und Zone wird der Tag der letzten
  vollständigen Bewässerung gespeichert. Fällig ist eine Zone, wenn
  seitdem mindestens `Intervall` Tage vergangen sind. Ein abgebrochener
  Lauf zählt nicht.
- **Parallel**: Zonen mit „Parallel zur vorherigen Zone" laufen als
  Zweiergruppe (Dauer = längere der beiden). Beim Gruppenwechsel schließt
  zuerst ein altes Ventil, dann öffnet das erste neue, nach dem Überlapp
  schließt das letzte alte und öffnet das zweite neue.
- **„Rasen" ist exklusiv**: Vor und nach „Rasen" wird die Pumpe geordnet
  abgeschaltet; manuell lässt sich „Rasen" nur starten, wenn keine andere
  Zone offen ist, und solange es läuft, keine weitere Zone.
- **Mehrere manuelle Zonen**: Die Grenze gilt für reservierte und noch
  schließende Zonen. Schließt eine Zone, während eine andere offen bleibt,
  wartet sie, bis das andere Ventil vollständig offen ist; die Pumpe geht
  erst mit der letzten Zone aus.
- **Manuell + Automatik**: Startet eine Sequenz, während manuell bewässert
  wird, wird die manuelle Bewässerung zuerst geordnet beendet. Während
  einer Sequenz ist die manuelle Bedienung gesperrt. Wird eine andere
  Sequenz gestartet, endet die laufende geordnet.
- **Automatikstart**: Der Termin wird alle 10 s geprüft. Ein verpasster
  Termin (Last, kurzer Ausfall) wird bis 15 Minuten nachgeholt, jeder
  Termin startet höchstens einmal. Termine bei ausgeschaltetem
  Master-Schalter oder im Wartungsmodus verfallen. Wird eine Startzeit auf eine heute
  bereits verstrichene Uhrzeit gesetzt, startet die Sequenz erst morgen.
- **Konfiguration übernehmen** während einer Bewässerung beendet diese
  geordnet.
- **Tageswechsel**: Die Tageslaufzeit der Pumpe beginnt um Mitternacht neu.

Alle Wartezeiten laufen nicht blockierend: Jeder Schaltschritt steht mit
festem Zeitpunkt in einer gespeicherten Warteschlange, die ein Timer
abarbeitet.

### Überwachung und Fehler

- **Neustart von IP-Symcon** während einer Bewässerung: geplante Schritte
  werden verworfen, nie nachträglich ausgeführt. 10 s nach dem
  vollständigen Systemstart (KNX-Gateway verbunden) schaltet das Modul die
  Pumpe aus und schließt nach der Verfahrzeit **alle** konfigurierten
  Ventile. Die Automatik läuft danach normal weiter.
- **KNX-Fehler**: Jeder Schaltbefehl wird auf Erfolg geprüft. Öffnet ein
  Ventil nicht, startet die Pumpe nicht; die Bewässerung wird abgebrochen
  und alle Ventile werden geschlossen. Startet die Pumpe nicht, wird
  ebenfalls abgebrochen. Ein fehlgeschlagener Schließbefehl wird nach 5 s
  einmal wiederholt. Alle Fehler stehen im Meldungsfenster und im Status.
- **Zone ohne gültiges Ventil** wird in Sequenzen übersprungen und lässt
  sich manuell nicht starten.
- **Pumpen-Rückmeldung** (optional): Meldet die Pumpe während der
  Bewässerung länger als 20 s „aus", wird der Einschaltbefehl einmal
  wiederholt; bleibt sie aus (z. B. Trockenlaufschutz), wird nach
  weiteren 20 s abgebrochen. Meldet sie „an", obwohl das Modul sie nicht
  eingeschaltet hat, wird das protokolliert.
- Während der Bewässerung wird der Einschaltbefehl jede Minute
  wiederholt, um ein verlorenes Telegramm abzufangen.
- „Alles stoppen" sendet immer ein Aus-Telegramm an die Pumpe.

Per Skript: `BWS_StartSequence(<InstanzID>, 1..4);`,
`BWS_StopAll(<InstanzID>);`, `BWS_ResetCounters(<InstanzID>);`,
`BWS_SetMaintenance(<InstanzID>, true|false);`

## Sensor-Logik

- Ohne Sensor wird eine fällige Zone immer bewässert.
- Mit Sensor wird sie übersprungen, wenn der Messwert die Schwelle in
  Richtung „feucht genug" erreicht.
- „Rasen" mit mehreren Sensoren: bewässert wird, sobald **eine**
  Teilfläche trocken meldet; dann läuft die ganze Kette.
- Die manuelle Bedienung ignoriert den Sensor.

## Regensperre (Weather Underground)

Optional, standardmäßig aus (aufklappbarer Bereich im Instanz-Editor):
PWS-Station-ID und API-Key eintragen (kostenloser Key unter
`wunderground.com/member/api-keys`, eigene PWS erforderlich), optional
eine Regenraten-Schwelle in mm/h (0 = jeder Regen sperrt). „Verbindung
testen" prüft die Einstellungen.

Beim Start einer Sequenz (automatisch oder manuell) wird die aktuelle
Regenrate abgefragt; bei Regen über der Schwelle startet die Sequenz
nicht. Fehlen Key oder Station oder ist die Abfrage gestört (Zeitlimit
5 s), wird **nicht** blockiert und eine Warnung protokolliert.

## Wasserverbrauch

- **Wasserverbrauch letzte Laufzeit**: Summe über alle Kreise seit dem
  letzten Pumpenstart; bleibt nach dem Lauf stehen.
- **Wasserverbrauch gesamt**: Summe seit Installation bzw. „Zähler
  zurücksetzen".

Berechnet wird Durchfluss (l/min) × Zeit, solange ein Ventil offen ist.
Abgerechnet wird bei jeder neuen Durchfluss-Messung mit dem bis dahin
gültigen Wert sowie bei jeder Ventiländerung – unabhängig davon, wie oft
der Sensor sendet. Ohne Durchflusssensor bleiben beide Werte bei 0.

## Tests

`php tests/run.php` simuliert die IP-Symcon-Umgebung und prüft die
Abläufe (Sequenzen, manuelle Bedienung, Rasen, Neustart, KNX-Fehler,
Rückmeldung, Intervall, Zeitplan, Migration, Wasserverbrauch, Formular,
Master-Schalter, Wartungsmodus).
Bei jedem simulierten KNX-Telegramm wird das Schaltschema geprüft. Ein
Exit-Code 0 bedeutet: alle Prüfungen bestanden. Die Tests ersetzen keinen
Test mit der echten Anlage.

## Annahmen und Grenzen

- Jede Gruppenadresse liegt als eigene KNX-Geräteinstanz mit „1.001
  Schalten" vor und unterstützt `KNX_WriteDPT1`. Bei abweichender
  Signatur ist nur die Methode `knx()` anzupassen.
- Rückmeldungen der Ventile werden nicht ausgewertet; ein Ventil gilt als
  offen, sobald der Schaltbefehl erfolgreich gesendet wurde.

Änderungen siehe [CHANGELOG.md](CHANGELOG.md).
