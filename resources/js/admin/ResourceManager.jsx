import { ArrowLeft, Download, Eye, FileSpreadsheet, Pencil, Plus, Printer, Search, Trash2, Upload } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, listFrom, messageFrom, toFormData, unwrap } from '../api/client';
import { Empty, ErrorState, Loading, Modal, Pagination, asset, money } from '../components/Common';
import RichEditor from '../components/RichEditor';
import { addonUid, normalizeCatalogList } from '../data/addons';
import { AdminPage } from './Dashboard';

export const resourceConfigs = {
    products: { title: 'Products', singular: 'Product', fields: [['name', 'Name', 'text', true], ['slug', 'Slug'], ['sku', 'SKU', 'text', true], ['price', 'Price', 'number', true], ['sale_price', 'Sale price', 'number'], ['stock', 'Stock', 'number', true], ['category_id', 'Category', 'category-select', true], ['brand_id', 'Brand', 'brand-select'], ['condition', 'Condition', 'condition-select', true], ['addons_enabled', 'Enable add-ons', 'checkbox'], ['addons', 'Product add-ons', 'product-addons'], ['short_description', 'Short description', 'textarea'], ['description', 'Description', 'editor'], ['specs', 'Specifications (JSON)', 'json'], ['seo_title', 'SEO title'], ['seo_description', 'SEO description', 'textarea'], ['image', 'Main image', 'file'], ['gallery', 'Gallery images', 'files'], ['is_featured', 'Featured', 'checkbox'], ['is_sale', 'On sale', 'checkbox'], ['is_active', 'Active', 'checkbox']], columns: [['image_url', 'Image', 'image'], ['name', 'Product'], ['sku', 'SKU'], ['price', 'Price', 'money'], ['stock', 'Stock'], ['is_active', 'Status', 'boolean']], multipart: true },
    categories: { title: 'Categories', singular: 'Category', fields: [['name', 'Name', 'text', true], ['slug', 'Slug'], ['description', 'Description', 'textarea'], ['image', 'Image', 'file'], ['sort_order', 'Sort order', 'number'], ['is_active', 'Active', 'checkbox']], columns: [['image_url', 'Image', 'image'], ['name', 'Name'], ['products_count', 'Products'], ['sort_order', 'Order'], ['is_active', 'Status', 'boolean']], multipart: true },
    brands: { title: 'Brands', singular: 'Brand', fields: [['name', 'Name', 'text', true], ['slug', 'Slug'], ['description', 'Description', 'textarea'], ['logo', 'Logo', 'file'], ['is_active', 'Active', 'checkbox']], columns: [['logo_url', 'Logo', 'image'], ['name', 'Name'], ['products_count', 'Products'], ['is_active', 'Status', 'boolean']], multipart: true },
    posts: { title: 'Blog Posts', singular: 'Post', fields: [['title', 'Title', 'text', true], ['slug', 'Slug'], ['excerpt', 'Excerpt', 'textarea'], ['content', 'Content', 'editor', true], ['image', 'Featured image', 'file'], ['seo_title', 'SEO title'], ['seo_description', 'SEO description', 'textarea'], ['published_at', 'Publish date', 'date'], ['is_active', 'Published', 'checkbox']], columns: [['image_url', 'Image', 'image'], ['title', 'Title'], ['is_active', 'Published', 'boolean'], ['published_at', 'Date', 'date']], multipart: true },
    pages: { title: 'Pages', singular: 'Page', fields: [['title', 'Title', 'text', true], ['slug', 'Slug'], ['content', 'Content', 'editor', true], ['seo_title', 'SEO title'], ['seo_description', 'SEO description', 'textarea'], ['is_active', 'Active', 'checkbox']], columns: [['title', 'Title'], ['slug', 'Slug'], ['is_active', 'Status', 'boolean']], editorWide: true },
    banners: { title: 'Banners', singular: 'Banner', fields: [['title', 'Title', 'text', true], ['subtitle', 'Subtitle', 'textarea'], ['button_text', 'Button text'], ['url', 'Button URL'], ['image', 'Image', 'file'], ['sort_order', 'Sort order', 'number'], ['is_active', 'Active', 'checkbox']], columns: [['image_url', 'Image', 'image'], ['title', 'Title'], ['sort_order', 'Order'], ['is_active', 'Status', 'boolean']], multipart: true },
    faqs: { title: 'FAQs', singular: 'FAQ', fields: [['question', 'Question', 'text', true], ['answer', 'Answer', 'textarea', true], ['sort_order', 'Sort order', 'number'], ['is_active', 'Active', 'checkbox']], columns: [['question', 'Question'], ['sort_order', 'Order'], ['is_active', 'Status', 'boolean']] },
    testimonials: { title: 'Testimonials', singular: 'Testimonial', fields: [['name', 'Name', 'text', true], ['role', 'Designation'], ['content', 'Testimonial', 'textarea', true], ['rating', 'Rating', 'number'], ['image', 'Photo', 'file'], ['is_active', 'Active', 'checkbox']], columns: [['image_url', 'Photo', 'image'], ['name', 'Name'], ['role', 'Designation'], ['rating', 'Rating'], ['is_active', 'Status', 'boolean']], multipart: true },
    coupons: { title: 'Discount Coupons', singular: 'Coupon', fields: [['code', 'Coupon code', 'text', true], ['type', 'Discount type', 'coupon-type', true], ['value', 'Discount value', 'number', true], ['category_id', 'Only for category (optional)', 'category-select'], ['addon_group', 'Only on add-on group, e.g. Warranty (optional)'], ['addon_option', 'Only on add-on option, e.g. 1 year (optional)'], ['minimum_order', 'Minimum order', 'number'], ['maximum_discount', 'Maximum discount', 'number'], ['usage_limit', 'Usage limit', 'number'], ['starts_at', 'Starts at', 'datetime-local'], ['expires_at', 'Expires at', 'datetime-local'], ['is_active', 'Active', 'checkbox']], columns: [['code', 'Code'], ['type', 'Type'], ['value', 'Value'], ['addon_option', 'Add-on only'], ['used_count', 'Used'], ['is_active', 'Status', 'boolean']] },
    subscribers: { title: 'Subscribers', singular: 'Subscriber', fields: [['email', 'Email', 'email', true], ['is_active', 'Active', 'checkbox']], columns: [['email', 'Email'], ['is_active', 'Status', 'boolean'], ['created_at', 'Subscribed', 'date']] },
};

