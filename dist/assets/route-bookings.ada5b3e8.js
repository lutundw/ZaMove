export default function({$, $$, K, html, raw, put, S, TRIPS, TOWNS, opts}){
  async function bookings(){
    const up = $('#up');
    const pa = $('#pa');
    const ca = $('#ca');
    try {
      const res = await ZamoveApi.request('GET', 'bookings?limit=50');
      const rows = res.data.data || [];
      if (!rows.length) {
        put(up, html`<p class="mu">No bookings yet. <a href="results.html">Book a trip</a></p>`);
        put(pa, html`<p class="mu">No past bookings.</p>`);
        return;
      }
      const isPast = s => ['completed','cancelled','rejected'].includes(s);
      const L = (a) => a.map(x => html`
        <div class="card row"><div>
          <b>${x.booking_reference}</b><br>
          <span class="mu">${x.created_at} · K${x.total_amount}</span>
        </div>
        <span class="chip ${x.booking_status === 'confirmed' || x.booking_status === 'completed' ? '' : 'w'} sp">${x.booking_status}</span>
        <a class="btn s" href="ticket.html?id=${x.booking_id}">View ticket</a>
        </div>`);
      put(up, L(rows.filter(b => !isPast(b.booking_status))));
      put(pa, L(rows.filter(b => isPast(b.booking_status))));
      if (ca) put(ca, html`<p class="mu">No cargo bookings yet.</p>`);
    } catch (e) {
      put(up, html`<p class="err">${e.message}</p>`);
    }
  }
  return bookings;
}