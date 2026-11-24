export default function({$, $$, K, html, raw, put, S, TRIPS, TOWNS, opts}){
  async function ticket(){
    const el = $('#ticket') || $('#up') || document.querySelector('main');
    const params = new URLSearchParams(location.search);
    const id = params.get('id');
    const o = S.get('order');

if (!id && o) {
  const seatList = Array.isArray(o.seats) ? o.seats.join(', ') : (o.seats || '');
  if (seatList) {
    put(el, html`<div class="card"><b>${o.ref}</b><br><span class="mu">${o.q.f} → ${o.q.t} · ${o.q.d} · ${o.trip.op}</span><br><b>K${o.total}</b><br><span class="mu">Seats: ${seatList}</span><br><span class="mu">Passengers: ${o.names ? o.names.join(', ') : ''}</span></div>`);
  } else {
    put(el, html`<div class="card"><b>${o.ref}</b><br><span class="mu">${o.q.f} → ${o.q.t} · ${o.q.d} · ${o.trip.op}</span><br><b>K${o.total}</b><br><span class="mu">Passengers: ${o.names ? o.names.join(', ') : ''}</span></div>`);
  }
  return;
}
    if (!id) { location.href = 'index.html'; return; }
    try {
      let b;
      if (window.ZamoveApi) {
        const res = await ZamoveApi.request('GET', 'bookings/' + id);
        b = res.data.data || res.data;
      } else {
        // fallback - same BASE as js/api.js
        let token = S.get('token') || localStorage.getItem('zamove_token') || localStorage.getItem('token') || '';
        const res = await fetch('api/v1/bookings/' + id, {
          headers: token ? { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' } : { 'Accept': 'application/json' }
        });
        const json = await res.json();
        b = json.data?.data || json.data || json;
        if (!res.ok) throw new Error(b.message || 'Booking not found');
      }
      put(el, html`<div class="card"><b>${b.booking_reference}</b><br><span class="mu">${b.created_at} · Status: ${b.booking_status}</span><br><b>Total: K${b.total_amount}</b><br><span class="mu">Seats: ${b.seats || b.seat_numbers || '-'}</span></div>`);
    } catch(e) {
      put(el, html`<p class="err">${e.message}</p>`);
    }
  }
  return ticket;
}