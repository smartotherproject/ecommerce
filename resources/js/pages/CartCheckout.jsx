import { ArrowLeft, CheckCircle2, Minus, Plus, TicketPercent, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import { api, messageFrom, unwrap } from '../api/client';
import { Media, money } from '../components/Common';
import { useCart, useStore } from '../contexts/StoreContext';
import { PAKISTAN_CITIES, resolveShippingRates, shippingForCity } from '../data/shipping';

function cartPayload(items) {
    return items.map((x) => {
        const payload = { product_id: x.id, quantity: x.quantity };
        if (x.addons) payload.addons = x.addons;
        return payload;
    });
}

function AddonMeta({ item }) {
    const labels = (item.addon_summary || []).map((x) => x.label);
    if (!labels.length) return null;
    return <small className="cart-addons">{labels.join(' · ')}</small>;
}

export function CartPage() {
    const cart = useCart();
    return <div className="page shell narrow"><div className="page-title left"><span className="eyebrow">Your selection</span><h1>Shopping cart</h1></div>
        {!cart.items.length ? <div className="state-box"><h2>Your cart is empty</h2><p>Discover reliable tech picked for real life.</p><Link className="btn" to="/shop">Start shopping</Link></div> : <div className="cart-page-grid"><section className="cart-table">{cart.items.map((item) => {
            const key = item.lineKey || item.id;
            const price = Number(item.unit_price ?? item.sale_price ?? item.price ?? 0);
            return <div className="cart-page-line" key={key}>
                <Media src={item.image_url || item.thumbnail || item.image} alt={item.name} />
                <div>
                    <Link to={`/product/${item.slug}`}><h3>{item.name}</h3></Link>
                    <AddonMeta item={item} />
                    <span>{money(price)}</span>
                </div>
                <div className="qty">
                    <button onClick={() => cart.update(key, item.quantity - 1)}><Minus /></button>
                    <span>{item.quantity}</span>
                    <button onClick={() => cart.update(key, item.quantity + 1)}><Plus /></button>
                </div>
                <strong>{money(price * item.quantity)}</strong>
                <button className="remove" onClick={() => cart.remove(key)}><Trash2 /></button>
            </div>;
        })}</section><OrderTotal cart={cart} /></div>}
    </div>;
}

function OrderTotal({ cart, checkout = false, coupon = null, shipping = 0, city = '' }) {
    const discount = Number(coupon?.discount || 0);
    const shippingAmount = Number(shipping || 0);
    const total = Math.max(0, cart.total - discount) + shippingAmount;
    return <aside className="order-total"><h2>Order summary</h2><div><span>Items ({cart.count})</span><strong>{money(cart.total)}</strong></div><div><span>Delivery{city ? ` (${city})` : ''}</span>{checkout ? <strong>{city ? money(shippingAmount) : 'Select city'}</strong> : <span>Calculated at checkout</span>}</div>{discount > 0 && <div className="coupon-saving"><span>Coupon ({coupon.coupon?.code})</span><strong>-{money(discount)}</strong></div>}<hr /><div className="grand"><span>Total</span><strong>{money(total)}</strong></div>{!checkout && <Link className="btn btn-block" to="/checkout">Continue to checkout</Link>}<small>Cash on Delivery available nationwide.</small></aside>;
}

export function Checkout() {
    const cart = useCart();
    const store = useStore();
    const rates = useMemo(() => resolveShippingRates(store.settings || {}), [store.settings]);
    const [form, setForm] = useState({ customer_name: '', email: '', phone: '', city: '', address: '', notes: '' });
    const [loading, setLoading] = useState(false), [error, setError] = useState('');
    const [couponCode, setCouponCode] = useState(''), [coupon, setCoupon] = useState(null), [couponError, setCouponError] = useState(''), [applyingCoupon, setApplyingCoupon] = useState(false);
    const shipping = useMemo(() => shippingForCity(form.city, rates), [form.city, rates]);
    const applyCoupon = async () => {
        if (!couponCode.trim()) return;
        setApplyingCoupon(true); setCouponError('');
        try {
            const result = unwrap(await api.post('/coupons/validate', { coupon_code: couponCode, items: cartPayload(cart.items) }));
            setCoupon(result);
        } catch (err) { setCoupon(null); setCouponError(messageFrom(err)); } finally { setApplyingCoupon(false); }
    };
    const submit = async (e) => {
        e.preventDefault(); setLoading(true); setError('');
        try {
            const result = unwrap(await api.post('/orders', { ...form, coupon_code: coupon?.coupon?.code, items: cartPayload(cart.items) }));
            const order = result.order || result;
            try { sessionStorage.setItem('last_order', JSON.stringify(order)); } catch { /* storage blocked */ }
            cart.clear();
            window.history.replaceState({}, '', '/order-success');
            window.location.assign(`/order-success?order=${encodeURIComponent(order?.order_number || order?.id || '')}`);
        } catch (err) { setError(messageFrom(err)); setLoading(false); }
    };
    if (!cart.items.length) return <Navigate to="/cart" replace />;
    const field = (key, value) => setForm((x) => ({ ...x, [key]: value }));
    const finalTotal = Math.max(0, cart.total - Number(coupon?.discount || 0)) + shipping;
    return <div className="page shell"><div className="page-title left"><Link className="back-link" to="/cart"><ArrowLeft /> Back to cart</Link><h1>Delivery details</h1><p>Complete your order — pay cash when it arrives.</p></div><form className="checkout-grid" onSubmit={submit}><section className="checkout-form"><h2>Contact information</h2><div className="form-grid"><label>Full name<input required value={form.customer_name} onChange={(e) => field('customer_name', e.target.value)} /></label><label>Mobile number<input required inputMode="tel" placeholder="03XX XXXXXXX" value={form.phone} onChange={(e) => field('phone', e.target.value)} /></label><label className="full">Email address <small>(optional)</small><input type="email" value={form.email} onChange={(e) => field('email', e.target.value)} /></label></div><h2>Shipping address</h2><div className="form-grid"><label className="full">Complete address<textarea required rows="3" value={form.address} onChange={(e) => field('address', e.target.value)} /></label><label>City<select required value={form.city} onChange={(e) => field('city', e.target.value)}><option value="">Select city</option>{PAKISTAN_CITIES.map((city) => <option key={city} value={city}>{city}</option>)}</select><small className="city-shipping-hint">{form.city ? `Delivery: ${money(shipping)}` : `Karachi ${money(rates.karachi)} · Other cities ${money(rates.other)}`}</small></label><label className="full">Order notes<textarea rows="2" value={form.notes} onChange={(e) => field('notes', e.target.value)} placeholder="Landmark or special instructions" /></label></div><div className="coupon-box"><div><TicketPercent /><span><b>Discount coupon</b><small>Enter your voucher code</small></span></div>{coupon ? <div className="coupon-applied"><span><b>{coupon.coupon.code}</b> applied — you save {money(coupon.discount)}{coupon.coupon.applies_to && coupon.coupon.applies_to !== 'Whole order' && <small> (on {coupon.coupon.applies_to})</small>}</span><button type="button" onClick={() => { setCoupon(null); setCouponCode(''); }} aria-label="Remove coupon"><X /></button></div> : <div className="coupon-input"><input value={couponCode} onChange={(e) => setCouponCode(e.target.value.toUpperCase())} placeholder="e.g. WELCOME5" /><button type="button" className="btn btn-outline" onClick={applyCoupon} disabled={applyingCoupon}>{applyingCoupon ? 'Checking…' : 'Apply'}</button></div>}{couponError && <p className="form-error">{couponError}</p>}</div><div className="payment-box"><b>Cash on Delivery</b><p>Pay in cash when your order arrives. Our team may call to confirm your order.</p></div>{error && <p className="form-error">{error}</p>}<button className="btn btn-block" disabled={loading || !form.city}>{loading ? 'Placing order…' : `Place order — ${money(finalTotal)}`}</button></section><OrderTotal cart={cart} checkout coupon={coupon} shipping={shipping} city={form.city} /></form></div>;
}

const PAYMENT_LABELS = { cod: 'Cash on Delivery' };

function readLastOrder(number) {
    try {
        const saved = JSON.parse(sessionStorage.getItem('last_order') || 'null');
        return saved && saved.order_number === number ? saved : null;
    } catch { return null; }
}

export function OrderSuccess() {
    const number = new URLSearchParams(useLocation().search).get('order') || '';
    const store = useStore();
    const [order, setOrder] = useState(() => readLastOrder(number));
    const [loading, setLoading] = useState(!order && !!number);
    useEffect(() => {
        if (!number) return;
        api.get(`/orders/${encodeURIComponent(number)}`)
            .then((r) => setOrder(r.data?.order || null))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [number]);

    const template = String(store.settings?.order_success_message || '').trim();
    const message = order && template
        ? template.replaceAll('{order_number}', order.order_number).replaceAll('{total}', money(order.total))
        : template;
    const date = order?.created_at ? new Date(order.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : '';

    return <div className="page shell narrow order-received">
        <div className="order-received-banner"><CheckCircle2 /><h1>Thank you. Your order has been received.</h1></div>
        {loading && <p className="order-received-loading">Loading order details…</p>}
        {(order || number) && <ul className="order-overview">
            <li><span>Order number</span><strong>{order?.order_number || number}</strong></li>
            {date && <li><span>Date</span><strong>{date}</strong></li>}
            {order && <li><span>Total</span><strong>{money(order.total)}</strong></li>}
            {order && <li><span>Payment method</span><strong>{PAYMENT_LABELS[order.payment_method] || order.payment_method}</strong></li>}
        </ul>}
        {message && <div className="order-received-message">{message}</div>}
        {order && <section className="order-received-details">
            <h2>Order details</h2>
            <table>
                <thead><tr><th>Product</th><th>Total</th></tr></thead>
                <tbody>
                    {(order.items || []).map((item, i) => {
                        const addons = item.options?.summary || [];
                        return <tr key={i}>
                            <td>
                                {item.slug ? <Link to={`/product/${item.slug}`}>{item.name}</Link> : item.name} <b>× {item.quantity}</b>
                                {!!addons.length && <ul className="order-received-addons">{addons.map((a, j) => <li key={j}>{a.label}{Number(a.price) > 0 && <small> (+{money(a.price)})</small>}</li>)}</ul>}
                            </td>
                            <td>{money(item.total)}</td>
                        </tr>;
                    })}
                </tbody>
                <tfoot>
                    <tr><th>Subtotal</th><td>{money(order.subtotal)}</td></tr>
                    {Number(order.discount) > 0 && <tr><th>Discount{order.coupon_code ? ` (${order.coupon_code})` : ''}</th><td>-{money(order.discount)}</td></tr>}
                    <tr><th>Delivery{order.city ? ` (${order.city})` : ''}</th><td>{Number(order.shipping) > 0 ? money(order.shipping) : 'Free'}</td></tr>
                    <tr><th>Payment method</th><td>{PAYMENT_LABELS[order.payment_method] || order.payment_method}</td></tr>
                    <tr className="grand"><th>Total</th><td>{money(order.total)}</td></tr>
                </tfoot>
            </table>
        </section>}
        <div className="order-received-actions"><Link className="btn" to="/shop">Continue shopping</Link><Link className="btn btn-ghost" to="/">Back home</Link></div>
    </div>;
}
