'use strict';
const EP = location.pathname;

// Storico chat AI (in memoria per la sessione)
let aiStorico = [];

// Allegati temporanei durante inserimento/modifica
let allegatiSessione = [];

// ── Orologio + sync ───────────────────────────────────────────
function tickOra() {
    document.getElementById('ora-live').textContent =
        new Date().toLocaleString('it-IT',{weekday:'short',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
tickOra(); setInterval(tickOra, 1000);

// ── POST helper ────────────────────────────────────────────────
async function api(params) {
    const body = new URLSearchParams(params);
    const r = await fetch(EP, {method:'POST', body});
    return r.json();
}

async function uploadApi(file) {
    const formData = new FormData();
    formData.append('_action', 'upload_file');
    formData.append('file', file);
    const r = await fetch(EP, {method:'POST', body: formData});
    return r.json();
}

// ── Carica config aggiornata dal server ────────────────────────
async function ricaricaCfg() {
    CFG = await api({_action:'get_config'});
    popolaFiltriAssegnati();
    popolaFiltriTipi();
    popolaModalSelects();
}

// ── Stats ─────────────────────────────────────────────────────
// aggiornaStat() rimossa - i dati stats arrivano da lista_completa (B3)

// ── Popola filtri e selects ────────────────────────────────────
function popolaFiltriAssegnati() {
    const sel = document.getElementById('f-assegnato');
    const cur = sel.value;
    sel.innerHTML = '<option value="tutti">Tutti</option>';
    (CFG.assegnati||[]).forEach(a =>
        sel.innerHTML += `<option value="${esc(a.sigla)}">${esc(a.sigla)} - ${esc(a.nome)}</option>`
    );
    if (cur) sel.value = cur;
}
function popolaFiltriTipi() {
    const sel = document.getElementById('f-tipo');
    const cur = sel.value;
    sel.innerHTML = '<option value="tutti">Tutti</option>';
    (CFG.tipi_richiesta||[]).forEach(t =>
        sel.innerHTML += `<option value="${esc(t)}">${esc(t)}</option>`
    );
    if (cur) sel.value = cur;
}
function popolaModalSelects() {
    // Tipo richiesta nel form
    const mTipo = document.getElementById('m-tipo');
    mTipo.innerHTML = '';
    (CFG.tipi_richiesta||[]).forEach(t =>
        mTipo.innerHTML += `<option value="${esc(t)}">${esc(t)}</option>`
    );
    // Licenze
    const mDet = document.getElementById('m-dettaglio');
    mDet.innerHTML = '';
    (CFG.tipi_licenza||[]).forEach(l =>
        mDet.innerHTML += `<option value="${esc(l)}">${esc(l)}</option>`
    );
    // Assegnati
    const mAss = document.getElementById('m-assegnato');
    mAss.innerHTML = '';
    (CFG.assegnati||[]).forEach(a =>
        mAss.innerHTML += `<option value="${esc(a.sigla)}">${esc(a.sigla)} - ${esc(a.nome)}</option>`
    );
}

// ── Visualizzazione dettaglio (FIX 1) ─────────────────────────
let _viewId = null;

async function apriView(id) {
    _viewId = id;
    const r = await api({_action:'get_record', id});
    if (!r || r.ok === false) { toast('Record non trovato','err'); return; }

    // Pre-compila autore commento con utente loggato
    const autoreEl = document.getElementById('commento-autore');
    if (autoreEl && UTENTE.nome && !autoreEl.value) autoreEl.value = UTENTE.nome;

    const statoMeta = {
        'aperto':         '🟡 Aperto',
        'presa in carico':'🔵 Presa in carico',
        'attesa':         '🟠 In attesa',
        'chiuso':         '✅ Chiuso',
    };
    const prioMeta = {urgente:'🔴 Urgente',alta:'🟡 Alta',normale:'⚪ Normale'};

    document.getElementById('view-title').textContent = `Lavorazione #${r.id}`;

    const righe = [
        ['Tipo richiesta',  r.tipo + (r.dettaglio ? ` - ${r.dettaglio}` : '')],
        ['Data richiesta',  fmtD(r.data_richiesta)],
        ['Richiedente',     r.richiedente || '-'],
        ['Assegnato a',     r.assegnato_a],
        ['Priorità',        prioMeta[r.priorita] || r.priorita],
        ['Stato',           statoMeta[r.stato] || r.stato],
        ['Ticket',          r.ticket_aperto==1 ? `🎫 ${r.numero_ticket||'Aperto'}` : '-'],
        ['Lavoro da svolgere', r.descrizione],
        ['Note',            r.note || '-'],
        ['Data chiusura',   r.data_chiusura ? fmtDt(r.data_chiusura) : '-'],
        ['Creato il',       fmtDt(r.created_at)],
    ];
    
    const allegatiArr = JSON.parse(r.allegati || '[]');
    const allegatiHtml = allegatiArr.length > 0 
        ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">` + 
          allegatiArr.map(f => `<a href="?_action=download_allegato&file=${esc(f)}" class="allegato-item allegato-link" target="_blank">📄 ${esc(f.substring(16))}</a>`).join('') + 
          `</div>`
        : '—';

    document.getElementById('view-body').innerHTML = righe.map(([lbl, val]) => `
        <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:baseline;padding:6px 0;border-bottom:1px solid #f1f5f9">
            <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b">${lbl}</span>
            <span style="font-size:.87rem;color:#1a2535;line-height:1.5">${esc(String(val))}</span>
        </div>`).join('') + `<div style="padding:10px 0"><span style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b">Allegati</span>${allegatiHtml}</div>`;

    document.getElementById('view-overlay').classList.add('open');
    // F7: carica commenti
    caricaCommenti(id);
}

function chiudiView() {
    document.getElementById('view-overlay').classList.remove('open');
    _viewId = null;
}

function apriModificaDaView() {
    if (!_viewId) return;
    const idDaAprire = _viewId; // salva prima che chiudiView() lo azzeri
    chiudiView();
    apriModifica(idDaAprire);
}

// ── Modifica lavorazione ───────────────────────────────────────
let _editId = null;

async function apriModifica(id) {
    _editId = id;
    // Carica dati record dal server
    const r = await api({_action:'get_record', id});
    if (!r || r.ok === false) { toast('Record non trovato','err'); return; }

    // Popola selects con cfg corrente
    const eTipo = document.getElementById('e-tipo');
    const eDet  = document.getElementById('e-dettaglio');
    const eAss  = document.getElementById('e-assegnato');
    eTipo.innerHTML = ''; eDet.innerHTML = ''; eAss.innerHTML = '';
    (CFG.tipi_richiesta||[]).forEach(t =>
        eTipo.innerHTML += `<option value="${esc(t)}">${esc(t)}</option>`);
    (CFG.tipi_licenza||[]).forEach(l =>
        eDet.innerHTML  += `<option value="${esc(l)}">${esc(l)}</option>`);
    (CFG.assegnati||[]).forEach(a =>
        eAss.innerHTML  += `<option value="${esc(a.sigla)}">${esc(a.sigla)} - ${esc(a.nome)}</option>`);
    initLicCards('e'); // inizializza click handler card licenza

    // Precompila campi
    document.getElementById('edit-id-label').textContent = '#' + r.id;
    eTipo.value = r.tipo || '';
    onEditTipoChange(); // aggiorna visibilità blocco licenze
    // Se è una licenza, precompila sotto-tipo e nome
    if (/licenz/i.test(r.tipo || '') && r.dettaglio) {
        precompilaLicenza('e', r.dettaglio);
    }
    document.getElementById('e-data').value        = r.data_richiesta?.slice(0,10) || '';
    document.getElementById('e-richiedente').value = r.richiedente || '';
    eAss.value = r.assegnato_a || '';
    document.getElementById('e-priorita').value    = r.priorita || 'normale';
    document.getElementById('e-descrizione').value = r.descrizione || '';
    document.getElementById('e-ticket').checked    = r.ticket_aperto == 1;
    onEditTicketChange();
    document.getElementById('e-num-ticket').value  = r.numero_ticket || '';
    document.getElementById('e-stato').value       = r.stato || 'aperto';
    document.getElementById('e-note').value        = r.note || '';
    
    allegatiSessione = JSON.parse(r.allegati || '[]');
    renderAllegatiList('e');
    initDropzone('e');

    document.getElementById('edit-overlay').classList.add('open');
    document.getElementById('e-descrizione').focus();
}

function chiudiModifica() {
    document.getElementById('edit-overlay').classList.remove('open');
    _editId = null;
}

async function salvaModifica() {
    if (!_editId) return;
    const tipo = document.getElementById('e-tipo').value;
    let det = '';
    let sottoLic = '', nomeLic = '';
    if (!document.getElementById('edit-row-licenza').classList.contains('hidden')) {
        sottoLic = getLicCat('e');
        nomeLic  = getNomeLicenza('e');
        if (!nomeLic) { toast('Inserisci il nome della licenza','err'); return; }
        det = `${sottoLic} - ${nomeLic}`;
    }
    const data = document.getElementById('e-data').value;
    const desc = document.getElementById('e-descrizione').value.trim();
    if (!data) { toast('Inserisci la data','err'); return; }
    if (!desc) { toast('Inserisci la descrizione','err'); return; }

    const res = await api({
        _action:        'modifica',
        id:             _editId,
        tipo,
        dettaglio:      det,
        sotto_licenza:  sottoLic,
        nome_licenza:   nomeLic,
        data_richiesta: data,
        richiedente:    document.getElementById('e-richiedente').value.trim(),
        assegnato_a:    document.getElementById('e-assegnato').value,
        priorita:       document.getElementById('e-priorita').value,
        descrizione:    desc,
        ticket_aperto:  document.getElementById('e-ticket').checked ? '1' : '',
        numero_ticket:  document.getElementById('e-num-ticket').value.trim(),
        stato:          document.getElementById('e-stato').value,
        note:           document.getElementById('e-note').value.trim(),
        allegati:       JSON.stringify(allegatiSessione)
    });
    if (res.ok) {
        // Aggiorna CFG in memoria se nome licenza è nuovo
        if (nomeLic && !CFG.tipi_licenza.includes(nomeLic)) {
            CFG.tipi_licenza.push(nomeLic);
            popolaModalSelects();
        }
        toast('Modifiche salvate ✓','ok');
        chiudiModifica();
        caricaLista();
    } else {
        toast(res.err || 'Errore salvataggio','err');
    }
}

function onEditTipoChange() {
    const v = document.getElementById('e-tipo').value;
    const isLicenza = /licenz/i.test(v);
    document.getElementById('edit-row-licenza').classList.toggle('hidden', !isLicenza);
    if (isLicenza) {
        initLicCards('e');
        onSottoLicenzaChange('e');
    }
}
function onEditTicketChange() {
    document.getElementById('edit-row-ticket').classList.toggle(
        'hidden', !document.getElementById('e-ticket').checked);
}

// ── Ordinamento colonne ───────────────────────────────────────
let _sortCol = '';
let _sortDir = 'desc';

function setSort(col) {
    if (_sortCol === col) {
        _sortDir = _sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        _sortCol = col;
        _sortDir = 'asc';
    }
    // Aggiorna icone header
    document.querySelectorAll('.th-sort').forEach(th => {
        th.classList.remove('asc','desc');
        if (th.dataset.col === col) th.classList.add(_sortDir);
    });
    caricaLista();
}

// ── Cambio stato inline dalla tabella ────────────────────────
// ── Cambio stato inline dalla tabella ────────────────────────
async function cambiaStato(id, nuovoStato, selectEl) {
    const prev = selectEl.dataset.prev;
    selectEl.disabled = true;
    const res = await api({_action:'modifica_stato', id, stato: nuovoStato});
    selectEl.disabled = false;
    if (res.ok) {
        toast('Stato aggiornato ✓','ok');
        caricaLista();
    } else {
        toast(res.err || 'Errore aggiornamento stato','err');
        selectEl.value = prev; // ripristina valore precedente
    }
}

async function cambiaAssegnato(id, nuovoAssegnato) {
    const res = await api({_action:'modifica_assegnato', id, assegnato_a: nuovoAssegnato});
    if (res.ok) {
        toast('Assegnazione aggiornata ✓','ok');
        caricaLista();
    } else {
        toast(res.err || 'Errore','err');
    }
}

// ── Lista lavorazioni - B3: una sola chiamata lista+stats ────
let _ultimoCount = null;
let _paginaCorrente = 1;

async function caricaLista(pagina) {
    if (pagina !== undefined) _paginaCorrente = pagina;
    const ind = document.getElementById('sync-ind');
    ind.textContent = '⟳'; ind.style.animation = 'spin .8s linear infinite';

    const data = await api({
        _action:   'lista_completa',
        stato:     document.getElementById('f-stato').value,
        assegnato: document.getElementById('f-assegnato').value,
        tipo:      document.getElementById('f-tipo').value,
        priorita:  document.getElementById('f-priorita').value,
        cerca:     document.getElementById('f-cerca')?.value.trim() || '',
        sort_col:  _sortCol,
        sort_dir:  _sortDir,
        page:      _paginaCorrente,
    });

    renderTabella(data.righe || []);
    aggiornaStatDaData(data.stats || {});
    renderPaginazione(data.tot_filtrato||0, data.pagina||1, data.per_pagina||20);

    // U1: counter righe
    const counter = document.getElementById('row-counter');
    if (counter) {
        const tot = data.tot_filtrato||0;
        const inizio = (( data.pagina||1)-1)*(data.per_pagina||20)+1;
        const fine   = Math.min((data.pagina||1)*(data.per_pagina||20), tot);
        counter.textContent = tot > 0
            ? `${inizio}–${fine} di ${tot} lavorazion${tot===1?'e':'i'}`
            : 'Nessuna lavorazione trovata';
    }

    // M3: notifica nuove lavorazioni
    const tot = data.tot_filtrato||0;
    if (_ultimoCount !== null && tot > _ultimoCount) {
        toast(`🔔 ${tot - _ultimoCount} nuova lavorazione inserita`, 'info');
    }
    _ultimoCount = tot;

    // U6: aggiorna datalist
    aggiornaRichiedenti();

    ind.textContent = '✓'; ind.style.animation = '';
    setTimeout(()=>{ ind.textContent='⟳' }, 2000);
}

// U5: paginazione
function renderPaginazione(tot, pagina, perPage) {
    const numPagine = Math.ceil(tot / perPage);
    const el = document.getElementById('pagination');
    if (!el || numPagine <= 1) { if(el) el.innerHTML=''; return; }
    let html = `<button class="pag-btn" onclick="caricaLista(${pagina-1})" ${pagina<=1?'disabled':''}>‹</button>`;
    const inizio = Math.max(1, pagina-2), fine = Math.min(numPagine, pagina+2);
    if (inizio>1) html += `<button class="pag-btn" onclick="caricaLista(1)">1</button>${inizio>2?'<span style="padding:0 4px;color:#94a3b8">…</span>':''  }`;
    for(let i=inizio;i<=fine;i++) html+=`<button class="pag-btn ${i===pagina?'active':''}" onclick="caricaLista(${i})">${i}</button>`;
    if(fine<numPagine) html+=`${fine<numPagine-1?'<span style="padding:0 4px;color:#94a3b8">…</span>':''}<button class="pag-btn" onclick="caricaLista(${numPagine})">${numPagine}</button>`;
    html+=`<button class="pag-btn" onclick="caricaLista(${pagina+1})" ${pagina>=numPagine?'disabled':''}>›</button>`;
    el.innerHTML = html;
}

// U8: stats cliccabili
function aggiornaStatDaData(s) {
    const sa = document.getElementById('stats-area');
    if (!sa) return;
    const cardsAss = (CFG.assegnati||[]).map(a => {
        const n = s.per_assegnato?.[a.sigla]??0;
        return `<div class="stat-card blu clickable-stat" onclick="filtraPerStats(\'tutti\',\'\',\'${esc(a.sigla)}\')">
            <div class="stat-num">${n}</div><div class="stat-lbl">${esc(a.sigla)} Aperti</div></div>`;
    }).join('');
    sa.innerHTML=`
        <div class="stat-card blu clickable-stat"    onclick="filtraPerStats('tutti','','')" ><div class="stat-num">${s.tot||0}</div><div class="stat-lbl">Totale</div></div>
        <div class="stat-card arancio clickable-stat" onclick="filtraPerStats('aperto','','')" ><div class="stat-num">${s.aperti||0}</div><div class="stat-lbl">Aperti</div></div>
        <div class="stat-card blu clickable-stat"    onclick="filtraPerStats('presa in carico','','')" ><div class="stat-num">${s.in_carico||0}</div><div class="stat-lbl">In carico</div></div>
        <div class="stat-card arancio clickable-stat" onclick="filtraPerStats('attesa','','')" ><div class="stat-num">${s.in_attesa||0}</div><div class="stat-lbl">In attesa</div></div>
        <div class="stat-card verde clickable-stat"  onclick="filtraPerStats('chiuso','','')" ><div class="stat-num">${s.chiusi||0}</div><div class="stat-lbl">Chiusi</div></div>
        <div class="stat-card rosso clickable-stat"  onclick="filtraPerStats('tutti','urgente','')" ><div class="stat-num">${s.urgenti||0}</div><div class="stat-lbl">Urgenti</div></div>
        ${cardsAss}
        <div class="stat-card viola" ><div class="stat-num">${s.tick_ap||0}</div><div class="stat-lbl">Con Ticket</div></div>
        <div class="stat-card verde" ><div class="stat-num">${s.oggi||0}</div><div class="stat-lbl">Oggi</div></div>`;
}
function filtraPerStats(stato, priorita, assegnato) {
    if (stato)    document.getElementById('f-stato').value    = stato;
    if (priorita) document.getElementById('f-priorita').value = priorita;
    if (assegnato)document.getElementById('f-assegnato').value= assegnato;
    _paginaCorrente=1; caricaLista();
}

function renderTabella(rows) {
    const tb = document.getElementById('tabella-body');
    if (!rows.length) {
        tb.innerHTML = '<tr class="row-vuota"><td colspan="11">Nessuna lavorazione trovata.</td></tr>'; return;
    }
    tb.innerHTML = rows.map(r => {
        const chiuso = r.stato === 'chiuso';

        const statoMeta = {
            'aperto':         {cls:'b-aperto', lbl:'🟡 Aperto',         row:'stato-aperto', scls:'s-aperto'},
            'presa in carico':{cls:'b-presa',  lbl:'🔵 Presa in carico', row:'stato-presa',  scls:'s-presa'},
            'attesa':         {cls:'b-attesa', lbl:'🟠 In attesa',       row:'stato-attesa', scls:'s-attesa'},
            'chiuso':         {cls:'b-chiuso', lbl:'✅ Chiuso',          row:'stato-chiuso', scls:'s-chiuso'},
        };
        const sm = statoMeta[r.stato] || {cls:'b-aperto',lbl:r.stato,row:'stato-aperto',scls:'s-aperto'};

        // FIX 2: select inline per cambiare stato direttamente dalla tabella
        const statoHtml = `<select class="stato-select ${sm.scls}" data-prev="${esc(r.stato)}"
            onclick="event.stopPropagation()"
            onchange="this.dataset.prev=this.value;cambiaStato(${r.id},this.value,this)">
            <option value="aperto"          ${r.stato==='aperto'         ?'selected':''}>🟡 Aperto</option>
            <option value="presa in carico" ${r.stato==='presa in carico'?'selected':''}>🔵 Presa in carico</option>
            <option value="attesa"          ${r.stato==='attesa'         ?'selected':''}>🟠 In attesa</option>
            <option value="chiuso"          ${r.stato==='chiuso'         ?'selected':''}>✅ Chiuso</option>
        </select>`;
        const tipoHtml = r.dettaglio
            ? `<div class="td-tipo">${esc(r.tipo)}</div><div class="td-sub">↳ ${esc(r.dettaglio)}</div>`
            : `<div class="td-tipo">${esc(r.tipo)}</div>`;
        const tickHtml = r.ticket_aperto==1
            ? `<span class="badge b-tick-si">🎫 ${r.numero_ticket?esc(r.numero_ticket):'Sì'}</span>`
            : `<span class="badge b-tick-no">-</span>`;
        const commBadge = (r.num_commenti > 0)
            ? `<span class="badge-commenti" title="${r.num_commenti} commento/i">💬 ${r.num_commenti}</span>`
            : '';
        const prioMap = {urgente:'🔴 Urgente',alta:'🟡 Alta',normale:'⚪ Normale'};
        const prioCls = {urgente:'b-urgente',alta:'b-alta',normale:'b-normale'};
        const prioHtml  = `<span class="badge ${prioCls[r.priorita]||'b-normale'}">${prioMap[r.priorita]||r.priorita}</span>`;
        const chiusHtml = r.data_chiusura
            ? `<span class="td-chiusura">${fmtDt(r.data_chiusura)}</span>`
            : '<span style="color:#cbd5e1">-</span>';
        const richHtml  = r.richiedente ? `<small>${esc(r.richiedente)}</small>` : '<span style="color:#cbd5e1">-</span>';
        const descHtml  = `<div>${esc(r.descrizione)}</div>${r.note?`<div class="td-note">📌 ${esc(r.note)}</div>`:''}`;
        const prioCls2  = r.priorita==='urgente'?'prio-urgente':r.priorita==='alta'?'prio-alta':'';
        const assStyle  = badgeColorPerSigla(r.assegnato_a);
        const assBg     = assStyle.match(/background:([^;]+)/)?.[1] || '#f1f5f9';
        const assColor  = assStyle.match(/color:([^;]+)/)?.[1] || '#1a2535';
const assInline = `background-color:${assBg};color:${assColor};font-weight:700`;
        // FIX 2: btn-edit con testo "Modifica" leggibile
        const azioniHtml = chiuso
            ? `<button class="btn-sm btn-edit"   onclick="event.stopPropagation();apriModifica(${r.id})">✏ Modifica</button>
               <button class="btn-sm btn-riapri" onclick="event.stopPropagation();azione('riapri',${r.id})">↩ Riapri</button>
               <button class="btn-sm btn-del"    onclick="event.stopPropagation();azione('elimina',${r.id})">🗑</button>`
            : `<button class="btn-sm btn-edit"   onclick="event.stopPropagation();apriModifica(${r.id})">✏ Modifica</button>
               <button class="btn-sm btn-chiudi" onclick="event.stopPropagation();azione('chiudi',${r.id})">✓ Chiudi</button>
               <button class="btn-sm btn-del"    onclick="event.stopPropagation();azione('elimina',${r.id})">🗑</button>`;



        const classeScaduta = isScaduta(r) ? 'scaduta' : '';
        return `<tr class="clickable ${sm.row} ${prioCls2} ${classeScaduta}" onclick="apriView(${r.id})">
            <td class="td-id">#${r.id}</td>
            <td>${tipoHtml}</td>
            <td class="td-date">${fmtD(r.data_richiesta)}</td>
            <td>${richHtml}</td>
<td onclick="event.stopPropagation()" style="white-space:nowrap">
<select class="ass-select" style="${assInline}" onchange="if(!confirm('Cambiare assegnazione a ' + this.options[this.selectedIndex].text + '?')){this.value='${esc(r.assegnato_a)}';return;}cambiaAssegnato(${r.id},this.value)">
    ${(CFG.assegnati||[]).map(a =>
`<option value="${esc(a.sigla)}" ${r.assegnato_a===a.sigla?'selected':''}>${esc(a.sigla)}</option>`).join('')}
</select></td>
         <td class="td-desc">${descHtml}${commBadge}</td>
            <td>${tickHtml}</td>
            <td>${prioHtml}</td>
            <td>${statoHtml}</td>
            <td>${chiusHtml}</td>
            <td><div class="azioni">${azioniHtml}</div></td>
        </tr>`;
    }).join('');
}

// ── Azioni riga ────────────────────────────────────────────────
async function azione(cmd, id) {
    if (cmd==='elimina' && !confirm('Eliminare definitivamente questa lavorazione?')) return;
    const res = await api({_action:cmd, id});
    if (res.ok) {
        const msg={chiudi:'Lavorazione chiusa ✓',riapri:'Lavorazione riaperta',elimina:'Eliminata'};
        toast(msg[cmd]||'OK','ok'); caricaLista();
    }
}

// ── Salva nuova lavorazione ────────────────────────────────────
async function salva() {
    const tipo     = document.getElementById('m-tipo').value;
    let det = '';
    let sottoLic = '', nomeLic = '';
    if (!document.getElementById('row-licenza').classList.contains('hidden')) {
        sottoLic = getLicCat('m');
        nomeLic  = getNomeLicenza('m');
        if (!nomeLic) { toast('Inserisci il nome della licenza','err'); return; }
        det = `${sottoLic} - ${nomeLic}`;
    }
    const data     = document.getElementById('m-data').value;
    const rich     = document.getElementById('m-richiedente').value.trim();
    const ass      = document.getElementById('m-assegnato').value;
    const desc     = document.getElementById('m-descrizione').value.trim();
    const tick     = document.getElementById('m-ticket').checked;
    const nTick    = document.getElementById('m-num-ticket').value.trim();
    const prio     = document.getElementById('m-priorita').value;
    const note     = document.getElementById('m-note').value.trim();
    if (!data)  { toast('Inserisci la data','err'); return; }
    if (!desc)  { toast('Inserisci la descrizione del lavoro','err'); return; }
    const p = {_action:'inserisci',tipo,dettaglio:det,
        sotto_licenza:sottoLic, nome_licenza:nomeLic,
        data_richiesta:data, richiedente:rich,assegnato_a:ass,
        descrizione:desc, numero_ticket:nTick,priorita:prio,note};
    if (tick) p.ticket_aperto='1';
    p.allegati = JSON.stringify(allegatiSessione);

    const res = await api(p);
    if (res.ok) {
        toast('Lavorazione inserita ✓','ok');
        // Se è una nuova licenza non ancora in lista, aggiorna CFG in memoria
        if (nomeLic && !CFG.tipi_licenza.includes(nomeLic)) {
            CFG.tipi_licenza.push(nomeLic);
            popolaModalSelects(); // aggiorna i dropdown subito
        }
        chiudiModal();
        caricaLista();
    } else {
        toast('Errore: ' + (res.err || 'impossibile salvare'), 'err');
        console.error('salva() error:', res);
    }
}

// ── Modal lavorazione ─────────────────────────────────────────
function apriModal() {
    document.getElementById('m-data').value = new Date().toISOString().split('T')[0];
    document.getElementById('m-richiedente').value='';
    document.getElementById('m-descrizione').value='';
    document.getElementById('m-ticket').checked=false;
    document.getElementById('m-num-ticket').value='';
    document.getElementById('m-priorita').value='normale';
    document.getElementById('m-note').value='';
    // Pre-setta assegnato con l'utente loggato
    const mAss = document.getElementById('m-assegnato');
    if (UTENTE.sigla && mAss) mAss.value = UTENTE.sigla;
    onTipoChange(); onTicketChange();
    initLicCards('m');
    document.getElementById('modal-overlay').classList.add('open');
}
function chiudiModal(){ document.getElementById('modal-overlay').classList.remove('open'); }
function chiudiSeFuori(e){ if(e.target===document.getElementById('modal-overlay')) chiudiModal(); }

// FIX B14: onTipoChange controlla se il tipo selezionato contiene "licenz"
function onTipoChange(){
    const v = document.getElementById('m-tipo').value;
    const isLicenza = /licenz/i.test(v);
    document.getElementById('row-licenza').classList.toggle('hidden', !isLicenza);
    if (isLicenza) {
        initDropzone('m');
        initLicCards('m');
        onSottoLicenzaChange('m');
    }
}

// Aggiorna hint in base alla categoria selezionata
function onSottoLicenzaChange(pfx) {
    const cat  = getLicCat(pfx);
    const hint = document.getElementById(`${pfx}-lic-hint`);
    if (!hint) return;
    const msgs = {
        'Nuova Licenza'      : '🆕 <b>Nuova Licenza</b>: scrivi il nome nel campo libero - verrà aggiunta alla lista.',
        'Rinnovo'            : '🔄 <b>Rinnovo</b>: seleziona la licenza dalla lista o scrivi il nome.',
        'Inserimento Rinnovo': '📋 <b>Inserimento Rinnovo</b>: registra i dati di un rinnovo già avvenuto.',
    };
    hint.innerHTML = msgs[cat] || '';
}
function onTicketChange(){
    document.getElementById('row-ticket').classList.toggle('hidden',!document.getElementById('m-ticket').checked);
}

// ── Helper licenze ─────────────────────────────────────────────
// Legge la categoria selezionata dalle card radio
function getLicCat(pfx) {
    const checked = document.querySelector(`input[name="${pfx}_sotto_lic"]:checked`);
    return checked ? checked.value : 'Nuova Licenza';
}

// Attiva la card giusta e imposta il radio button
function setLicCat(pfx, val) {
    const wrap = document.getElementById(`${pfx}-lic-cat-wrap`);
    if (!wrap) return;
    wrap.querySelectorAll('.lic-cat-card').forEach(card => {
        const isActive = card.dataset.val === val;
        card.classList.toggle('active', isActive);
        const radio = card.querySelector('input[type=radio]');
        if (radio) radio.checked = isActive;
    });
}

// Click su card: attiva visivamente e imposta radio
function initLicCards(pfx) {
    const wrap = document.getElementById(`${pfx}-lic-cat-wrap`);
    if (!wrap) return;
    wrap.querySelectorAll('.lic-cat-card').forEach(card => {
        card.addEventListener('click', () => {
            setLicCat(pfx, card.dataset.val);
            onSottoLicenzaChange(pfx);
            // Per Nuova Licenza svuota la select, focus sul campo libero
            if (card.dataset.val === 'Nuova Licenza') {
                const sel = document.getElementById(`${pfx}-dettaglio`);
                if (sel) sel.value = '';
                const lib = document.getElementById(`${pfx}-nome-licenza-libero`);
                if (lib) lib.focus();
            }
        });
    });
}

function onNomeLicenzaSelect(pfx) {
    const sel = document.getElementById(`${pfx}-dettaglio`).value;
    if (sel) document.getElementById(`${pfx}-nome-licenza-libero`).value = '';
}

function onNomeLicenzaLibero(pfx) {
    const libero = document.getElementById(`${pfx}-nome-licenza-libero`).value.trim();
    if (libero) document.getElementById(`${pfx}-dettaglio`).value = '';
}

// Legge il nome licenza (libero ha priorità)
function getNomeLicenza(pfx) {
    const libero = document.getElementById(`${pfx}-nome-licenza-libero`)?.value.trim() || '';
    const sel    = document.getElementById(`${pfx}-dettaglio`)?.value || '';
    return libero || sel;
}

// Precompila blocco licenze nel form modifica da dettaglio esistente (es. "Rinnovo - Adobe")
function precompilaLicenza(pfx, dettaglio) {
    if (!dettaglio) return;
    const sep = ' - ';
    const idx = dettaglio.indexOf(sep);
    if (idx > -1) {
        const sotto = dettaglio.substring(0, idx);
        const nome  = dettaglio.substring(idx + sep.length);
        setLicCat(pfx, sotto);
        // Prova lista, altrimenti campo libero
        const nomeSel = document.getElementById(`${pfx}-dettaglio`);
        const nomiOpts = Array.from(nomeSel.options).map(o => o.value);
        if (nomiOpts.includes(nome)) {
            nomeSel.value = nome;
        } else {
            document.getElementById(`${pfx}-nome-licenza-libero`).value = nome;
        }
    } else {
        document.getElementById(`${pfx}-nome-licenza-libero`).value = dettaglio;
    }
}
document.addEventListener('keydown',e=>{ if(e.key==='Escape') chiudiImpostazioni(); });

// ── Drag & Drop Allegati ──────────────────────────────────────
function initDropzone(pfx) {
    const dz = document.getElementById(`dropzone-${pfx}`);
    if (!dz) return;
    
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
        dz.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); }, false);
    });

    dz.addEventListener('dragover', () => dz.classList.add('dragover'));
    dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
    dz.addEventListener('drop', e => {
        dz.classList.remove('dragover');
        const files = e.dataTransfer.files;
        handleFileUpload(files, pfx);
    });
    dz.onclick = () => {
        const inp = document.createElement('input');
        inp.type = 'file'; inp.multiple = true;
        inp.onchange = () => handleFileUpload(inp.files, pfx);
        inp.click();
    };
}