const PRODUCT_MAIN_FIELDS = ['name', 'slug', 'sku', 'price', 'sale_price', 'stock', 'category_id', 'brand_id', 'short_description', 'description', 'specs', 'seo_title', 'seo_description'];
const PRODUCT_SIDE_ADDONS = ['addons_enabled', 'addons'];

function fieldInitial(key, type, item) {
    if (type === 'file') return '';
    if (type === 'files') return [];
    if (type === 'checkbox') return Boolean(item?.[key]);
    if (type === 'product-addons') return normalizeCatalogList(item?.[key]);
    if (type === 'json' && item?.[key] && typeof item[key] !== 'string') return JSON.stringify(item[key], null, 2);
    if (type === 'condition-select') return item?.[key] || 'new';
    return item?.[key] ?? '';
}

export default function ResourceManager({ resource }) {
    const config = resourceConfigs[resource], [result, setResult] = useState({ items: [], meta: {} }), [loading, setLoading] = useState(true), [error, setError] = useState(''), [search, setSearch] = useState(''), [page, setPage] = useState(1), [editing, setEditing] = useState(null), [open, setOpen] = useState(false), [importOpen, setImportOpen] = useState(false);
    const navigate = useNavigate();
    const isProducts = resource === 'products';
    const load = () => { setLoading(true); setError(''); api.get(`/cp/${resource}`, { params: { search, page } }).then((r) => setResult(listFrom(r))).catch((e) => setError(messageFrom(e))).finally(() => setLoading(false)); };
    useEffect(load, [resource, page]);
    const remove = async (item) => { if (!confirm(`Delete this ${config.singular.toLowerCase()}?`)) return; try { await api.delete(`/cp/${resource}/${item.id}`); load(); } catch (e) { alert(messageFrom(e)); } };
    const create = () => {
        if (isProducts) navigate('/cp/products/new');
        else { setEditing(null); setOpen(true); }
    };
    const edit = (item) => {
        if (isProducts) navigate(`/cp/products/${item.id}/edit`);
        else { setEditing(item); setOpen(true); }
    };
    const pageAction = resource === 'subscribers' ? null : (
        <div className="admin-page-actions">
            {isProducts && <>
                <a className="btn btn-outline" href="/api/cp/products/import-template"><Download /> Demo Excel</a>
                <button type="button" className="btn btn-outline" onClick={() => setImportOpen(true)}><Upload /> Import Excel</button>
            </>}
            <button className="btn" onClick={create}><Plus /> Add {config.singular}</button>
        </div>
    );
    return <AdminPage title={config.title} subtitle={`Manage storefront ${config.title.toLowerCase()}.`} action={pageAction}>
        <section className="admin-card"><div className="resource-toolbar"><form onSubmit={(e) => { e.preventDefault(); setPage(1); load(); }}><Search /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={`Search ${config.title.toLowerCase()}…`} /></form></div>
            {loading ? <Loading /> : error ? <ErrorState message={error} retry={load} /> : !result.items.length ? <Empty title={`No ${config.title.toLowerCase()} found`} /> : <div className="table-wrap"><table><thead><tr>{config.columns.map(([, label]) => <th key={label}>{label}</th>)}<th>Actions</th></tr></thead><tbody>{result.items.map((item) => <tr key={item.id}>{config.columns.map(([key, , type]) => <td key={key}>{renderCell(item[key] ?? item[key?.replace('_url', '')], type, item)}</td>)}<td><div className="table-actions"><button onClick={() => edit(item)} title="Edit"><Pencil /></button><button className="danger" onClick={() => remove(item)} title="Delete"><Trash2 /></button></div></td></tr>)}</tbody></table></div>}
            <Pagination meta={result.meta} onPage={setPage} />
        </section>
        {!isProducts && <ResourceForm open={open} close={() => setOpen(false)} resource={resource} config={config} item={editing} saved={() => { setOpen(false); load(); }} />}
        {isProducts && <ProductImportModal open={importOpen} close={() => setImportOpen(false)} saved={() => { setImportOpen(false); load(); }} />}
    </AdminPage>;
}

