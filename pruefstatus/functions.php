<?php

/**
 * Modul "Prüfstatus" für BookStack (geschrieben für v26.05.5)
 *
 * - Jede neue/geänderte Seite eines Nicht-Prüfers bekommt automatisch den Status "ungeprüft".
 * - Prüfer (siehe PRUEFSTATUS_ROLLEN) sehen auf der Seite einen Button "Als geprüft markieren".
 * - Der Status liegt als Tag an der Seite; die Suche [Prüfstatus=ungeprüft] listet alle offenen Seiten.
 * - Freigaben/Zurücksetzungen landen im Audit-Log.
 */

use BookStack\Activity\ActivityType;
use BookStack\Entities\Models\Page;
use BookStack\Entities\Queries\PageQueries;
use BookStack\Facades\Activity;
use BookStack\Facades\Theme;
use BookStack\Search\SearchIndex;
use BookStack\Theming\ThemeEvents;
use BookStack\Theming\ThemeViews;
use BookStack\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;

// ======================= KONFIGURATION =======================

// Anzeigenamen der BookStack-Rollen, deren Mitglieder prüfen dürfen.
// Admins dürfen immer prüfen.
const PRUEFSTATUS_ROLLEN = ['Prüfer'];

// Nur Seiten in Büchern mit diesem Tag prüfen (z. B. 'Prüfpflicht').
// Leer lassen ('') = alle Seiten im gesamten BookStack.
const PRUEFSTATUS_NUR_BUCH_TAG = '';

// Dürfen Prüfer ihre eigenen Seiten freigeben? false = Vier-Augen-Prinzip.
const PRUEFSTATUS_EIGENE_FREIGEBEN = false;

// Namen/Werte der Tags und Zeitzone für die Anzeige
const PRUEFSTATUS_TAG = 'Prüfstatus';
const PRUEFSTATUS_TAG_VON = 'Geprüft von';
const PRUEFSTATUS_TAG_AM = 'Geprüft am';
const PRUEFSTATUS_OFFEN = 'ungeprüft';
const PRUEFSTATUS_OK = 'geprüft';
const PRUEFSTATUS_ZEITZONE = 'Europe/Berlin';

// Button "Fehler?" in der Hinweis-Box: öffnet das Mailprogramm mit vorbereiteter Fehlermeldung.
// Leer lassen ('') = kein Button.
const PRUEFSTATUS_FEHLER_MAIL = '';

// Betreff und Text der Fehlermeldung. Platzhalter: {titel}, {link}, {buch}, {status}, {name}
const PRUEFSTATUS_FEHLER_BETREFF = 'Fehler im Artikel: {titel}';
const PRUEFSTATUS_FEHLER_TEXT = <<<'TEXT'
Hallo,

im folgenden Artikel ist mir etwas aufgefallen, das nicht stimmt:

Artikel: {titel}
Buch: {buch}
Link: {link}
Prüfstatus: {status}

1. Was ist falsch oder unklar?


2. An welcher Stelle? (Überschrift / Abschnitt)


3. Wie wäre es richtig? (falls bekannt)


Viele Grüße
{name}
TEXT;

// =================== AB HIER NICHTS ÄNDERN ===================

function pruefstatus_ist_pruefer(User $user): bool
{
    if ($user->isGuest()) {
        return false;
    }

    if ($user->hasSystemRole('admin')) {
        return true;
    }

    return $user->roles->pluck('display_name')->intersect(PRUEFSTATUS_ROLLEN)->isNotEmpty();
}

function pruefstatus_gilt_fuer(Page $page): bool
{
    if ($page->draft) {
        return false;
    }

    if (PRUEFSTATUS_NUR_BUCH_TAG === '') {
        return true;
    }

    $book = $page->book()->first();
    return $book && $book->tags()->where('name', '=', PRUEFSTATUS_NUR_BUCH_TAG)->exists();
}

/** Liefert 'ungeprüft', 'geprüft' oder null (noch kein Status gesetzt). */
function pruefstatus_lesen(Page $page): ?string
{
    return $page->tags()->where('name', '=', PRUEFSTATUS_TAG)->value('value');
}

function pruefstatus_tag_wert(Page $page, string $name): ?string
{
    return $page->tags()->where('name', '=', $name)->value('value');
}

/** mailto-Link für den Button "Fehler?" oder null, wenn keine Adresse konfiguriert ist. */
function pruefstatus_fehler_mailto(Page $page): ?string
{
    if (PRUEFSTATUS_FEHLER_MAIL === '') {
        return null;
    }

    $user = user();
    $werte = [
        '{titel}'  => $page->name,
        '{link}'   => $page->getUrl(),
        '{buch}'   => $page->book?->name ?? '',
        '{status}' => pruefstatus_lesen($page) ?? '',
        '{name}'   => $user->isGuest() ? '' : $user->name,
    ];

    $betreff = strtr(PRUEFSTATUS_FEHLER_BETREFF, $werte);
    $text = str_replace("\n", "\r\n", str_replace("\r\n", "\n", strtr(PRUEFSTATUS_FEHLER_TEXT, $werte)));

    return 'mailto:' . PRUEFSTATUS_FEHLER_MAIL
        . '?subject=' . rawurlencode($betreff)
        . '&body=' . rawurlencode($text);
}

