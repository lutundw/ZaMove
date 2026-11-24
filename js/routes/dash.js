/* REAL APP: route chunk 'dash'. One file serves the operator workspace and the admin approvals page (body data-p). */
export default function({$,$$,K,html,raw,put,S,opts,fmt,api}){
  const say=(el,msg,bad)=>{el.textContent=msg;el.classList.toggle('err',!!bad)};
  const errText=e=>Object.values(e.fieldErrors||{})[0]||e.message;
  const catIso=v=>v+':00+02:00';                                    // <input type=datetime-local> is Zambia time (UTC+2)

  async function admin(){
    const load=async()=>{
      try{
        const rows=(await api('GET','admin/operators')).data.data;
        const chip=s=>s==='approved'?'':s==='rejected'?'r':'w';
        put($('#ops'),rows.length?html`<table class="tbl"><tr><th>Company</th><th>Owner</th><th>Address</th><th>Status</th><th>Action</th></tr>${rows.map(o=>html`<tr><td><b>${o.business_name}</b><br><span class="mu">${o.business_type}</span></td><td>${o.owner_name}<br><span class="mu">${o.owner_email} · ${o.owner_phone}</span></td><td>${o.business_address}</td><td><span class="chip ${chip(o.verification_status)}">${o.verification_status}</span></td><td><button class="btn s act" data-id="${o.operator_id}" data-d="approve">Approve</button> <button class="btn s l act" data-id="${o.operator_id}" data-d="reject">Reject</button></td></tr>`)}</table>`:html`<p class="mu">No operator companies have registered yet.</p>`);
        $$('.act').forEach(b=>b.onclick=async()=>{
          b.disabled=true;
          try{const r=await api('POST',`admin/operators/${b.dataset.id}/decision`,{decision:b.dataset.d});say($('#admMsg'),r.data.message);await load()}
          catch(e){say($('#admMsg'),errText(e),true);b.disabled=false}
        });
      }catch(e){say($('#admMsg'),e.message,true)}
    };
    await load();
  }

  async function operator(){
    let routes=[],vehicles=[];
    const stat=(id,v)=>{$(id).textContent=v};
    const loadFleet=async()=>{
      [routes,vehicles]=(await Promise.all([api('GET','operator/routes'),api('GET','operator/vehicles')])).map(r=>r.data.data);
      put($('#vehs'),vehicles.length?html`<table class="tbl"><tr><th>Registration</th><th>Type</th><th>Seats</th><th>Status</th></tr>${vehicles.map(v=>html`<tr><td>${v.registration_number}</td><td>${v.vehicle_type}</td><td>${v.seat_capacity}</td><td><span class="chip">${v.vehicle_status}</span></td></tr>`)}</table>`:html`<p class="mu">No vehicles yet. Add one above.</p>`);
      put($('#rts'),routes.length?html`<table class="tbl"><tr><th>Route</th><th>Usual fare</th><th>Status</th></tr>${routes.map(r=>html`<tr><td>${r.origin} → ${r.destination}</td><td>${K(r.base_fare)}</td><td><span class="chip">${r.route_status}</span></td></tr>`)}</table>`:html`<p class="mu">No routes yet. Add one above.</p>`);
      put($('#a-route'),routes.filter(r=>r.route_status==='active').map(r=>html`<option value="${r.route_id}" data-fare="${r.base_fare}">${r.origin} → ${r.destination}</option>`));
      put($('#a-veh'),vehicles.filter(v=>v.vehicle_status==='active').map(v=>html`<option value="${v.vehicle_id}" data-seats="${v.seat_capacity}">${v.registration_number} (${v.seat_capacity} seats)</option>`));
      const need=[];if(!routes.length)need.push('Add a route');if(!vehicles.length)need.push('add a vehicle');
      $('#tripNeeds').textContent=need.length?need.join(' and ')+' in "Fleet and routes" before you can publish a departure.':'';
      $('#tripNeeds').classList.toggle('hid',!need.length);
      sync();
    };
    const sync=()=>{const r=$('#a-route').selectedOptions[0],v=$('#a-veh').selectedOptions[0];
      if(r&&!$('#a-fare').dataset.touched)$('#a-fare').value=r.dataset.fare;if(v&&!$('#a-seats').dataset.touched)$('#a-seats').value=v.dataset.seats};
    $('#a-route').onchange=sync;$('#a-veh').onchange=sync;
    $('#a-fare').oninput=()=>{$('#a-fare').dataset.touched=1};$('#a-seats').oninput=()=>{$('#a-seats').dataset.touched=1};

    const loadLists=async()=>{
      const [sc,bk]=(await Promise.all([api('GET','operator/schedules'),api('GET','operator/bookings')])).map(r=>r.data.data);
      const now=Date.now(),up=sc.filter(s=>s.schedule_status==='scheduled'&&new Date(s.departure_at)>now);
      const paid=bk.filter(b=>b.booking_status==='confirmed'||b.booking_status==='completed');
      stat('#s-dep',up.length);stat('#s-seats',paid.reduce((a,b)=>a+b.seats,0));stat('#s-rev',K(paid.reduce((a,b)=>a+b.total_amount,0)));
      stat('#s-pend',bk.filter(b=>b.booking_status==='pending').length);
      put($('#deps'),sc.length?html`<table class="tbl"><tr><th>Route</th><th>Departs</th><th>Bus</th><th>Seats sold</th><th>Status</th></tr>${sc.map(s=>html`<tr><td>${s.origin} → ${s.destination}</td><td>${fmt.date(s.departure_at)} ${fmt.time(s.departure_at)}</td><td>${s.registration_number}</td><td>${s.total_seats-s.seats_available} / ${s.total_seats}</td><td><span class="chip ${s.seats_available===0?'w':''}">${s.seats_available===0?'full':s.schedule_status}</span></td></tr>`)}</table>`:html`<p class="mu">No departures yet. Use "Add departure".</p>`);
      put($('#bks'),bk.length?html`<table class="tbl"><tr><th>Reference</th><th>Passenger(s)</th><th>Trip</th><th>Seats</th><th>Amount</th><th>Status</th></tr>${bk.slice(0,15).map(b=>html`<tr><td>${b.booking_reference}</td><td>${b.passenger_name||b.customer_name}<br><span class="mu">${b.customer_phone}</span></td><td>${b.origin} → ${b.destination}<br><span class="mu">${fmt.date(b.departure_at)} ${fmt.time(b.departure_at)}</span></td><td>${b.seats}</td><td>${K(b.total_amount)}</td><td><span class="chip ${b.booking_status==='confirmed'?'':'w'}">${b.booking_status}</span></td></tr>`)}</table>`:html`<p class="mu">No bookings yet.</p>`);
    };

    $('#addTripForm').onsubmit=async e=>{
      e.preventDefault();const m=$('#tripMsg');
      if(!$('#a-dep').value||!$('#a-arr').value){say(m,'Enter departure and arrival times.',true);return}
      say(m,'Saving…');
      try{
        await api('POST','schedules',{route_id:+$('#a-route').value,vehicle_id:+$('#a-veh').value,departure_time:catIso($('#a-dep').value),arrival_time:catIso($('#a-arr').value),fare_per_seat:+$('#a-fare').value,total_seats:+$('#a-seats').value});
        say(m,'Departure published. Passengers can now find it in search.');await loadLists();
      }catch(err){say(m,errText(err),true)}
    };
    $('#vehForm').onsubmit=async e=>{
      e.preventDefault();const m=$('#vehMsg');say(m,'Saving…');
      try{await api('POST','operator/vehicles',{registration_number:$('#v-reg').value.trim(),vehicle_type:$('#v-type').value,seat_capacity:+$('#v-seats').value});
        say(m,'Vehicle added.');$('#vehForm').reset();await loadFleet()}catch(err){say(m,errText(err),true)}
    };
    $('#routeForm').onsubmit=async e=>{
      e.preventDefault();const m=$('#routeMsg');say(m,'Saving…');
      const b={origin:$('#r-from').value.trim(),destination:$('#r-to').value.trim(),base_fare:+$('#r-fare').value};if($('#r-km').value)b.distance_km=+$('#r-km').value;
      try{await api('POST','operator/routes',b);say(m,'Route added.');$('#routeForm').reset();await loadFleet()}catch(err){say(m,errText(err),true)}
    };

    try{await loadFleet();await loadLists()}catch(e){put($('#deps'),html`<p class="err" role="alert">${e.message}</p>`)}
    let busy=false;
    window.addEventListener('zamove:realtime',async()=>{if(busy)return;busy=true;try{await loadLists()}finally{busy=false}});   // Lab 8: live refresh
  }

  return ()=>document.body.dataset.p==='admin'?admin():operator();
}