async function handleFileUpload(files, pfx) {
    for (let file of files) {
        toast(`Caricamento ${file.name}...`, 'info');
        const res = await uploadApi(file);
        if (res.ok) {
            allegatiSessione.push(res.filename);
            renderAllegatiList(pfx);
        } else {
            toast(res.err, 'err');
        }
    }
}

function renderAllegatiList(pfx) {
    const list = document.getElementById(`allegati-${pfx}`);
    list.innerHTML = allegatiSessione.map((f, i) => `
        <div class="allegato-item">
            <span>📄 ${esc(f.substring(16))}</span>
            <button class="allegato-del" onclick="allegatiSessione.splice(${i},1);renderAllegatiList('${pfx}')">✕</button>
        </div>`).join('');
}

// ── Export CSV ────────────────────────────────────────────────
function exportCsv(){
    const f = document.createElement('form');
    f.method='POST'; f.action=EP;
    f.innerHTML='<input name="_action" value="export_csv">';
    document.body.appendChild(f); f.submit(); document.body.removeChild(f);
    toast('Export CSV avviato','info');
}

// ── AI PANEL ─────────────────────────────────────────────────
function toggleAI(){
    const p   = document.getElementById('ai-panel');
    const btn = document.getElementById('btn-ai-toggle');
    const aperto = p.classList.toggle('aperto');
    if(btn) btn.classList.toggle('ai-attivo', aperto);
}

