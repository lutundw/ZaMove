/* Zamove front end. Real data from api/v1 (REAL APP). Pages: home and results here; the rest are route chunks in js/routes/. */
const {html,raw,put}=Safe;
const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>[...r.querySelectorAll(s)];
const K=n=>'K '+Number(n).toLocaleString('en-US');
// REAL APP: no mock data any more. Trips, towns and prices come from the API (api/v1/schedules, api/v1/places).
const CAT={timeZone:'Africa/Lusaka'};                                     // Zambia is UTC+2 all year
const fmt={
 time:iso=>iso?new Date(iso).toLocaleTimeString('en-GB',{...CAT,hour:'2-digit',minute:'2-digit'}):'',
 date:iso=>iso?new Date(iso).toLocaleDateString('en-GB',{...CAT,weekday:'short',day:'numeric',month:'short',year:'numeric'}):'',
 dur:(a,b)=>{if(!a||!b)return'';const m=Math.round((new Date(b)-new Date(a))/60000);return Math.floor(m/60)+'h'+(m%60?' '+m%60+'m':'')},
 today:()=>new Date().toLocaleDateString('en-CA',CAT)                       // YYYY-MM-DD in Zambia
};
const api=(m,p,b,o)=>window.ZamoveApi.request(m,p,b,o);
const S={get:(k,d)=>{try{return JSON.parse(sessionStorage.getItem(k))||d}catch(e){return d}},set:(k,v)=>{try{sessionStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
const opts=(a,s)=>a.map(x=>html`<option${x==s?' selected':''}>${x}</option>`);
function chrome(){const b=document.body,d=b.classList.contains('dash');
if(!d){$('.nav-ph')&&$('.nav-ph').remove();b.insertAdjacentHTML('afterbegin',`<header class="nav"><div class="wrap row"><a class="logo" href="index.html"><b>ZM</b>Zamove</a><nav class="row sp" aria-label="Main"><a href="index.html">Search</a><a href="bookings.html">My bookings</a><a href="cargo.html">Send cargo</a><a href="login.html">Log in</a><a class="btn s" href="login.html?r=1">Register</a></nav></div></header>`);
b.insertAdjacentHTML('beforeend',`<footer class="foot"><div class="wrap row"><div><b>Zamove</b><br>Connecting Zambia, one journey at a time.</div><div class="sp">Help centre · Booking terms · Privacy<br>hello@zamove.example · Demo design</div></div></footer>`)}
b.insertAdjacentHTML('afterbegin','<a class="skip" href="#main">Skip to content</a>')}
function tabs(){$$('[data-tab]').forEach(t=>t.onclick=()=>{$$('[data-tab]').forEach(x=>x.setAttribute('aria-selected',x==t));$$('[data-panel]').forEach(p=>p.classList.toggle('hid',p.dataset.panel!=t.dataset.tab))})}
const P={
async home(){const f=$('#from'),t=$('#to');$('#date').min=fmt.today();$('#date').value=fmt.today();
$('#swap').onclick=()=>{[f.value,t.value]=[t.value,f.value]};
try{const [pl,all]=await Promise.all([api('GET','places'),api('GET','schedules?limit=100')]);
 const towns=pl.data.data;
 put(f,towns.map(x=>html`<option>${x}</option>`));put(t,towns.map(x=>html`<option>${x}</option>`));
 if(towns.length>1)t.selectedIndex=1;
 if(!towns.length)put($('#pop'),html`<p class="empty">No trips are on sale yet. Check back soon, or <a href="login.html?r=1">join as an operator</a> to list the first one.</p>`);
 else{const g={};all.data.data.forEach(x=>{const k=x.origin+'|'+x.destination;(g[k]=g[k]||{o:x.origin,d:x.destination,n:0,ops:new Set(),min:1e9});g[k].n++;g[k].ops.add(x.operator_name);g[k].min=Math.min(g[k].min,x.fare_per_seat)});
  put($('#pop'),Object.values(g).slice(0,6).map(x=>html`<a class="card" href="${'results.html?from='+encodeURIComponent(x.o)+'&to='+encodeURIComponent(x.d)}"><b>${x.o} → ${x.d}</b><br><span class="mu">${x.n} departure${x.n>1?'s':''} · ${x.ops.size} operator${x.ops.size>1?'s':''}</span><div class="big">from ${K(x.min)}</div></a>`))}
}catch(e){put($('#pop'),html`<p class="err" role="alert">Could not load trips: ${e.message}</p>`)}},
async results(){const q=new URLSearchParams(location.search),f=q.get('from')||'',t=q.get('to')||'',pax=Math.min(10,Math.max(1,+q.get('pax')||1)),d=q.get('date')||'';
S.set('q',{f,t,pax,d});$('#h').textContent=f&&t?`${f} → ${t}`:'All departures';
$('#sub').textContent=`${d?fmt.date(d+'T12:00:00+02:00'):'Any day'} · ${pax} passenger${pax>1?'s':''} · One way`;
let rows=[];
try{const qs=new URLSearchParams({limit:'100'});if(f)qs.set('origin',f);if(t)qs.set('destination',t);if(d)qs.set('date',d);
 rows=(await api('GET','schedules?'+qs)).data.data}catch(e){put($('#list'),html`<p class="err" role="alert">Could not load trips: ${e.message}</p>`);return}
const ops=[...new Set(rows.map(x=>x.operator_name))].sort();
put($('#ops'),ops.map(o=>html`<label><input class="fo" type="checkbox" value="${o}" checked> ${o}</label>`));
const top=Math.max(50,Math.ceil(Math.max(0,...rows.map(x=>x.fare_per_seat))/10)*10);$('#mx').max=top;$('#mx').value=top;
const draw=()=>{const on=$$('.fo:checked').map(x=>x.value),mx=+$('#mx').value;let l=rows.filter(x=>on.includes(x.operator_name)&&x.fare_per_seat<=mx);
l.sort($('#so').value=='p'?(a,b)=>a.fare_per_seat-b.fare_per_seat:(a,b)=>a.departure_at.localeCompare(b.departure_at));$('#mxv').textContent=K(mx);
put($('#list'),l.length?l.map(x=>{const ok=x.seats_available>=pax;return html`<article class="card trip"><div><b>${x.operator_name}</b><br><span class="chip">${x.vehicle_type}</span></div><div><span class="mu">${fmt.date(x.departure_at)}</span><br><span class="big">${fmt.time(x.departure_at)}</span> → <span class="big">${fmt.time(x.arrival_at)}</span><br><span class="mu">${fmt.dur(x.departure_at,x.arrival_at)} · ${x.origin} → ${x.destination}</span><br><span class="chip ${x.seats_available<=5?'w':''}">${x.seats_available} seat${x.seats_available==1?'':'s'} left</span></div><div><div class="big">${K(x.fare_per_seat)}</div><div class="mu">per passenger</div>${ok?html`<a class="btn o s" href="${'booking.html?trip='+x.schedule_id+'&pax='+pax}">Select seats</a>`:html`<span class="chip r">Not enough seats</span>`}</div></article>`}):html`<p class="empty">${rows.length?'No trips match your filters. Raise the price limit or select more operators.':'No trips found for this search. Try another date or route.'}</p>`)};
$$('.fo,#mx,#so').forEach(e=>e.oninput=draw);$('#ops').onchange=draw;draw()}
};
// ---- LAB 9: route-based code splitting. Everything except home/results is loaded with import() only on the page that needs it. ----
// (tools/build.js rewrites these paths to the content-hashed file names.)
const LAZY={booking:()=>import('./routes/booking.js'),checkout:()=>import('./routes/checkout.js'),ticket:()=>import('./routes/ticket.js'),bookings:()=>import('./routes/bookings.js'),login:()=>import('./routes/login.js'),cargo:()=>import('./routes/cargo.js'),dash:()=>import('./routes/dash.js')};
const Z={$,$$,K,html,raw,put,S,opts,fmt,api};
const loadRoute=async n=>(await LAZY[n]()).default(Z)();
// Yield to the browser between start-up steps so no single task blocks input for more than ~50 ms (INP).
const yieldToMain=()=>(globalThis.scheduler&&scheduler.yield)?scheduler.yield():new Promise(r=>setTimeout(r));
(async()=>{chrome();tabs();await yieldToMain();const pg=document.body.dataset.p;
if(P[pg])P[pg]();else if(LAZY[pg])await loadRoute(pg);
if(document.body.classList.contains('dash'))await loadRoute('dash')})();