export function ProductFormPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const isEdit = Boolean(id);
    const config = resourceConfigs.products;
    const [item, setItem] = useState(null);
    const [loading, setLoading] = useState(isEdit);
    const [error, setError] = useState('');
    const [loadError, setLoadError] = useState('');
    const [busy, setBusy] = useState(false);
    const [categories, setCategories] = useState([]);
    const [brands, setBrands] = useState([]);

    const initial = useMemo(() => Object.fromEntries(config.fields.map(([key, , type]) => [key, fieldInitial(key, type, item)])), [item, config]);
    const [values, setValues] = useState(initial);
    useEffect(() => setValues(initial), [initial]);

    useEffect(() => {
        Promise.all([
            api.get('/cp/categories', { params: { per_page: 100 } }),
            api.get('/cp/brands', { params: { per_page: 100 } }),
        ]).then(([catRes, brandRes]) => {
            setCategories(listFrom(catRes).items || []);
            setBrands(listFrom(brandRes).items || []);
        }).catch(() => { setCategories([]); setBrands([]); });
    }, []);

    useEffect(() => {
        if (!isEdit) { setItem(null); setLoading(false); return; }
        setLoading(true); setLoadError('');
        api.get(`/cp/products/${id}`)
            .then((r) => setItem(unwrap(r) || null))
            .catch((e) => setLoadError(messageFrom(e)))
            .finally(() => setLoading(false));
    }, [id, isEdit]);

    const setField = (key, value) => setValues((current) => ({ ...current, [key]: value }));

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true); setError('');
        try {
            const payload = { ...values };
            payload.addons_enabled = values.addons_enabled ? 1 : 0;
            payload.addons = JSON.stringify(values.addons_enabled ? normalizeCatalogList(values.addons) : []);
            if (isEdit) payload._method = 'PUT';
            Object.keys(payload).forEach((key) => {
                const field = config.fields.find(([name]) => name === key);
                if (!field) return;
                const type = field[2];
                if (type === 'file' && !(payload[key] instanceof File)) delete payload[key];
                if (type === 'files' && (!Array.isArray(payload[key]) || !payload[key].length || !(payload[key][0] instanceof File))) delete payload[key];
            });
            if (!payload.brand_id) payload.brand_id = '';
            const body = toFormData(payload);
            if (isEdit) await api.post(`/cp/products/${id}`, body);
            else await api.post('/cp/products', body);
            navigate('/cp/products');
        } catch (err) {
            setError(messageFrom(err));
        } finally {
            setBusy(false);
        }
    };

    if (loading) return <AdminPage><Loading label="Loading product" /></AdminPage>;
    if (loadError) return <AdminPage><ErrorState message={loadError} /></AdminPage>;

    const fieldByKey = Object.fromEntries(config.fields.map((field) => [field[0], field]));
    const renderFields = (keys) => keys.map((key) => {
        const field = fieldByKey[key];
        if (!field) return null;
        const [, label, type = 'text', required] = field;
        if (key === 'addons' && !values.addons_enabled) return null;
        return (
            <Field
                key={key}
                name={key}
                label={label}
                type={type}
                required={required}
                value={values[key]}
                existing={previewUrlsForField(key, item)}
                options={{ categories, brands }}
                onChange={(value) => setField(key, value)}
            />
        );
    });

    return (
        <AdminPage
            title={isEdit ? 'Edit product' : 'Add product'}
            subtitle={isEdit ? 'Update details, media and add-ons for this product.' : 'Create a product with pricing, media and optional add-ons.'}
            action={(
                <div className="admin-page-actions">
                    <Link className="btn btn-outline" to="/cp/products"><ArrowLeft size={16} /> Back</Link>
                    <button form="product-form-page" className="btn" disabled={busy}>{busy ? 'Saving…' : 'Save product'}</button>
                </div>
            )}
        >
            <form id="product-form-page" className="product-form-page" onSubmit={submit}>
                <div className="product-form-layout">
                    <section className="admin-card product-form-main">
                        <div className="card-head"><div><h2>Product details</h2><p>Name, pricing, content and SEO</p></div></div>
                        <div className="resource-form form-grid has-editor">{renderFields(PRODUCT_MAIN_FIELDS)}</div>
                    </section>
                    <aside className="product-form-side">
                        <section className="admin-card">
                            <div className="card-head"><div><h2>Options</h2><p>Condition, media and visibility</p></div></div>
                            <div className="resource-form form-grid side-stack">
                                {renderFields(['condition', 'image', 'gallery'])}
                                <div className="product-flag-checks full">
                                    {renderFields(['is_featured', 'is_sale', 'is_active'])}
                                </div>
                            </div>
                        </section>
                        <section className="admin-card">
                            <div className="card-head"><div><h2>Add-ons</h2><p>Custom groups and option prices</p></div></div>
                            <div className="resource-form form-grid side-stack product-addons-side">
                                {renderFields(PRODUCT_SIDE_ADDONS)}
                            </div>
                        </section>
                    </aside>
                </div>
                {error && <p className="form-error">{error}</p>}
                <div className="product-form-footer">
                    <Link className="btn btn-ghost" to="/cp/products">Cancel</Link>
                    <button className="btn" disabled={busy}>{busy ? 'Saving…' : 'Save product'}</button>
                </div>
            </form>
        </AdminPage>
    );
}

