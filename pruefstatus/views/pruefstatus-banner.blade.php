{{-- Prüfstatus-Hinweis: nur in der normalen Seitenansicht (nicht Export, Revision, Startseite) --}}
@if(isset($page) && \Illuminate\Support\Facades\Route::currentRouteAction() === 'BookStack\Entities\Controllers\PageController@show' && pruefstatus_gilt_fuer($page))
    @php
        $pruefStatus = pruefstatus_lesen($page);
        $pruefDarf = pruefstatus_ist_pruefer(user());
        $pruefSucheUrl = url('/search?term=' . urlencode('[' . PRUEFSTATUS_TAG . '=' . PRUEFSTATUS_OFFEN . ']'));
    @endphp

    @if($pruefStatus === PRUEFSTATUS_OK)
        <div class="callout success mb-m" style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1rem">
            <span style="flex:1 1 16rem">
                <strong>Geprüft</strong>
                @if(pruefstatus_tag_wert($page, PRUEFSTATUS_TAG_VON))
                    von {{ pruefstatus_tag_wert($page, PRUEFSTATUS_TAG_VON) }}
                    am {{ pruefstatus_tag_wert($page, PRUEFSTATUS_TAG_AM) }}
                @endif
            </span>
            @if($pruefDarf)
                <form action="{{ url('/pruefstatus/' . $page->id . '/zuruecksetzen') }}" method="POST" class="print-hidden" style="margin:0">
                    {!! csrf_field() !!}
                    <button type="submit" class="button outline small" style="margin:0">Prüfung zurücksetzen</button>
                </form>
            @endif
        </div>
    @elseif($pruefStatus === PRUEFSTATUS_OFFEN)
        <div class="callout warning mb-m" style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1rem">
            <span style="flex:1 1 16rem">
                <strong>Nicht geprüft</strong> – dieser Artikel wurde noch nicht von einer prüfenden Person freigegeben.
            </span>
            @if($pruefDarf)
                <span class="print-hidden" style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem">
                    <a href="{{ $pruefSucheUrl }}" class="text-small">Alle ungeprüften</a>
                    <form action="{{ url('/pruefstatus/' . $page->id . '/freigeben') }}" method="POST" style="margin:0">
                        {!! csrf_field() !!}
                        <button type="submit" class="button small" style="margin:0">Als geprüft markieren</button>
                    </form>
                </span>
            @endif
        </div>
    @endif
@endif
