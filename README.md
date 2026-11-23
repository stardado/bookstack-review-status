# BookStack Prüfstatus

Ein Theme-Modul für [BookStack](https://www.bookstackapp.com/), das einen einfachen **Freigabe-Workflow** für Seiten nachrüstet:
Neue oder geänderte Seiten gelten als **„ungeprüft“**, bis eine Person mit Prüfer-Rolle sie als **„geprüft“** markiert.

BookStack bringt keinen eingebauten Freigabe-Prozess mit – dieses Modul schließt die Lücke über das offizielle
[Theme-Modul-System](https://github.com/BookStackApp/BookStack/blob/development/dev/docs/theme-system-modules.md), ohne Änderungen am BookStack-Code.

## Funktionen

- **Automatischer Status:** Legt jemand ohne Prüfer-Rolle eine Seite an, bearbeitet sie oder stellt eine alte Revision wieder her,
  wird die Seite auf „ungeprüft“ gesetzt – auch wenn der Status zuvor „geprüft“ war.
- **Hinweis-Box** oben im Seiteninhalt:
  - gelb: *„Nicht geprüft – dieser Artikel wurde noch nicht von einer prüfenden Person freigegeben.“*
  - grün: *„Geprüft von Name am Datum“*
- **Prüfer-Buttons** direkt in der Box: „Als geprüft markieren“ bzw. „Prüfung zurücksetzen“.
- **Vier-Augen-Prinzip (optional):** Prüfer können Seiten, die sie selbst angelegt oder zuletzt bearbeitet haben, nicht freigeben.
- **Manipulationsschutz:** Setzt ein Nicht-Prüfer den Status-Tag von Hand auf „geprüft“, wird das beim Speichern überschrieben.
- **Button „Fehler?“ (optional):** Wer einen Fehler im Artikel entdeckt, kann ihn mit einem Klick melden. Der Button öffnet das
  Mailprogramm mit einer vorbereiteten Nachricht (Artikel, Buch, Link, Prüfstatus und Fragen zum Ausfüllen) an eine feste Adresse.
- **Übersicht offener Seiten** über die normale BookStack-Suche: `[Prüfstatus=ungeprüft]` (in der Box verlinkt).
- **Audit-Log:** Freigaben und Zurücksetzungen erscheinen im Audit-Log von BookStack.
- **Optional nur für bestimmte Bücher** (über einen Buch-Tag, z. B. `Prüfpflicht`).
- **Artisan-Befehl** zum einmaligen Setzen des Status für bestehende Seiten.

Der Status wird als normale BookStack-Tags an der Seite gespeichert (`Prüfstatus`, `Geprüft von`, `Geprüft am`) und ist damit
durchsuchbar und über die API auslesbar.

## Voraussetzungen

- BookStack **v26.03 oder neuer** (Theme-Modul-System). Entwickelt und im Einsatz mit **v26.05.5**.
- Zugriff auf das Dateisystem der BookStack-Installation (bzw. auf das Docker-Volume).

## Installation

### Variante A: per Artisan-Befehl aus dem Release

```bash
php artisan bookstack:install-module https://github.com/stardado/bookstack-review-status/releases/latest/download/pruefstatus.zip
```

Der Befehl legt bei Bedarf auch einen Theme-Ordner an und fragt vor der Installation nach.
Unter Docker (linuxserver-Image) z. B.:

```bash
docker exec -it bookstack php /app/www/artisan bookstack:install-module https://github.com/stardado/bookstack-review-status/releases/latest/download/pruefstatus.zip
```

### Variante B: manuell

1. Falls noch kein Theme aktiv ist, in der BookStack-`.env` ein Theme setzen:
   ```
   APP_THEME=custom
   ```
2. Den Ordner [`pruefstatus/`](pruefstatus) aus diesem Repository nach
   `themes/<theme-name>/modules/pruefstatus/` kopieren, sodass dort `bookstack-module.json` und `functions.php` liegen.

   | Installation | Theme-Ordner |
   |---|---|
   | Standard | `<bookstack>/themes/` |
   | Docker, linuxserver-Image | `/config/www/themes/` (im Volume, das auf `/config` gemountet ist); `.env` unter `/config/www/.env` |

3. BookStack bzw. den Container neu starten.

### Prüfen, ob das Modul geladen ist

```bash
php artisan pruefstatus:init --help
```

Wird der Befehl nicht gefunden, ist entweder `APP_THEME` nicht gesetzt oder das Modul liegt nicht im richtigen Ordner.

## Einrichtung

### 1. Prüfer-Rolle anlegen

1. *Einstellungen → Rollen → Neue Rolle anlegen*
2. Name: **`Prüfer`** (exakt so, siehe Konfiguration)
3. Rechte können leer bleiben, wenn die Rolle **zusätzlich** zu einer normalen Rolle (z. B. „Editor“) vergeben wird –
   in BookStack addieren sich die Rechte aller Rollen eines Benutzers.
   Soll jemand *nur* die Prüfer-Rolle haben, braucht die Rolle mindestens Leserechte auf die Inhalte.
4. Den Prüfern unter *Einstellungen → Benutzer* die Rolle zusätzlich zuweisen.

Administratoren dürfen immer prüfen.

### 2. Bestehende Seiten (optional)

Seiten ohne Status zeigen keine Box, bis sie das nächste Mal gespeichert werden. Um allen bestehenden Seiten einmalig einen Status zu geben:

```bash
# alle bestehenden Seiten als ungeprüft markieren
php artisan pruefstatus:init

# oder: alle bestehenden Seiten als geprüft betrachten
php artisan pruefstatus:init --status=geprüft
```

Der Befehl ändert nur Seiten, die noch keinen Status haben.

## Konfiguration

Oben in [`pruefstatus/functions.php`](pruefstatus/functions.php):

| Konstante | Standard | Bedeutung |
|---|---|---|
| `PRUEFSTATUS_ROLLEN` | `['Prüfer']` | Anzeigenamen der Rollen, die prüfen dürfen (mehrere möglich) |
| `PRUEFSTATUS_NUR_BUCH_TAG` | `''` | Leer = alle Seiten. Sonst nur Seiten in Büchern mit diesem Tag (z. B. `'Prüfpflicht'`) |
| `PRUEFSTATUS_EIGENE_FREIGEBEN` | `false` | `false` = Vier-Augen-Prinzip, `true` = Prüfer dürfen eigene Seiten freigeben |
| `PRUEFSTATUS_TAG`, `…_TAG_VON`, `…_TAG_AM` | `Prüfstatus`, `Geprüft von`, `Geprüft am` | Namen der verwendeten Tags |
| `PRUEFSTATUS_OFFEN`, `PRUEFSTATUS_OK` | `ungeprüft`, `geprüft` | Tag-Werte |
| `PRUEFSTATUS_ZEITZONE` | `Europe/Berlin` | Zeitzone für „Geprüft am“ |
| `PRUEFSTATUS_FEHLER_MAIL` | `''` | Empfängeradresse für den Button „Fehler?“. Leer = kein Button |
| `PRUEFSTATUS_FEHLER_BETREFF`, `PRUEFSTATUS_FEHLER_TEXT` | siehe Datei | Betreff und Text der Fehlermeldung; Platzhalter `{titel}`, `{link}`, `{buch}`, `{status}`, `{name}` |

Nach Änderungen an Tag-Namen oder -Werten passen bestehende Tags nicht mehr – am besten vor dem ersten Einsatz festlegen.

## Ablauf im Alltag

| Wer | Aktion | Ergebnis |
|---|---|---|
| Autor | Seite anlegen / bearbeiten / Revision wiederherstellen | Status „ungeprüft“, gelbe Box |
| Prüfer | Seite öffnen → **Als geprüft markieren** | Status „geprüft“, grüne Box mit Name und Datum, Eintrag im Audit-Log |
| Autor | geprüfte Seite erneut bearbeiten | wieder „ungeprüft“ |
| Prüfer | **Prüfung zurücksetzen** | wieder „ungeprüft“, Eintrag im Audit-Log |
| Prüfer | Link **Alle ungeprüften** | Suchergebnis aller offenen Seiten |
| alle | Button **Fehler?** | Mailprogramm öffnet sich mit vorbereiteter Fehlermeldung an die konfigurierte Adresse |

Bearbeitet ein Prüfer selbst eine Seite, bleibt ihr Status unverändert (fehlt er, wird „ungeprüft“ gesetzt).

## Aufbau

```
pruefstatus/
├── bookstack-module.json            Modul-Metadaten
├── functions.php                    Logik: Event-Listener, Routen, Artisan-Befehl, Konfiguration
├── views/
│   └── pruefstatus-banner.blade.php Hinweis-Box (vor dem Seiteninhalt eingefügt)
└── lang/{de,de_informal,en}/
    └── activities.php               Texte für das Audit-Log
```

Verwendete Erweiterungspunkte des Logical Theme Systems: `ACTIVITY_LOGGED`, `ROUTES_REGISTER_WEB_AUTH`, `THEME_REGISTER_VIEWS`
sowie `Theme::registerCommand`.

## Deinstallation

Ordner `themes/<theme-name>/modules/pruefstatus/` entfernen und BookStack neu starten.
Die gesetzten Tags bleiben an den Seiten erhalten und können bei Bedarf über *Einstellungen → Tags* eingesehen bzw. manuell entfernt werden.

## Hinweise

- Das Logical Theme System ist laut BookStack nur „semi-stabil“. Nach BookStack-Updates kurz prüfen, ob das Modul noch wie erwartet arbeitet.
- Die Hinweis-Box erscheint nur in der normalen Seitenansicht, nicht in Exporten, Revisionsansichten oder auf einer als Startseite gesetzten Seite.
- Der Button „Fehler?“ ist ein `mailto:`-Link. Er braucht ein eingerichtetes Mailprogramm auf dem Gerät der meldenden Person;
  BookStack selbst verschickt dabei keine Mail. Die Empfängeradresse ist im Seitenquelltext sichtbar.
- Änderungen über die BookStack-API werden genauso behandelt wie Änderungen im Editor.
- Dies ist kein offizielles BookStack-Projekt.

## Lizenz

[MIT](LICENSE)
