# Changelog

## 4.1.1

- Überwachung für stromlos schließende Kugelhähne (NC): Die minütliche
  Wiederholung öffnet zuerst die Ventile erneut und schaltet die Pumpe
  erst nach der Verfahrzeit ein. Zuvor konnte die Pumpe nach einem kurzen
  Spannungsausfall gegen zugefahrene Hähne anlaufen. Dasselbe gilt für das
  erneute Einschalten nach einer Pumpen-Rückmeldung „aus".
- Eine Wiederholung startet die Pumpe nicht mehr, wenn sie inzwischen
  regulär abgeschaltet wurde.
- README: Hinweis auf NC-Kugelhähne und ETS-Empfehlung für den Pumpenkanal.

## 4.1.0

- **Wartungsmodus** (WebFront-Schalter bzw. `BWS_SetMaintenance`): beendet
  eine laufende Bewässerung geordnet, sperrt die Pumpe und macht jeden
  physischen Kugelhahn einzeln schaltbar – zum Ausblasen der Leitungen.
  Automatik und Zonen-Schalter sind gesperrt, beim Verlassen schließen
  alle Kugelhähne.
- **Master-Schalter aus**: Eine Zone einzuschalten erzeugt keinen Fehler
  mehr. Abgelehnte Bedienungen (Master aus, Sequenz läuft, Grenze
  erreicht, kein Ventil, Steuerung beschäftigt) erscheinen mit Begründung
  im Status und im Meldungsfenster, statt eine Exception auszulösen.
- Automatik-Termine bei ausgeschaltetem Master-Schalter oder im
  Wartungsmodus verfallen und werden nach dem Wiedereinschalten nicht
  nachgeholt.

## 4.0.0

Überarbeitung mit Schwerpunkt Robustheit. Bedienung, Variablen-Idents,
Eigenschaften und die Skriptfunktionen `BWS_StartSequence`, `BWS_StopAll`,
`BWS_ResetCounters`, `BWS_WUTestConnection` bleiben kompatibel.

### Sicherheit von Pumpe und Ventilen

- Die Warteschlange liegt mit festen Zeitpunkten in einem gespeicherten
  Attribut. Bei einem Neustart von IP-Symcon während der Bewässerung
  werden geplante Schritte verworfen; nach dem Systemstart wird die
  Anlage geordnet abgeschaltet (alle Ventile). Zuvor lief die Pumpe nach
  einem Neustart weiter und die Automatik blieb gesperrt.
- KNX-Schaltbefehle werden auf Erfolg geprüft. Öffnet ein Ventil nicht
  oder startet die Pumpe nicht, wird abgebrochen; die Pumpe läuft nie
  gegen ein geschlossenes Ventil.
- Zonen ohne gültige Ventil-Instanz werden übersprungen bzw. manuell
  abgelehnt.
- Die Grenze gleichzeitiger Zonen zählt reservierte und noch schließende
  Zonen mit; schnelles Mehrfach-Einschalten öffnet keine dritte Zone mehr.
- Schließt eine von mehreren offenen Zonen, wartet sie, bis die übrigen
  Ventile vollständig offen sind.
- Pumpen-Rückmeldung: bleibt sie trotz wiederholtem Einschaltbefehl aus,
  wird abgebrochen, statt die Pumpe alle 10 s erneut einzuschalten.

### Schaltschema

- Ausschalten einheitlich: Pumpe aus → Verfahrzeit → Ventil zu (zuvor am
  regulären Ende nur 1 s).
- Der Überlapp beim Zonenwechsel beträgt mindestens die Verfahrzeit des
  neuen Ventils.
- Jede Zone erhält die volle eingestellte Dauer, auch nach einem
  Zonenwechsel mit Überlapp.

### Konfiguration

- Sequenzzeilen und Intervall-Speicher verweisen über die Ventil-Instanz
  auf eine Zone („Rasen": -1) statt über die Listenposition. Bestehende
  Konfigurationen werden beim ersten Übernehmen automatisch umgestellt.
- Instanzstatus 201–204 für fehlende Pumpe, ungültige oder doppelte
  Ventile und verwaiste Sequenzzeilen.
- Verfahrzeit im Formular mindestens 1 s.
- Konfiguration übernehmen während einer Bewässerung beendet diese geordnet.

### Ablauf

- Intervall: eine Zone gilt erst nach vollständiger Bewässerung als
  erledigt; ein Abbruch zählt nicht.
- Automatikstart: verpasste Termine werden bis 15 Minuten nachgeholt,
  jeder Termin startet höchstens einmal, auch über einen Neustart hinweg.
  Eine Startzeit, die auf eine heute verstrichene Uhrzeit gesetzt wird,
  gilt erst ab morgen.
- Regensperre: Zeitlimit der Wetterabfrage 5 s (zuvor bis 18 s).

### Effizienz

- Variablen werden nur bei Änderung geschrieben (Leerlauf: 0 statt bis zu
  2160 Schreibzugriffe pro Stunde).
- Druck, Durchfluss und Pumpen-Rückmeldung werden per Nachricht bei
  Änderung übernommen statt alle 10 s abgefragt.
- Wasserverbrauch wird aus den Durchfluss-Änderungen exakt integriert;
  der eigene Abtast-Timer entfällt (`BWS_SampleWater` entfernt).
- „Nächste Laufzeiten" werden höchstens einmal pro Minute berechnet.

### Code

- `module.php` von 2381 auf rund 1800 Zeilen; eine Ablauflogik für
  Sequenz, Rasen-Kette und manuelle Bedienung statt drei getrennter.
- Kommentare beschreiben das aktuelle Verhalten; Migrationscode für
  Versionen vor 3.0 entfernt (Update auf 4.0 setzt 3.x voraus).
- Offline-Testsuite `tests/run.php`.

## 3.6.2 und älter

- **3.2** – Wasserverbrauch als zwei Summen (letzte Laufzeit / gesamt).
- **3.0** – Pumpen-Watchdog, „Nächste Laufzeiten" je Kreis, frei wählbare
  Sequenznamen, manuelle Laufzeiten in eigener Unterkategorie, vier
  Sequenzen.
- **2.9** – Entscheidung über das Abschalten der Pumpe beim Schließen der
  letzten Zone zur Ausführungszeit.
- **2.8** – Pumpenstatus-Rückmeldung mit Abweichungswarnung.
- **2.x** – Grundfunktionen: Sequenzsteuerung, Rasen-Zusammenlegung,
  Intervall- und Sensorlogik, Statistik, manuelle Steuerung.
