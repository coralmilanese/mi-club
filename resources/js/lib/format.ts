const moneda = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const monedaEntera = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', minimumFractionDigits: 0, maximumFractionDigits: 0 });

/** Los importes viajan como string decimal desde el backend; nunca operar con float acá. */
export function formatMoney(valor: string | number | null | undefined, opts: { entero?: boolean } = {}): string {
    if (valor === null || valor === undefined || valor === '') return '—';
    const n = typeof valor === 'string' ? Number(valor) : valor;
    if (Number.isNaN(n)) return '—';
    return (opts.entero ? monedaEntera : moneda).format(n);
}

/** Recibe 'YYYY-MM-DD' (o ISO) y lo muestra dd/mm/aaaa sin corrimiento por zona horaria. */
export function formatDate(fecha: string | null | undefined): string {
    if (!fecha) return '—';
    const [y, m, d] = fecha.slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
}

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** 'YYYY-MM-DD' → 'septiembre 2026' */
export function formatPeriodo(fecha: string | null | undefined): string {
    if (!fecha) return '—';
    const [y, m] = fecha.slice(0, 10).split('-');
    return `${MESES[Number(m) - 1]} ${y}`;
}