/** Setzt den Status-Tag (und bei "geprüft" Prüfer + Datum), andere Tags bleiben unberührt. */
function pruefstatus_setzen(Page $page, string $status, ?User $pruefer = null): void
{
    $page->tags()->whereIn('name', [PRUEFSTATUS_TAG, PRUEFSTATUS_TAG_VON, PRUEFSTATUS_TAG_AM])->delete();

    $order = intval($page->tags()->max('order')) + 1;
    $page->tags()->create(['name' => PRUEFSTATUS_TAG, 'value' => $status, 'order' => $order]);

    if ($status === PRUEFSTATUS_OK && $pruefer) {
        $page->tags()->create(['name' => PRUEFSTATUS_TAG_VON, 'value' => $pruefer->name, 'order' => $order + 1]);
        $page->tags()->create([
            'name'  => PRUEFSTATUS_TAG_AM,
            'value' => now()->setTimezone(PRUEFSTATUS_ZEITZONE)->format('d.m.Y H:i'),
            'order' => $order + 2,
        ]);
    }

    // Suchindex aktualisieren, damit die Tag-Suche sofort stimmt
    $page->unsetRelation('tags');
    app(SearchIndex::class)->indexEntity($page);
}

// 1) Automatik: Speichern durch Nicht-Prüfer => "ungeprüft"
Theme::listen(ThemeEvents::ACTIVITY_LOGGED, function (string $type, $detail) {
    $relevant = [ActivityType::PAGE_CREATE, ActivityType::PAGE_UPDATE, ActivityType::PAGE_RESTORE];
    if (!in_array($type, $relevant, true) || !($detail instanceof Page) || !pruefstatus_gilt_fuer($detail)) {
        return null;
    }

    // Nicht-Prüfer: jede Änderung macht die Seite wieder "ungeprüft" (auch manuell gesetzte Tags werden überschrieben).
    // Prüfer: ihr Stand bleibt erhalten, nur ein fehlender Status wird ergänzt.
    if (!pruefstatus_ist_pruefer(user()) || pruefstatus_lesen($detail) === null) {
        pruefstatus_setzen($detail, PRUEFSTATUS_OFFEN);
    }

    return null;
});

// 2) Buttons "Als geprüft markieren" / "Prüfung zurücksetzen"
Theme::listen(ThemeEvents::ROUTES_REGISTER_WEB_AUTH, function (Router $router) {
    $router->post('/pruefstatus/{pageId}/{aktion}', function (int $pageId, string $aktion) {
        $page = app(PageQueries::class)->findVisibleByIdOrFail($pageId);
        $user = user();

        if (!pruefstatus_ist_pruefer($user) || !pruefstatus_gilt_fuer($page)) {
            abort(403, 'Keine Berechtigung zum Prüfen dieser Seite.');
        }

        if ($aktion === 'freigeben') {
            $istEigene = intval($page->created_by) === $user->id || intval($page->updated_by) === $user->id;
            if ($istEigene && !PRUEFSTATUS_EIGENE_FREIGEBEN) {
                session()->flash('error', 'Eigene Änderungen können nicht selbst freigegeben werden – bitte eine andere Person prüfen lassen.');
                return redirect($page->getUrl());
            }
            pruefstatus_setzen($page, PRUEFSTATUS_OK, $user);
            Activity::add('page_pruefung_freigegeben', $page);
            session()->flash('success', 'Seite wurde als geprüft markiert.');
        } else {
            pruefstatus_setzen($page, PRUEFSTATUS_OFFEN);
            Activity::add('page_pruefung_zurueckgesetzt', $page);
            session()->flash('success', 'Prüfung wurde zurückgesetzt.');
        }

        return redirect($page->getUrl());
    })->where(['pageId' => '[0-9]+', 'aktion' => 'freigeben|zuruecksetzen']);
});

// 3) Hinweis-Box oben im Seiteninhalt
Theme::listen(ThemeEvents::THEME_REGISTER_VIEWS, function (ThemeViews $themeViews) {
    $themeViews->renderBefore('pages.parts.page-display', 'pruefstatus-banner');
});

// 4) Einmalig für Bestandsseiten: php artisan pruefstatus:init --status=ungeprüft
class PruefstatusInitCommand extends Command
{
    protected $signature = 'pruefstatus:init {--status=ungeprüft : "ungeprüft" oder "geprüft"}';
    protected $description = 'Setzt den Prüfstatus für alle Seiten, die noch keinen haben';

    public function handle(): int
    {
        $status = $this->option('status');
        if (!in_array($status, [PRUEFSTATUS_OFFEN, PRUEFSTATUS_OK], true)) {
            $this->error('Ungültiger Status, erlaubt: ' . PRUEFSTATUS_OFFEN . ' / ' . PRUEFSTATUS_OK);
            return 1;
        }

        $anzahl = 0;
        Page::query()
            ->where('draft', '=', false)
            ->whereDoesntHave('tags', fn ($q) => $q->where('name', '=', PRUEFSTATUS_TAG))
            ->chunkById(100, function ($pages) use ($status, &$anzahl) {
                /** @var Page $page */
                foreach ($pages as $page) {
                    if (pruefstatus_gilt_fuer($page)) {
                        pruefstatus_setzen($page, $status);
                        $anzahl++;
                    }
                }
            }, 'entities.id', 'id');

        $this->info("{$anzahl} Seite(n) auf \"{$status}\" gesetzt.");
        return 0;
    }
}

Theme::registerCommand(new PruefstatusInitCommand());
