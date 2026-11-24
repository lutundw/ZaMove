export default function({$,$$,K,html,raw,put,S,opts,fmt,api}){async function ticket(){const id=+new URLSearchParams(location.search).get('id');$('#pr').onclick=()=>window.print();try{const b=(await api('GET','bookings/'+id)).data;const paid=b.booking_status==='confirmed'||b.booking_status==='completed';put($('#tk'),html`<h2>${paid?'Your e-ticket':'Booking '+b.booking_status}</h2>
        <p class="chip ${paid?'':'w'}">${b.booking_status}${b.payment_status?' · payment '+b.payment_status:''}</p>
        <h3>${b.origin} → ${b.destination}</h3>
        <p><b>${fmt.date(b.departure_at)}</b> · departs <b>${fmt.time(b.departure_at)}</b>${b.arrival_at?html` · arrives ${fmt.time(b.arrival_at)}`:''}<br><span class="mu">Times in Central Africa Time</span></p>
        <p>Operator: <b>${b.operator_name}</b><br>Passengers (${b.seats}): ${b.passenger_name||'-'}</p>
        <p class="big">${K(b.total_amount)}</p>
        <p class="mu">Reference <b>${b.booking_reference}</b>. Show this at boarding.</p>
        ${paid?'':html`<p><a class="btn o" href="${'checkout.html?id='+b.booking_id}">Pay now</a></p>`}
        <p class="mu">Payments in this release use a demonstration gateway.</p>`);}catch(e){put($('#tk'),html`<p class="err" role="alert">${e.message}</p>`)}
}
return ticket;}