function aiChip(testo){
    document.getElementById('ai-chips').style.display='none';
    document.getElementById('ai-input').value=testo;
    aiInvia();
}

// FIX B9: lock invio durante elaborazione
let aiInCorso = false;
async function aiInvia(){
    if(aiInCorso) return; // FIX B9: ignora click multipli
    const inp=document.getElementById('ai-input');
    const testo=inp.value.trim();
    if(!testo) return;
    inp.value=''; inp.style.height='';

    document.getElementById('ai-empty')?.remove();
    document.getElementById('ai-chips').style.display='none';

    aiAddMsg('user',testo);
    aiStorico.push({ruolo:'utente',testo});

    // FIX B10: tronca storico lato JS prima di inviare (max 20 elementi = 10 scambi)
    if(aiStorico.length>20) aiStorico=aiStorico.slice(-20);

    // FIX B9: disabilita input e bottone
    aiInCorso=true;
    inp.disabled=true;
    document.querySelector('.btn-ai-send').disabled=true;
    document.querySelector('.btn-ai-send').style.opacity='.4';

    // Indicatore "sta scrivendo"
    const think=document.createElement('div');
    think.className='ai-thinking'; think.id='ai-think';
    think.innerHTML='<span></span><span></span><span></span>';
    document.getElementById('ai-chat').appendChild(think);
    aiScrollBottom();

    try {
        const res = await api({_action:'ai_chat', messaggio:testo, storico:JSON.stringify(aiStorico)});
        document.getElementById('ai-think')?.remove();
        if(res.ok){
            aiAddMsg('bot', res.risposta);
            aiStorico.push({ruolo:'assistente',testo:res.risposta});
        } else {
            aiAddMsg('bot','⚠️ '+( res.err||'Risposta non valida'));
        }
    } catch(e){
        document.getElementById('ai-think')?.remove();
        aiAddMsg('bot','⚠️ Errore di rete. Riprova.');
    } finally {
        // FIX B9: sblocca sempre il bottone, anche in caso di errore
        aiInCorso = false;
        inp.disabled = false;
        const sendBtn = document.querySelector('.btn-ai-send');
        sendBtn.disabled = false;
        sendBtn.style.opacity = '';
        inp.focus();
    }
    aiScrollBottom();
}

