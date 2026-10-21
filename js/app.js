/* Zamove demo frontend. Mock data below; swap for fetch('/api/...') when the PHP backend is ready. */
const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>[...r.querySelectorAll(s)];
const K=n=>'K '+Number(n).toLocaleString('en-US');
const TOWNS=['Lusaka','Kitwe','Ndola','Livingstone','Kabwe','Chipata','Solwezi'];
const TRIPS=[
{id:1,op:'Power Tools',tag:'Executive',dep:'07:00',arr:'13:00',dur:'6h',fare:280,seats:12,r:4.8,am:'AC · USB charging · Reclining seats'},
{id:2,op:'Mazhandu Family Bus',tag:'Standard',dep:'08:00',arr:'14:30',dur:'6h 30m',fare:260,seats:18,r:4.7,am:'AC · Reclining seats'},
{id:3,op:'Shalom Bus',tag:'Standard',dep:'09:30',arr:'16:00',dur:'6h 30m',fare:240,seats:9,r:4.5,am:'AC · Luggage included'}];
const S={get:(k,d)=>{try{return JSON.parse(sessionStorage.getItem(k))||d}catch(e){return d}},set:(k,v)=>{try{sessionStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
const opts=(a,s)=>a.map(x=>`<option${x==s?' selected':''}>${x}</option>`).join('');
function chrome(){const b=document.body,d=b.classList.contains('dash');
if(!d){b.insertAdjacentHTML('afterbegin',`<header class="nav"><div class="wrap row"><a class="logo" href="index.html"><b>ZM</b>Zamove</a><nav class="row sp" aria-label="Main"><a href="index.html">Search</a><a href="bookings.html">My bookings</a><a href="cargo.html">Send cargo</a><a href="login.html">Log in</a><a class="btn s" href="login.html?r=1">Register</a></nav></div></header>`);
b.insertAdjacentHTML('beforeend',`<footer class="foot"><div class="wrap row"><div><b>Zamove</b><br>Connecting Zambia, one journey at a time.</div><div class="sp">Help centre · Booking terms · Privacy<br>hello@zamove.example · Demo design</div></div></footer>`)}
b.insertAdjacentHTML('afterbegin','<a class="skip" href="#main">Skip to content</a>')}
function tabs(){$$('[data-tab]').forEach(t=>t.onclick=()=>{$$('[data-tab]').forEach(x=>x.setAttribute('aria-selected',x==t));$$('[data-panel]').forEach(p=>p.classList.toggle('hid',p.dataset.panel!=t.dataset.tab))})}
const P={
home(){$('#from').innerHTML=opts(TOWNS,'Lusaka');$('#to').innerHTML=opts(TOWNS,'Kitwe');$('#date').value='2026-10-24';
$('#swap').onclick=()=>{[$('#from').value,$('#to').value]=[$('#to').value,$('#from').value]}},
results(){const q=new URLSearchParams(location.search),f=q.get('from')||'Lusaka',t=q.get('to')||'Kitwe',pax=+q.get('pax')||2,d=q.get('date')||'2026-10-24';
S.set('q',{f,t,pax,d});$('#h').textContent=`${f} → ${t}`;$('#sub').textContent=`${d} · ${pax} adult${pax>1?'s':''} · One way`;
const draw=()=>{const ops=$$('.fo:checked').map(x=>x.value),mx=+$('#mx').value;let l=TRIPS.filter(x=>ops.includes(x.op)&&x.fare<=mx);
l.sort($('#so').value=='p'?(a,b)=>a.fare-b.fare:(a,b)=>a.dep.localeCompare(b.dep));$('#mxv').textContent=K(mx);
$('#list').innerHTML=l.map(x=>`<article class="card trip"><div><b>${x.op}</b><br><span class="chip">★ ${x.r} · ${x.tag}</span></div><div><span class="big">${x.dep}</span> → <span class="big">${x.arr}</span><br><span class="mu">${x.dur} · Direct · ${x.am}</span><br><span class="chip w">${x.seats} seats left · 2 bags included</span></div><div><div class="big">${K(x.fare)}</div><div class="mu">per adult</div><a class="btn o s" href="booking.html?trip=${x.id}">Select seats</a></div></article>`).join('')||'<p class="empty">No trips match. Raise the price limit or select more operators.</p>'};
$$('.fo,#mx,#so').forEach(e=>e.oninput=draw);draw()},
booking(){const t=TRIPS.find(x=>x.id==new URLSearchParams(location.search).get('trip'))||TRIPS[0],q=S.get('q',{f:'Lusaka',t:'Kitwe',pax:2,d:'2026-10-24'});
const taken=[3,4,9,10,11,15,16,20];let sel=[],nm={};$('#tinfo').textContent=`${t.op} · ${q.f} → ${q.t} · ${q.d} · ${t.dep}`;
let h='';for(let i=1;i<=24;i++){const x=taken.includes(i);h+=`<button type="button" class="seat${x?' t':''}" data-n="${i}" aria-label="Seat ${i}${x?', booked':''}"${x?' disabled':''}>${i}</button>`;if(i%4==2)h+='<span class="gap"></span>'}$('#seats').innerHTML=h;
const draw=()=>{$$('.seat').forEach(b=>b.classList.toggle('s',sel.includes(+b.dataset.n)));
$('#pf').innerHTML=sel.map((n,i)=>`<fieldset class="card"><legend>Passenger ${i+1} · Seat ${n}</legend><label>Full name (as on ID)</label><input class="in pn" data-n="${n}" value="${nm[n]||''}" required><label>NRC or passport number (optional)</label><input class="in"></fieldset>`).join('');
$$('.pn').forEach(e=>e.oninput=()=>nm[e.dataset.n]=e.value);
$('#ssum').textContent=sel.length?`${sel.length} of ${q.pax} seats: ${sel.join(', ')}`:`Select ${q.pax} seat${q.pax>1?'s':''}`;
$('#fare').textContent=K(sel.length*t.fare);$('#total').textContent=K(sel.length*t.fare+(sel.length?20:0))};
$('#seats').onclick=e=>{const n=+e.target.dataset.n;if(!n)return;if(sel.includes(n))sel=sel.filter(x=>x!=n);else if(sel.length<q.pax)sel.push(n);else $('#msg').textContent=`You are booking for ${q.pax}. Unselect a seat first.`;draw()};
$('#go').onclick=()=>{const em=$('#email').value,ph=$('#phone').value;if(sel.length!=q.pax||!em||!ph||sel.some(n=>!nm[n])){$('#msg').textContent='Select all seats, enter every passenger name, and add your phone and email.';return}
S.set('order',{trip:t,q,seats:sel,names:sel.map(n=>nm[n]),total:sel.length*t.fare+20,email:em,phone:ph});location='checkout.html'}
draw()},
checkout(){const o=S.get('order');if(!o){location='index.html';return}
$('#sum').innerHTML=`<b>${o.trip.op}</b><br>${o.q.f} → ${o.q.t}, ${o.q.d} · ${o.trip.dep}<br>Seats ${o.seats.join(', ')}<div class="tot">${K(o.total)}</div><span class="mu">Includes K 20 booking fee</span>`;$('#amt').textContent=K(o.total);
const show=x=>{$$('[data-st]').forEach(p=>p.classList.toggle('hid',p.dataset.st!=x))};
$('#pay').onsubmit=e=>{e.preventDefault();show('p');setTimeout(()=>{if($('#fail').checked)return show('f');o.ref='ZM'+Math.random().toString(36).slice(2,8).toUpperCase();S.set('order',o);show('s');setTimeout(()=>location='ticket.html',1200)},1800)};
$('#retry').onclick=()=>{$('#fail').checked=false;show('')}},
ticket(){const o=S.get('order');if(!o||!o.ref){location='index.html';return}let r=0;for(const c of o.ref)r=r*31+c.charCodeAt(0);let q='';
for(let y=0;y<9;y++)for(let x=0;x<9;x++){r=(r*1103515245+12345)&0x7fffffff;if(r>>16&1)q+=`<rect x="${x*10}" y="${y*10}" width="10" height="10"/>`}
$('#tk').innerHTML=`<div class="row"><div><span class="chip">Confirmed</span><h2>${o.q.f} → ${o.q.t}</h2><b>${o.trip.op}</b> · ${o.trip.tag}</div><svg class="sp" viewBox="0 0 90 90" role="img" aria-label="Ticket QR code">${q}</svg></div><p>Reference <b>${o.ref}</b><br>${o.q.d} · Departs ${o.trip.dep} · Arrives ${o.trip.arr}<br>Boarding: Intercity Bus Terminus, Lusaka</p><table class="tbl"><tr><th>Passenger</th><th>Seat</th></tr>${o.names.map((n,i)=>`<tr><td>${n}</td><td>${o.seats[i]}</td></tr>`).join('')}</table><p class="mu">Ticket sent to ${o.email}. Show the QR code when boarding.</p>`;
$('#pr').onclick=()=>print()},
bookings(){const o=S.get('order'),u=[['Lusaka → Livingstone','2026-11-02','Shalom Bus','ZMA1B2C','Confirmed'],['Kitwe → Lusaka','2026-11-09','Power Tools','ZMD4E5F','Pending payment']];
if(o&&o.ref)u.unshift([`${o.q.f} → ${o.q.t}`,o.q.d,o.trip.op,o.ref,'Confirmed']);
const L=(a)=>a.map(x=>`<div class="card row"><div><b>${x[0]}</b><br><span class="mu">${x[1]} · ${x[2]} · ${x[3]}</span></div><span class="chip ${x[4]=='Confirmed'||x[4]=='Completed'||x[4]=='Delivered'?'':'w'} sp">${x[4]}</span>${x[3]==(o||{}).ref?'<a class="btn s" href="ticket.html">View ticket</a>':''}</div>`).join('');
$('#up').innerHTML=L(u);$('#pa').innerHTML=L([['Lusaka → Ndola','2026-09-14','Mazhandu Family Bus','ZMX9Y8Z','Completed']]);$('#ca').innerHTML=L([['Parcels: Lusaka → Kabwe','2026-10-18','Shalom Bus','CG7781','Delivered'],['Farm produce: Chipata → Lusaka','2026-10-27','Power Tools','CG7792','Awaiting quotes']])},
login(){const r=new URLSearchParams(location.search).get('r');if(r)$('[data-tab=reg]').click();
$$('form').forEach(f=>f.onsubmit=e=>{e.preventDefault();const v=(new FormData(f).get('role')||'customer');location=v=='operator'?'operator.html':v=='admin'?'admin.html':'bookings.html'})},
cargo(){$('#rq').onsubmit=e=>{e.preventDefault();$('[data-tab=q]').click()};$$('.acc').forEach(b=>b.onclick=()=>$('[data-tab=s]').click())},
dash(){$$('.act').forEach(b=>b.onclick=()=>{const c=b.closest('tr').querySelector('.st');c.textContent=b.dataset.r;c.className='st chip '+(b.dataset.r=='Rejected'||b.dataset.r=='Suspended'?'r':'')})}
};
chrome();tabs();const pg=document.body.dataset.p;if(P[pg])P[pg]();if(document.body.classList.contains('dash'))P.dash();