function ProductImportModal({ open, close, saved }) {
    const inputRef = useRef(null);
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [result, setResult] = useState(null);
    useEffect(() => {
        if (!open) return;
        setFile(null); setBusy(false); setError(''); setResult(null);
        if (inputRef.current) inputRef.current.value = '';
    }, [open]);
    const submit = async (e) => {
        e.preventDefault();
        if (!file) { setError('Please choose an Excel file (.xlsx, .xls or .csv).'); return; }
        setBusy(true); setError(''); setResult(null);
        try {
            const body = new FormData();
            body.append('file', file);
            const { data } = await api.post('/cp/products/import', body);
            setResult(data);
            if ((data.imported || 0) + (data.updated || 0) > 0) setTimeout(saved, 900);
        } catch (err) {
            const data = err?.response?.data;
            if (data?.errors?.length) setResult(data);
            setError(messageFrom(err));
        } finally { setBusy(false); }
    };
    return <Modal open={open} onClose={close} title="Import products from Excel" wide>
        <form className="resource-form" onSubmit={submit}>
            <p className="import-hint">Download the demo Excel, fill product rows (use existing category & brand names), then upload here. Same SKU updates the product.</p>
            <div className="import-actions">
                <a className="btn btn-outline" href="/api/cp/products/import-template"><FileSpreadsheet /> Download demo file</a>
            </div>
            <label className="full">Excel file (.xlsx, .xls, .csv)
                <input ref={inputRef} type="file" accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv" onChange={(e) => setFile(e.target.files?.[0] || null)} />
            </label>
            {error && <p className="form-error full">{error}</p>}
            {result && <div className="import-result">
                <p>{result.message}</p>
                {!!result.errors?.length && <ul>{result.errors.map((row) => <li key={`${row.row}-${row.message}`}>Row {row.row}: {row.message}</li>)}</ul>}
            </div>}
            <div className="modal-actions full">
                <button type="button" className="btn btn-ghost" onClick={close}>Cancel</button>
                <button className="btn" disabled={busy}>{busy ? 'Importing…' : 'Import products'}</button>
            </div>
        </form>
    </Modal>;
}