function aiAddMsg(ruolo, testo){
    const chat=document.getElementById('ai-chat');
    const div=document.createElement('div');
    div.className=`ai-msg ${ruolo==='user'?'user':'bot'}`;
    // Formattazione semplice: codice tra backtick tripli
    const html=testo
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/```(\w*)\n?([\s\S]*?)```/g,'<pre><code>$2</code></pre>')
        .replace(/`([^`]+)`/g,'<code>$1</code>')
        .replace(/\n/g,'<br>');
    const ora=new Date().toLocaleTimeString('it-IT',{hour:'2-digit',minute:'2-digit'});
    div.innerHTML=`<div class="ai-bubble">${html}</div><div class="ai-ts">${ora}</div>`;
    chat.appendChild(div);
}
function aiScrollBottom(){ const c=document.getElementById('ai-chat'); c.scrollTop=c.scrollHeight; }
function autoResize(ta){ ta.style.height=''; ta.style.height=Math.min(ta.scrollHeight,100)+'px'; }
function aiKeydown(e){ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();aiInvia();} }

// ── IMPOSTAZIONI ──────────────────────────────────────────────
let settData = { tipi_richiesta:[], tipi_licenza:[], assegnati:[] };

function apriImpostazioni(){
    document.getElementById('sett-pin-area').style.display='flex';
    document.getElementById('sett-content').style.display='none';
    document.getElementById('sett-pin-input').value='';
    document.getElementById('sett-overlay').classList.add('open');
    setTimeout(()=>document.getElementById('sett-pin-input').focus(),100);
}
function chiudiImpostazioni(){ document.getElementById('sett-overlay').classList.remove('open'); }
function chiudiSettSeFuori(e){ if(e.target===document.getElementById('sett-overlay')) chiudiImpostazioni(); }

async function caricaSettData(){
    const d=await api({_action:'get_config'});
    settData.tipi_richiesta=[...(d.tipi_richiesta||[])];
    settData.tipi_licenza=[...(d.tipi_licenza||[])];
    settData.assegnati=[...(d.assegnati||[])];
    renderChipsTipi(); renderChipsLicenze(); renderChipsAssegnati();
    document.getElementById('sett-api-key').placeholder=d.ai_ok?'*** configurata - incolla per aggiornare ***':'sk-ant-api03-…';
    document.getElementById('sett-api-key').value='';
    // FIX B5: rimossa seconda chiamata get_config inutile
}

// FIX B2: event passato esplicitamente - compatibile Firefox/strict mode
function settTab(tab, evTarget){
    document.querySelectorAll('.sett-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.sett-section').forEach(s=>s.classList.remove('active'));
    document.querySelector(`#sett-${tab}`).classList.add('active');
    evTarget.classList.add('active');
}

