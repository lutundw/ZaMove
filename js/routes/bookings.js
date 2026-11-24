/* REAL APP: route chunk 'bookings'. The customer's own bookings (GET /bookings), refreshed live when the server pushes an event. */
export default function({$,$$,K,html,raw,put,S,opts,fmt,api}){
  async function bookings(){
    const up=$('#up'),pa=$('#pa'),ca=$('#ca');
    const load=async()=>{
      try{
        const rows=(await api('GET','bookings?limit=100')).data.data.filter(x=>x.booking_type==='passenger');
        const past=x=>['completed','cancelled','rejected'].includes(x.booking_status)||(x.departure_at&&new Date(x.departure_at)<Date.now());
        const L=a=>a.map(x=>html`<div class="card row"><div><b>${x.origin} → ${x.destination}</b><br><span class="mu">${fmt.date(x.departure_at)} · ${fmt.time(x.departure_at)} · ${x.operator_name} · ${x.seats} seat${x.seats>1?'s':''} · ${K(x.total_amount)}</span><br><span class="mu">${x.booking_reference}</span></div>
          <span class="chip ${x.booking_status==='confirmed'||x.booking_status==='completed'?'':'w'} sp">${x.booking_status}</span>
          ${x.booking_status==='pending'?html`<a class="btn s o" href="${'checkout.html?id='+x.booking_id}">Pay now</a>`:html`<a class="btn s" href="${'ticket.html?id='+x.booking_id}">View ticket</a>`}</div>`);
        const u=rows.filter(x=>!past(x)),p=rows.filter(past);
        put(up,u.length?L(u):html`<p class="mu">No upcoming bookings. <a href="index.html">Book a trip</a></p>`);
        put(pa,p.length?L(p):html`<p class="mu">No past bookings.</p>`);
        if(ca)put(ca,html`<p class="mu">Cargo booking is not part of this release.</p>`);
      }catch(e){put(up,html`<p class="err" role="alert">${e.message}</p>`)}
    };
    await load();
    window.addEventListener('zamove:realtime',load);          // Lab 8: the WebSocket tells us a status changed
  }
  return bookings;
}