function renderCell(value, type, item = null) {
    if (type === 'image') {
        const src = value || item?.image_url || item?.logo_url || item?.image || item?.logo || '';
        return <span className="admin-thumb">{src ? <img src={asset(src)} alt="" /> : <span className="admin-thumb-empty" />}</span>;
    }
    if (type === 'money') return money(value);
    if (type === 'date') return value ? new Date(value).toLocaleDateString() : '—';
    if (type === 'boolean') return <span className={`status ${value ? 'active' : 'inactive'}`}>{value ? 'Active' : 'Inactive'}</span>;
    return value ?? '—';
}

function previewUrlsForField(name, item) {
    if (!item) return [];
    if (name === 'logo') return item.logo_url ? [item.logo_url] : item.logo ? [item.logo] : [];
    if (name === 'image') return item.image_url ? [item.image_url] : item.image ? [item.image] : [];
    if (name === 'gallery') {
        if (Array.isArray(item.gallery_urls) && item.gallery_urls.length) return item.gallery_urls;
        if (Array.isArray(item.gallery)) return item.gallery;
    }
    return [];
}

function ResourceForm({ open, close, resource, config, item, saved }) {
    const initial = useMemo(() => Object.fromEntries(config.fields.map(([key, , type]) => [key, fieldInitial(key, type, item)])), [item, config]);
    const [values, setValues] = useState(initial), [busy, setBusy] = useState(false), [error, setError] = useState('');
    const [categories, setCategories] = useState([]);
    const [brands, setBrands] = useState([]);
    useEffect(() => setValues(initial), [initial, open]);
    useEffect(() => {
        if (!open || !['products', 'coupons'].includes(resource)) return;
        Promise.all([
            api.get('/cp/categories', { params: { per_page: 100 } }),
            api.get('/cp/brands', { params: { per_page: 100 } }),
        ]).then(([catRes, brandRes]) => {
            setCategories(listFrom(catRes).items || []);
            setBrands(listFrom(brandRes).items || []);
        }).catch(() => {
            setCategories([]);
            setBrands([]);
        });
    }, [open, resource]);
    const submit = async (e) => {
        e.preventDefault(); setBusy(true); setError('');
        try {
            const payload = { ...values };
            if (resource === 'products') {
                payload.addons_enabled = values.addons_enabled ? 1 : 0;
                payload.addons = JSON.stringify(
                    values.addons_enabled ? normalizeCatalogList(values.addons) : [],
                );
            }
            if (config.multipart && item) payload._method = 'PUT';
            // Don't send empty file fields — keeps existing image on update
            if (config.multipart) {
                Object.keys(payload).forEach((key) => {
                    const field = config.fields.find(([name]) => name === key);
                    if (!field) return;
                    const type = field[2];
                    if (type === 'file' && !(payload[key] instanceof File)) delete payload[key];
                    if (type === 'files' && (!Array.isArray(payload[key]) || !payload[key].length || !(payload[key][0] instanceof File))) delete payload[key];
                });
            }
            if (!payload.brand_id) payload.brand_id = '';
            const body = config.multipart ? toFormData(payload) : payload;
            if (item) await api.post(`/cp/${resource}/${item.id}`, config.multipart ? body : { ...body, _method: 'PUT' });
            else await api.post(`/cp/${resource}`, body);
            saved();
        } catch (err) { setError(messageFrom(err)); } finally { setBusy(false); }
    };
    return <Modal open={open} onClose={close} title={`${item ? 'Edit' : 'Add'} ${config.singular}`} wide><form className={`resource-form form-grid ${config.fields.some(([, , type]) => type === 'editor') ? 'has-editor' : ''}`} onSubmit={submit}>{config.fields.map(([key, label, type = 'text', required]) => {
        if (key === 'addons' && !values.addons_enabled) return null;
        return <Field key={key} name={key} label={label} type={type} required={required} value={values[key]} existing={previewUrlsForField(key, item)} options={{ categories, brands }} onChange={(value) => setValues({ ...values, [key]: value })} />;
    })}{error && <p className="form-error full">{error}</p>}<div className="modal-actions full"><button type="button" className="btn btn-ghost" onClick={close}>Cancel</button><button className="btn" disabled={busy}>{busy ? 'Saving…' : `Save ${config.singular}`}</button></div></form></Modal>;
}