// ── Drag & drop helper generico ────────────────────────────────
function abilitaDragDrop(containerId, arr, renderFn) {
    const wrap = document.getElementById(containerId);
    if (!wrap) return;
    let dragIdx = null;

    wrap.querySelectorAll('.sett-chip').forEach((chip, i) => {
        chip.draggable = true;

        chip.addEventListener('dragstart', e => {
            dragIdx = i;
            chip.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        chip.addEventListener('dragend', () => {
            chip.classList.remove('dragging');
            wrap.querySelectorAll('.sett-chip').forEach(c => c.classList.remove('drag-over'));
        });
        chip.addEventListener('dragover', e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            wrap.querySelectorAll('.sett-chip').forEach(c => c.classList.remove('drag-over'));
            chip.classList.add('drag-over');
        });
        chip.addEventListener('drop', e => {
            e.preventDefault();
            if (dragIdx === null || dragIdx === i) return;
            // Sposta elemento nell'array
            const moved = arr.splice(dragIdx, 1)[0];
            arr.splice(i, 0, moved);
            renderFn();
        });
    });
}

function renderChipsTipi(){
    const w=document.getElementById('chips-tipi');
    w.innerHTML=settData.tipi_richiesta.map((t,i)=>
        `<span class="sett-chip" title="Trascina per riordinare">≡ ${esc(t)} <button onclick="rimuoviTipo(${i})" title="Rimuovi">✕</button></span>`
    ).join('');
    abilitaDragDrop('chips-tipi', settData.tipi_richiesta, renderChipsTipi);
}
function addTipo(){
    const v=document.getElementById('add-tipo-inp').value.trim();
    if(!v||settData.tipi_richiesta.includes(v)){toast('Tipo già presente o vuoto','err');return;}
    settData.tipi_richiesta.push(v);
    document.getElementById('add-tipo-inp').value='';
    renderChipsTipi();
}
function rimuoviTipo(i){ settData.tipi_richiesta.splice(i,1); renderChipsTipi(); }

function renderChipsLicenze(){
    const w=document.getElementById('chips-licenze');
    w.innerHTML=settData.tipi_licenza.map((t,i)=>
        `<span class="sett-chip" title="Trascina per riordinare">≡ ${esc(t)} <button onclick="rimuoviLicenza(${i})" title="Rimuovi">✕</button></span>`
    ).join('');
    abilitaDragDrop('chips-licenze', settData.tipi_licenza, renderChipsLicenze);
}
function addLicenza(){
    const v=document.getElementById('add-lic-inp').value.trim();
    if(!v){return;}
    settData.tipi_licenza.push(v);
    document.getElementById('add-lic-inp').value='';
    renderChipsLicenze();
}
function rimuoviLicenza(i){ settData.tipi_licenza.splice(i,1); renderChipsLicenze(); }

function renderChipsAssegnati(){
    const w=document.getElementById('chips-assegnati');
    w.innerHTML=settData.assegnati.map((a,i)=>
        `<span class="sett-chip" title="Trascina per riordinare">≡ <b>${esc(a.sigla)}</b> - ${esc(a.nome)} <button onclick="rimuoviAssegnato(${i})" title="Rimuovi">✕</button></span>`
    ).join('');
    abilitaDragDrop('chips-assegnati', settData.assegnati, renderChipsAssegnati);
}
function addAssegnato(){
    const sigla=document.getElementById('add-ass-sigla').value.trim().toUpperCase();
    const nome=document.getElementById('add-ass-nome').value.trim();
    if(!sigla||!nome){toast('Sigla e nome obbligatori','err');return;}
    settData.assegnati.push({sigla,nome});
    document.getElementById('add-ass-sigla').value='';
    document.getElementById('add-ass-nome').value='';
    renderChipsAssegnati();
}
function rimuoviAssegnato(i){ settData.assegnati.splice(i,1); renderChipsAssegnati(); }

// FIX B6: PIN tenuto in variabile di modulo - salvaSett lo usa direttamente
let SETTINGS_PIN_session = '';

async function verificaPin(){
    const pin = document.getElementById('sett-pin-input').value;
    const res = await api({_action:'verifica_pin', pin});
    if(res.ok){
        SETTINGS_PIN_session = pin; // salvato per uso in salvaSett
        document.getElementById('sett-pin-area').style.display='none';
        document.getElementById('sett-content').style.display='block';
        caricaSettData();
    } else {
        toast('PIN errato','err');
        document.getElementById('sett-pin-input').value='';
        document.getElementById('sett-pin-input').focus();
    }
}

async function salvaSett(){
    // FIX B6: usa SETTINGS_PIN_session (non il campo DOM già svuotato)
    const apiKey = document.getElementById('sett-api-key').value.trim();
    const params = {
        _action:'salva_config',
        pin: SETTINGS_PIN_session,
        tipi_richiesta: JSON.stringify(settData.tipi_richiesta),
        tipi_licenza:   JSON.stringify(settData.tipi_licenza),
        assegnati:      JSON.stringify(settData.assegnati),
    };
    if(apiKey) params.anthropic_key = apiKey;

    const res = await api(params);
    if(res.ok){
        toast('Impostazioni salvate ✓','ok');
        await ricaricaCfg();
        chiudiImpostazioni();
        // FIX B3: aggiorna badge AI in modo corretto
        const badge = document.getElementById('ai-stato-badge');
        if(badge){
            const aioraOk = apiKey || badge.classList.contains('no') === false;
            if(apiKey){ badge.textContent='CLAUDE'; badge.classList.remove('no'); }
            // se non è stata incollata una chiave nuova, il badge resta com'era
        }
    } else {
        toast(res.err||'Errore salvataggio','err');
    }
}

// ── Utility ───────────────────────────────────────────────────
function fmtD(s){
    if(!s) return '-';
    const d=new Date((s+'').replace(' ','T'));
    return isNaN(d)?s:d.toLocaleDateString('it-IT',{day:'2-digit',month:'2-digit',year:'numeric'});
}
function fmtDt(s){
    if(!s) return '-';
    const d=new Date((s+'').replace(' ','T'));
    return isNaN(d)?s:d.toLocaleDateString('it-IT',{day:'2-digit',month:'2-digit',year:'numeric'})
        +' '+d.toLocaleTimeString('it-IT',{hour:'2-digit',minute:'2-digit'});
}
function esc(t){
    return String(t??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// M7: colore badge assegnato deterministico dalla sigla - ogni persona ha il suo colore
const _BADGE_PALETTE = [
    {bg:'#dbeafe',color:'#1e40af'}, // blu
    {bg:'#ede9fe',color:'#5b21b6'}, // viola
    {bg:'#dcfce7',color:'#166534'}, // verde
    {bg:'#fef3c7',color:'#92400e'}, // giallo
    {bg:'#fce7f3',color:'#9d174d'}, // rosa
    {bg:'#f0fdf4',color:'#15803d'}, // verde chiaro
    {bg:'#fff7ed',color:'#9a3412'}, // arancio
    {bg:'#f0f9ff',color:'#075985'}, // azzurro
];
const _badgeCache = {};
function badgeColorPerSigla(sigla){
    if(!_badgeCache[sigla]){
        // hash semplice della sigla per indice stabile
        let h=0; for(const c of String(sigla)) h=(h*31+c.charCodeAt(0))&0xffff;
        const pal=_BADGE_PALETTE[h%_BADGE_PALETTE.length];
        _badgeCache[sigla]=`background:${pal.bg};color:${pal.color};font-weight:700`;
    }
    return _badgeCache[sigla];
}

// M1: debounce sul campo cerca - non interroga il server ad ogni tasto
let _cercaTimer;
function cercaDebounce(){
    clearTimeout(_cercaTimer);
    _cercaTimer = setTimeout(caricaLista, 350);
}

// ── Toast ─────────────────────────────────────────────────────
let toastT;
function toast(msg,tipo='ok'){
    const el=document.getElementById('toast');
    el.textContent=msg; el.className=`toast ${tipo} show`;
    clearTimeout(toastT); toastT=setTimeout(()=>el.classList.remove('show'),3200);
}

// ── Auto-refresh 30s - B4: pausa quando tab non è visibile ──
let _refreshInterval = null;
function avviaRefresh() {
    if (_refreshInterval) return;
    _refreshInterval = setInterval(caricaLista, 30000);
}
function pausaRefresh() {
    clearInterval(_refreshInterval);
    _refreshInterval = null;
}
document.addEventListener('visibilitychange', () => {
    document.hidden ? pausaRefresh() : (avviaRefresh(), caricaLista());
});
avviaRefresh();

// ── Init ──────────────────────────────────────────────────────
// ── U9: calcola se una lavorazione è scaduta (> 3 giorni aperta) ─
function isScaduta(r) {
    if (r.stato === 'chiuso') return false;
    const data = new Date((r.data_richiesta+'').replace(' ','T'));
    return !isNaN(data) && (Date.now() - data.getTime()) > 3 * 86400000;
}

// ── U6: autocomplete richiedenti ──────────────────────────────
let _richiedentiCache = [];
async function aggiornaRichiedenti() {
    if (_richiedentiCache.length > 0) return; // già caricati
    const rows = await api({_action:'richiedenti'});
    if (Array.isArray(rows)) {
        _richiedentiCache = rows;
        const dl = document.getElementById('richiedenti-list');
        if (dl) dl.innerHTML = rows.map(r => `<option value="${esc(r)}">`).join('');
    }
}

// ── F4: stampa lavorazione dal modal dettaglio ────────────────
async function stampaLavorazione() {
    const id = _viewId;
    if (!id) return;
    const r = await api({_action:'get_record', id});
    if (!r || r.ok === false) { toast('Record non trovato','err'); return; }

    const statoMeta = {
        'aperto':'Aperto', 'presa in carico':'Presa in carico',
        'attesa':'In attesa', 'chiuso':'Chiuso'
    };
    const prioMeta = {urgente:'Urgente',alta:'Alta',normale:'Normale'};

    // Nome file: #ID_Richiedente_Tipo
    const nomeFile = `Lavorazione_${r.id}_${(r.richiedente||'').replace(/\s+/g,'_')||'nessuno'}_${(r.tipo||'').replace(/\s+/g,'_')}`;
    const allegatiArr = JSON.parse(r.allegati || '[]');
    const allegatiHtml = allegatiArr.length > 0
        ? allegatiArr.map(f => `<li>${f.substring(16)}</li>`).join('')
        : '<li>—</li>';

    const html = `<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>${nomeFile}</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;font-size:12px;color:#1a2535;padding:30px 40px}
  .header{display:flex;align-items:center;gap:16px;border-bottom:2px solid #1a2e6b;padding-bottom:12px;margin-bottom:20px}
  .header img{height:40px}
  .header-testo h1{font-size:14px;font-weight:700;color:#1a2e6b}
  .header-testo p{font-size:10px;color:#64748b;margin-top:2px}
  .badge-stato{display:inline-block;padding:3px 10px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
  .badge-aperto{background:#fef3c7;color:#92400e}
  .badge-chiuso{background:#dcfce7;color:#166534}
  .badge-carico{background:#dbeafe;color:#1d4f88}
  .badge-attesa{background:#ede9fe;color:#4c1d95}
  .badge-prio-urgente{background:#fee2e2;color:#991b1b}
  .badge-prio-alta{background:#fef3c7;color:#92400e}
  .badge-prio-normale{background:#f1f5f9;color:#64748b}
  table{width:100%;border-collapse:collapse;margin-bottom:20px}
  th,td{padding:7px 10px;text-align:left;border-bottom:1px solid #e2e8f0;font-size:11px}
  th{font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;width:160px;background:#f8fafc}
  td{color:#1a2535;line-height:1.5}
  .section-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin:20px 0 8px}
  .footer{margin-top:30px;padding-top:10px;border-top:1px solid #e2e8f0;font-size:9px;color:#94a3b8;display:flex;justify-content:space-between}
  @media print{body{padding:15px 20px}}
</style>
</head>
<body>
<div class="header">
  <img src="${window.location.origin}/icon-192.png" alt="Logo">
  <div class="header-testo">
    <h1>Gestione Ticket · Lavorazione #${r.id}</h1>
    <p>Stampato il ${new Date().toLocaleString('it-IT')} · ${window.location.hostname}</p>
  </div>
</div>

<table>
  <tr><th>Tipo richiesta</th><td>${esc(r.tipo)}${r.dettaglio ? ' — ' + esc(r.dettaglio) : ''}</td></tr>
  <tr><th>Data richiesta</th><td>${fmtD(r.data_richiesta)}</td></tr>
  <tr><th>Richiedente</th><td>${esc(r.richiedente||'—')}</td></tr>
  <tr><th>Assegnato a</th><td>${esc(r.assegnato_a)}</td></tr>
  <tr><th>Priorità</th><td><span class="badge-stato badge-prio-${r.priorita}">${prioMeta[r.priorita]||r.priorita}</span></td></tr>
  <tr><th>Stato</th><td><span class="badge-stato badge-${r.stato==='presa in carico'?'carico':r.stato==='in attesa'?'attesa':r.stato}">${statoMeta[r.stato]||r.stato}</span></td></tr>
  <tr><th>Ticket</th><td>${r.ticket_aperto==1 ? '🎫 ' + (r.numero_ticket||'Aperto') : '—'}</td></tr>
  <tr><th>Lavoro da svolgere</th><td>${esc(r.descrizione)}</td></tr>
  ${r.note ? `<tr><th>Note</th><td>${esc(r.note)}</td></tr>` : ''}
  ${r.data_chiusura ? `<tr><th>Data chiusura</th><td>${fmtDt(r.data_chiusura)}</td></tr>` : ''}
  <tr><th>Creato il</th><td>${fmtDt(r.created_at)}</td></tr>
  <tr><th>Allegati</th><td><ul style="padding-left:16px">${allegatiHtml}</ul></td></tr>
</table>

<div class="footer">
  <span>Gestione Ticket v${typeof APP_VERSION!=='undefined'?APP_VERSION:''}</span>
  <span>#${r.id} · ${esc(r.richiedente||'')} · ${esc(r.tipo)}</span>
</div>

<script>
  document.title = '${nomeFile.replace(/'/g,"\'")}';
  window.onload = function(){ window.print(); };
<\/script>
</body></html>`;

    const w = window.open('', '_blank');
    w.document.write(html);
    w.document.close();
}

// ── F5: duplica lavorazione ───────────────────────────────────
async function duplicaLavorazione(id) {
    if (!id) return;
    const res = await api({_action:'duplica', id});
    if (res.ok) {
        toast(`Lavorazione duplicata → #${res.id} ✓`, 'ok');
        chiudiView();
        caricaLista();
    } else {
        toast(res.err || 'Errore duplicazione','err');
    }
}

// ── F7: commenti ──────────────────────────────────────────────
async function caricaCommenti(id) {
    const lista = document.getElementById('commenti-lista');
    if (!lista) return;
    lista.innerHTML = '<div class="commento-vuoto">Caricamento…</div>';
    const rows = await api({_action:'get_commenti', id});
    if (!Array.isArray(rows) || !rows.length) {
        lista.innerHTML = '<div class="commento-vuoto">Nessun commento ancora.</div>';
        return;
    }
    lista.innerHTML = rows.map(cm => `
        <div class="commento-item">
            <div class="commento-header">
                <span class="commento-autore">${esc(cm.autore || 'Anonimo')}</span>
                <span style="display:flex;align-items:center;gap:6px">
                    <span class="commento-ts">${fmtDt(cm.created_at)}</span>
                    <button class="commento-del" onclick="eliminaCommento(${cm.id})" title="Elimina">✕</button>
                </span>
            </div>
            <div class="commento-testo">${esc(cm.testo).replace(/\n/g,'<br>')}</div>
        </div>`).join('');
    lista.scrollTop = lista.scrollHeight;
}

async function inviaCommento() {
    if (!_viewId) return;
    const autore = document.getElementById('commento-autore')?.value.trim() || '';
    const testo  = document.getElementById('commento-testo')?.value.trim() || '';
    if (!testo) { toast('Scrivi il commento prima di inviarlo','err'); return; }
    const res = await api({_action:'aggiungi_commento', id:_viewId, autore, testo});
    if (res.ok) {
        document.getElementById('commento-testo').value = '';
        caricaCommenti(_viewId);
    }
}

async function eliminaCommento(cid) {
    if (!confirm('Eliminare questo commento?')) return;
    await api({_action:'elimina_commento', cid});
    caricaCommenti(_viewId);
}

// ── OFFLINE HANDLER ───────────────────────────────────────────
let _offlineCount = 0;
const _origApi = api;
window.api = async function(params) {
    try {
        const res = await _origApi(params);
        if (_offlineCount > 0) {
            _offlineCount = 0;
            document.getElementById('offline-banner').style.display = 'none';
            toast('✓ Connessione ripristinata','ok');
        }
        return res;
    } catch(e) {
        _offlineCount++;
        const banner = document.getElementById('offline-banner');
        if (banner) banner.style.display = 'flex';
        // Retry automatico dopo 5 secondi
        if (_offlineCount <= 3) setTimeout(() => caricaLista(), 5000);
        throw e;
    }
};

// ── DASHBOARD KPI ─────────────────────────────────────────────
function apriDashboard() {
    document.getElementById('dash-overlay').classList.add('open');
    caricaDashboard();
}
function chiudiDash() {
    document.getElementById('dash-overlay').classList.remove('open');
}

async function caricaDashboard() {
    const body = document.getElementById('dash-body');
    body.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8">Caricamento KPI…</div>';
    const d = await api({_action:'dashboard'});

    const colori = ['#2d7dd2','#7c3aed','#16a34a','#d97706','#dc2626','#0891b2','#9333ea'];

    // Tasso chiusura
    const tasso = d.tasso_chiusura || 0;
    const cerchio = `<div style="display:flex;align-items:center;gap:24px;padding:16px;
        background:rgba(45,125,210,.06);border-radius:10px;border:1px solid rgba(45,125,210,.15)">
        <div style="position:relative;width:80px;height:80px;flex-shrink:0">
            <svg viewBox="0 0 36 36" style="width:80px;height:80px;transform:rotate(-90deg)">
                <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e2e8f0" stroke-width="3"/>
                <circle cx="18" cy="18" r="15.9" fill="none" stroke="#2d7dd2" stroke-width="3"
                    stroke-dasharray="${tasso} ${100-tasso}" stroke-linecap="round"/>
            </svg>
            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
                font-size:.85rem;font-weight:700;color:#1a2535">${tasso}%</div>
        </div>
        <div>
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b">Tasso di chiusura globale</div>
            <div style="font-size:1.4rem;font-weight:700;color:#1a2535;margin-top:2px">${d.totale} lavorazioni totali</div>
            ${d.urgenti_scadute > 0 ? `<div style="color:#dc2626;font-size:.78rem;font-weight:600;margin-top:4px">⚠ ${d.urgenti_scadute} urgenti aperte da > 3 giorni</div>` : '<div style="color:#16a34a;font-size:.78rem;margin-top:4px">✓ Nessuna urgente scaduta</div>'}
        </div>
    </div>`;

    // Grafico per tipo (barre orizzontali)
    const maxN = Math.max(...(d.per_tipo||[]).map(r=>r.n), 1);
    const barreHTML = (d.per_tipo||[]).map((r,i) =>
        `<div style="display:grid;grid-template-columns:140px 1fr 30px;gap:8px;align-items:center;margin-bottom:6px">
            <span style="font-size:.75rem;font-weight:600;color:#1a2535;overflow:auto;text-overflow:ellipsis;white-space:nowrap">${esc(r.tipo)}</span>
            <div style="background:#e2e8f0;border-radius:4px;height:10px;overflow:auto">
                <div style="width:${Math.round(r.n/maxN*100)}%;height:100%;background:${colori[i%colori.length]};border-radius:4px;transition:width .5s"></div>
            </div>
            <span style="font-size:.75rem;font-weight:700;color:#64748b">${r.n}</span>
        </div>`).join('');

    // Tempi medi chiusura
    const tempiHTML = (d.tempi_medi||[]).length === 0
        ? '<p style="font-size:.78rem;color:#94a3b8">Nessuna lavorazione chiusa ancora.</p>'
        : (d.tempi_medi||[]).map(r =>
            `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:.78rem">
                <span style="color:#1a2535;font-weight:500">${esc(r.tipo)}</span>
                <span style="font-family:var(--mono);color:#2d7dd2;font-weight:700">${r.giorni_medi} giorni <span style="color:#94a3b8;font-weight:400">(${r.n} chiuse)</span></span>
            </div>`).join('');

    // Trend mensile
    const mesiHTML = (d.per_mese||[]).map(r => {
        const label = r.mese.split('-').reverse().join('/').replace(/^\d{2}/,'').replace(/^(\d{2})\/(\d{4})$/,'$1/$2');
        return `<div style="text-align:center;flex:1">
            <div style="font-size:.65rem;color:#94a3b8;margin-bottom:4px">${r.mese.slice(5)}</div>
            <div style="font-size:.85rem;font-weight:700;color:#2d7dd2">${r.inserite}</div>
            <div style="font-size:.7rem;color:#16a34a">${r.chiuse} ✓</div>
        </div>`;
    }).join('');

    body.innerHTML = `
        <div style="display:grid;gap:16px">
            ${cerchio}
            <div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:10px">Lavorazioni per tipo</div>
                ${barreHTML}
            </div>
            <div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:8px">Tempo medio chiusura per tipo</div>
                ${tempiHTML}
            </div>
            ${(d.per_mese||[]).length > 0 ? `
            <div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:10px">Trend ultimi 6 mesi</div>
                <div style="display:flex;gap:8px;justify-content:space-between">${mesiHTML}</div>
            </div>` : ''}
        </div>`;
}

// ── REPORT MENSILE ────────────────────────────────────────────
function apriReportMensile() {
    const oggi = new Date();
    document.getElementById('report-mese').value =
        oggi.getFullYear()+'-'+String(oggi.getMonth()+1).padStart(2,'0');
    document.getElementById('report-overlay').classList.add('open');
}
function chiudiReport() {
    document.getElementById('report-overlay').classList.remove('open');
}
function scaricaReport() {
    const mese = document.getElementById('report-mese').value;
    if (!mese) { toast('Seleziona un mese','err'); return; }
    const f = document.createElement('form');
    f.method='POST'; f.action=EP;
    f.innerHTML=`<input name="_action" value="report_mensile"><input name="mese" value="${esc(mese)}">`;
    document.body.appendChild(f); f.submit(); document.body.removeChild(f);
    toast('Report mensile in download…','info');
    chiudiReport();
}

// ── BACKUP MANUALE ────────────────────────────────────────────
async function backupManuale() {
    const status = document.getElementById('backup-status');
    if (status) status.textContent = 'Backup in corso…';
    const res = await api({_action:'backup_now', pin: SETTINGS_PIN_session});
    if (res.ok) {
        toast(res.msg || 'Backup completato ✓','ok');
        if (status) status.textContent = '✓ ' + (res.msg||'Completato');
    } else {
        toast(res.err||'Errore backup','err');
    }
}

// ── AUDIT LOG VIEWER ──────────────────────────────────────────
async function mostraLog() {
    const wrap = document.getElementById('audit-log-wrap');
    wrap.innerHTML = 'Caricamento…';
    const res = await api({_action:'audit_log', pin: SETTINGS_PIN_session});
    if (!res.ok) { wrap.innerHTML = '<span style="color:#dc2626">'+esc(res.err||'Errore')+'</span>'; return; }
    if (!res.rows.length) { wrap.innerHTML = '<span style="color:#94a3b8">Nessuna operazione registrata.</span>'; return; }
    const coloriAzioni = {INSERISCI:'#16a34a',CHIUDI:'#2d7dd2',MODIFICA:'#d97706',ELIMINA:'#dc2626',BACKUP:'#7c3aed',REPORT_MENSILE:'#0891b2'};
    wrap.innerHTML = res.rows.map(r => {
        const col = coloriAzioni[r.azione] || '#64748b';
        return `<div style="padding:2px 0;border-bottom:1px solid #f1f5f9">
            <span style="color:#94a3b8">${r.ts}</span>
            <span style="color:${col};font-weight:700;margin:0 6px">[${esc(r.azione)}]</span>
            <span style="color:#1a2535">${esc(r.utente)}</span>
            ${r.dettaglio ? `<span style="color:#64748b"> - ${esc(r.dettaglio)}</span>` : ''}
        </div>`;
    }).join('');
}

ricaricaCfg().then(caricaLista);
