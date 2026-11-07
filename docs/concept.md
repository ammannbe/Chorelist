# Konzept Chorelist

Stand: 1. November 2026. Ämtli-App wie die «Aufgaben» von Flatastic, ohne Einkaufen (→ Shoppinglist) und Finanzen (später).

- Name: **Chorelist**, Subdomain **aufgaben.narrenhaus.ch**
- Stack wie Shoppinglist: Laravel 13, Blade + Alpine.js, RxDB (offline, Sync), PWA, Fortify mit Passkeys, KeyHelp mit Cron
- Eigene Konten; SSO (z. B. über das geplante «Konto»/Pocket ID) später

## Entscheide

| Thema | Entscheid |
|---|---|
| Zuständigkeit | Keine. Jede Person erledigt, was sie will. Persönliche Vorschläge («Mit «Bad putzen» holst du 4 Punkte und ziehst an Anna vorbei!») sieht nur die Person selbst. |
| Bearbeiten | Alle Mitglieder dürfen Aufgaben erfassen, ändern und löschen; der Verlauf zeigt, wer. Name und Ranking der Liste sowie Mitglieder verwaltet die Besitzerin oder der Besitzer. |
| Für andere erledigen | Erlaubt («Erledigt …» → Person und Datum wählen); im Verlauf steht «eingetragen von». |
| Minuspunkte | Keine. Verpasste Termine erscheinen im Verlauf und in der Statistik. |
| Extra-Erledigungen | Geben Punkte, aber erst nach Rückfrage («Vorgezogen für Datum X» oder «Zusätzlich erledigt»). |
| Standard | Datenmodell nach iCalendar (RRULE, Fälligkeit, Alarme als Tage vorher); kein CalDAV-Backend (passt nicht zum Offline-Sync, kennt keine Punkte/Erledigt-von, Clients behandeln wiederkehrende VTODOs uneinheitlich). Dafür ein iCal-Abo (VEVENT). |

## Funktionen

- Mehrere Listen pro Person, teilen per E-Mail oder Einladungslink (7 Tage gültig) oder mit einem Haushalt (alle Mitglieder, auch spätere). Haushalte verwaltet die App selbst (`HOUSEHOLDS_DRIVER=local`) oder sie kommen aus dem AccountManager (`konto`: Webhooks und täglicher Abgleich, Mitglieder über `oidc_sub`, Umstieg per `households:import`; siehe AccountManager-Konzept 5.6 und `docs/HOUSEHOLDS-API.md` dort).
- Aufgabe: Titel, Emoji, Kategorie, Punkte 1–5, Notizen, Schritte (Checkliste), Termin:
  - einmalig, täglich, wöchentlich an Wochentagen, monatlich (Tag X, «4. Montag», letzter Tag), jährlich, jeweils «alle N»
  - «X Tage/Wochen nach dem letzten Mal» (wie Flatastic)
  - nach Bedarf (ohne Termin, z. B. Müll), optional «erinnern, wenn N Tage nicht erledigt»
  - erster Termin, Zeit zum Erledigen (Start → Ende, z. B. 1 Woche), Enddatum, Pausieren, Archivieren
  - Erinnerungen am Fälligkeitstag, 1/2 Tage oder 1 Woche vorher, am ersten Tag des Zeitfensters
- Erledigen (rückgängig machbar), Überspringen, Verschieben (bis vor den nächsten Termin), vorziehen, extra.
- Übersicht «Anstehend» über alle Listen: Überfällig, Heute, Nächste 7 Tage, Später, Nach Bedarf, Pausiert.
- Verlauf pro Liste und Aufgabe: erledigt/übersprungen/extra (mit Punkten), Änderungen (Feld alt → neu), Mitglieder, verschoben, rückgängig, verpasste Termine; Filter nach Person und Aufgabe.
- Ranking pro Liste (optional; Woche ab Montag, Monat oder gesamt) mit fairem Anteil nach anwesenden Tagen (Ferien), Statistik (Punkte pro Woche, erledigt/verpasst pro Aufgabe).
- Ferien: keine Erinnerungen, faires Ranking.
- Benachrichtigungen pro Art (Erinnerung, überfällig, von anderen erledigt, geänderte Aufgaben, geteilte Liste, Wochenzusammenfassung) per Push und/oder E-Mail, Erinnerungszeit, Listen stummschalten.
- Einstellungen: Sprache (Deutsch/Englisch), Darstellung (hell/dunkel/Gerät), Vorschläge an/aus, Wischen oder langes Drücken, App installieren (Anleitung pro Gerät, Push-Hinweis für iOS), Kalender-Abo, Datenexport (JSON), Konto (Name, E-Mail, Passwort, Passkeys, Konto löschen).

## Später

- Finanzen (Haushaltskasse), SSO.
- Fotos als Nachweis, Kommentare.