function Field({ name, label, type, value, onChange, required, existing = [], options = {} }) {
    if (type === 'checkbox') return <label className="check full"><input name={name} type="checkbox" checked={Boolean(value)} onChange={(e) => onChange(e.target.checked ? 1 : 0)} /><span>{label}</span></label>;
    if (type === 'coupon-type') return <label>{label}<select required={required} value={value || 'percent'} onChange={(e) => onChange(e.target.value)}><option value="percent">Percentage</option><option value="fixed">Fixed amount</option></select></label>;
    if (type === 'category-select') {
        return <label>{label}{required ? ' *' : ''}
            <select required={required} value={value || ''} onChange={(e) => onChange(e.target.value)}>
                <option value="">{required ? 'Select category' : 'Any category'}</option>
                {(options.categories || []).map((cat) => <option key={cat.id} value={cat.id}>{cat.name}</option>)}
            </select>
        </label>;
    }
    if (type === 'brand-select') {
        return <label>{label}{required ? ' *' : ''}
            <select value={value || ''} onChange={(e) => onChange(e.target.value)}>
                <option value="">No brand</option>
                {(options.brands || []).map((brand) => <option key={brand.id} value={brand.id}>{brand.name}</option>)}
            </select>
        </label>;
    }
    if (type === 'condition-select') {
        return <label>{label}{required ? ' *' : ''}
            <select required={required} value={value || 'new'} onChange={(e) => onChange(e.target.value)}>
                <option value="new">New</option>
                <option value="used">Used</option>
                <option value="refurbished">Refurbished</option>
                <option value="open-box">Open-box</option>
            </select>
        </label>;
    }
    if (type === 'product-addons') {
        return <ProductAddonsBuilder value={value} onChange={onChange} />;
    }
    if (type === 'editor') return <div className="full editor-field"><span className="editor-label">{label}{required ? ' *' : ''}</span><RichEditor value={value || ''} onChange={onChange} required={required} placeholder={`Write ${label.toLowerCase()}…`} /></div>;
    if (type === 'textarea' || type === 'json') return <label className="full">{label}<textarea required={required} rows={type === 'json' ? 6 : 4} value={value} onChange={(e) => onChange(e.target.value)} placeholder={type === 'json' ? '[{\"name\":\"Processor\",\"value\":\"Intel Core i7\"}]' : ''} /></label>;
    if (type === 'file' || type === 'files') {
        return <ImageField label={label} type={type} value={value} existing={existing} onChange={onChange} />;
    }
    return <label>{label}<input name={name} required={required} type={type} value={type === 'datetime-local' && value ? String(value).slice(0, 16) : value} onChange={(e) => onChange(e.target.value)} /></label>;
}

function ProductAddonsBuilder({ value, onChange }) {
    const groups = Array.isArray(value) ? value : [];

    const setGroups = (next) => onChange(next);

    const addGroup = () => {
        const title = window.prompt('Add-on group title (e.g. RAM, Storage, Warranty)');
        if (title === null) return;
        const labelText = String(title).trim();
        if (!labelText) {
            alert('Group title is required.');
            return;
        }
        setGroups([
            ...groups,
            {
                id: addonUid('g'),
                label: labelText,
                options: [{ id: addonUid('o'), label: 'Included', price: 0 }],
            },
        ]);
    };

    const updateGroup = (index, patch) => {
        setGroups(groups.map((group, i) => (i === index ? { ...group, ...patch } : group)));
    };

    const removeGroup = (index) => {
        if (!confirm('Remove this add-on group?')) return;
        setGroups(groups.filter((_, i) => i !== index));
    };

    const addOption = (groupIndex) => {
        const group = groups[groupIndex];
        updateGroup(groupIndex, {
            options: [...(group.options || []), { id: addonUid('o'), label: '', price: 0 }],
        });
    };

    const updateOption = (groupIndex, optionIndex, patch) => {
        const group = groups[groupIndex];
        updateGroup(groupIndex, {
            options: (group.options || []).map((option, i) => (i === optionIndex ? { ...option, ...patch } : option)),
        });
    };

    const removeOption = (groupIndex, optionIndex) => {
        const group = groups[groupIndex];
        const options = (group.options || []).filter((_, i) => i !== optionIndex);
        if (!options.length) {
            alert('Each add-on group needs at least one option.');
            return;
        }
        updateGroup(groupIndex, { options });
    };

    return (
        <div className="full product-addons-builder">
            <div className="product-addons-builder-head">
                <button type="button" className="btn btn-outline" onClick={addGroup}><Plus size={16} /> Add addon</button>
            </div>
            {!groups.length && <p className="field-hint">No add-ons yet. Click “Add addon” to create the first group.</p>}
            <div className="product-addons-groups">
                {groups.map((group, groupIndex) => (
                    <div className="product-addons-group" key={group.id || groupIndex}>
                        <div className="product-addons-group-head">
                            <label className="full">
                                Group title
                                <input
                                    value={group.label || ''}
                                    onChange={(e) => updateGroup(groupIndex, { label: e.target.value })}
                                    placeholder="e.g. RAM"
                                />
                            </label>
                            <button type="button" className="btn btn-ghost danger-text" onClick={() => removeGroup(groupIndex)}><Trash2 size={16} /> Remove</button>
                        </div>
                        <div className="product-addons-options">
                            <div className="product-addons-options-head"><span>Option title</span><span>Price (PKR)</span><span /></div>
                            {(group.options || []).map((option, optionIndex) => (
                                <div className="product-addons-option-row" key={option.id || optionIndex}>
                                    <input
                                        value={option.label || ''}
                                        onChange={(e) => updateOption(groupIndex, optionIndex, { label: e.target.value })}
                                        placeholder="e.g. 16GB RAM"
                                    />
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        value={option.price ?? 0}
                                        onChange={(e) => updateOption(groupIndex, optionIndex, { price: e.target.value })}
                                        placeholder="0"
                                    />
                                    <button type="button" className="icon-btn danger" title="Remove option" onClick={() => removeOption(groupIndex, optionIndex)}><Trash2 size={15} /></button>
                                </div>
                            ))}
                        </div>
                        <button type="button" className="btn btn-ghost" onClick={() => addOption(groupIndex)}><Plus size={15} /> Add option</button>
                    </div>
                ))}
            </div>
        </div>
    );
}

function ImageField({ label, type, value, existing = [], onChange }) {
    const selected = type === 'files'
        ? (Array.isArray(value) ? value : []).filter((f) => f instanceof File)
        : (value instanceof File ? [value] : []);
    const fileKey = selected.map((f) => `${f.name}-${f.size}-${f.lastModified}`).join('|');
    const [selectedUrls, setSelectedUrls] = useState([]);
    useEffect(() => {
        const urls = selected.map((file) => URL.createObjectURL(file));
        setSelectedUrls(urls);
        return () => urls.forEach((url) => URL.revokeObjectURL(url));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fileKey]);
    const previews = selectedUrls.length ? selectedUrls : existing.map((src) => asset(src)).filter(Boolean);
    return (
        <label className="full image-field">
            {label}
            {!!previews.length && (
                <div className={`image-previews ${type === 'files' ? 'multi' : ''}`}>
                    {previews.map((src) => <span className="image-preview-box" key={src}><img src={src} alt="" /></span>)}
                </div>
            )}
            <input type="file" accept="image/*" multiple={type === 'files'} onChange={(e) => onChange(type === 'files' ? Array.from(e.target.files || []) : (e.target.files?.[0] || ''))} />
            {!!existing.length && !selectedUrls.length && <small className="image-field-hint">Current image shown above. Choose a new file to replace.</small>}
        </label>
    );
}

export function Orders() {
    const [result, setResult] = useState({ items: [], meta: {} }), [loading, setLoading] = useState(true), [page, setPage] = useState(1);
    const load = () => { setLoading(true); api.get('/cp/orders', { params: { page } }).then((r) => setResult(listFrom(r))).finally(() => setLoading(false)); };
    useEffect(load, [page]);
    const status = async (id, value) => { await api.patch(`/cp/orders/${id}/status`, { status: value }); load(); };
    return <AdminPage title="Orders" subtitle="Review, update and fulfil customer orders."><section className="admin-card">{loading ? <Loading /> : <div className="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Phone</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead><tbody>{result.items.map((x) => <tr key={x.id}><td><b>#{x.order_number || x.id}</b></td><td>{x.customer_name || x.name}</td><td>{x.phone}</td><td>{money(x.total)}</td><td><select value={x.status || 'pending'} onChange={(e) => status(x.id, e.target.value)}>{['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'].map((s) => <option key={s}>{s}</option>)}</select></td><td><div className="table-actions"><Link to={`/cp/orders/${x.id}`}><Eye /></Link><Link to={`/cp/orders/${x.id}?print=1`} target="_blank"><Printer /></Link></div></td></tr>)}</tbody></table></div>}<Pagination meta={result.meta} onPage={setPage} /></section></AdminPage>;
}

export function OrderDetail() {
    const { id } = useParams(), [order, setOrder] = useState(null);
    useEffect(() => { api.get(`/cp/orders/${id}`).then((r) => { const data = unwrap(r); setOrder(data.order || data); }); }, [id]);
    useEffect(() => { if (order && new URLSearchParams(location.search).get('print')) setTimeout(() => window.print(), 300); }, [order]);
    if (!order) return <AdminPage><Loading /></AdminPage>;
    return <AdminPage title={`Order #${order.order_number || order.id}`} action={<button className="btn btn-outline" onClick={() => window.print()}><Printer /> Print</button>}><div className="order-detail-grid"><section className="admin-card"><h2>Order items</h2>{(order.items || []).map((x) => {
        const addonLabels = (x.options?.summary || []).map((o) => o.label).filter(Boolean);
        return <div className="order-item" key={x.id}><span>{x.product?.name || x.name} × {x.quantity}{!!addonLabels.length && <small className="order-item-addons">{addonLabels.join(' · ')}</small>}</span><strong>{money(x.total || x.unit_price * x.quantity)}</strong></div>;
    })}{Number(order.discount) > 0 && <div className="order-item"><span>Coupon ({order.coupon_code})</span><strong>-{money(order.discount)}</strong></div>}{Number(order.shipping) > 0 && <div className="order-item"><span>Delivery ({order.city})</span><strong>{money(order.shipping)}</strong></div>}<div className="order-item total"><span>Total</span><strong>{money(order.total)}</strong></div></section><aside className="admin-card"><h2>Customer & delivery</h2><p><b>{order.customer_name || order.name}</b><br />{order.phone}<br />{order.email}</p><p>{order.address}<br />{order.city}</p><p><b>Status:</b> {order.status}</p>{order.notes && <p><b>Notes:</b> {order.notes}</p>}</aside></div></AdminPage>;
}
