import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Celda {
    estado: 'pendiente' | 'parcial' | 'pagada' | 'exenta' | 'anulada';
    importe: string;
    pagador: boolean;
    imputado?: string;
    exigible?: string;
    falta?: string;
}
interface Fila {
    socio_id: number;
    socio: string;
    meses: (Celda | null)[];
    entregado: string;
    deuda: string;
}
interface Props {
    anio: number;
    filas: Fila[];
    totales: { deuda: string; entregado: string };
}

const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
const COLOR: Record<Celda['estado'], string> = {
    pagada: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200',
    parcial: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    pendiente: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
    exenta: 'bg-muted text-muted-foreground',
    anulada: 'bg-muted text-muted-foreground line-through',
};

/** Pendiente o parcial: click lleva a registrar el pago de ese socio (solo el tesorero puede). */
function CeldaMes({ celda, socioId, esTesorero }: { celda: Celda | null; socioId: number; esTesorero: boolean }) {
    if (!celda) return null;

    const texto = `${formatMoney(celda.importe, { entero: true })}${celda.pagador ? ' †' : ''}`;
    const esParcial = celda.estado === 'parcial';
    const clickable = esTesorero && (celda.estado === 'pendiente' || esParcial);

    const contenido = clickable ? (
        <Link
            href={route('pagos.create', { socio_id: socioId })}
            className={`hover:underline ${esParcial ? 'decoration-dotted underline-offset-2' : ''}`}
        >
            {texto}
        </Link>
    ) : (
        <span className={esParcial ? 'cursor-help underline decoration-dotted underline-offset-2' : ''}>{texto}</span>
    );

    if (!esParcial) {
        return contenido;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>{contenido}</TooltipTrigger>
            <TooltipContent>
                Pagó {formatMoney(celda.imputado, { entero: true })} de {formatMoney(celda.exigible, { entero: true })} a la tarifa de hoy
                <br />
                Le falta {formatMoney(celda.falta, { entero: true })}
                {clickable && (
                    <>
                        <br />
                        Click para registrar el pago
                    </>
                )}
            </TooltipContent>
        </Tooltip>
    );
}

/** Sin acentos ni mayúsculas, para que "nuñez" encuentre a "Núñez". */
function normalizar(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

export default function Grilla({ anio, filas, totales }: Props) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cuotas', href: '/cuotas' }];
    const [periodo, setPeriodo] = useState(new Date().toISOString().slice(0, 7));
    const [simular, setSimular] = useState(true);
    const [q, setQ] = useState('');

    const devengar: FormEventHandler = (e) => {
        e.preventDefault();
        router.post(route('cuotas.devengar'), { periodo, simular }, { preserveScroll: true });
    };

    const filasVisibles = useMemo(() => {
        const termino = normalizar(q.trim());

        return termino === '' ? filas : filas.filter((f) => normalizar(f.socio).includes(termino));
    }, [filas, q]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Cuotas ${anio}`} />
            <TooltipProvider>
                <div className="flex flex-col gap-4 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h1 className="text-2xl font-semibold">Cuotas {anio}</h1>
                        <div className="flex items-center gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('cuotas.grilla', { anio: anio - 1 })}>← {anio - 1}</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('cuotas.grilla', { anio: anio + 1 })}>{anio + 1} →</Link>
                            </Button>
                        </div>
                    </div>

                    {esTesorero && (
                        <form onSubmit={devengar} className="flex flex-wrap items-end gap-3 rounded-md border p-3">
                            <div className="grid gap-1">
                                <Label htmlFor="periodo">Devengar el mes</Label>
                                <Input id="periodo" type="month" className="w-44" value={periodo} onChange={(e) => setPeriodo(e.target.value)} />
                            </div>
                            <Label className="flex items-center gap-2 pb-2">
                                <Checkbox checked={simular} onCheckedChange={(v) => setSimular(v === true)} />
                                Solo simular
                            </Label>
                            <Button type="submit" variant={simular ? 'secondary' : 'default'}>
                                {simular ? 'Simular' : 'Devengar'}
                            </Button>
                        </form>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Input
                            className="w-64"
                            placeholder="Buscar socio…"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            aria-label="Buscar socio"
                        />
                        <div className="text-muted-foreground flex flex-wrap gap-3 text-xs">
                            <span className="rounded bg-green-100 px-2 py-0.5 text-green-900">Pagada</span>
                            <span className="rounded bg-amber-100 px-2 py-0.5 text-amber-900">Parcial</span>
                            <span className="rounded bg-red-100 px-2 py-0.5 text-red-900">Pendiente</span>
                            <span>† la paga el titular del grupo familiar</span>
                        </div>
                    </div>

                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Socio</TableHead>
                                    {MESES.map((m) => (
                                        <TableHead key={m} className="text-right">
                                            {m}
                                        </TableHead>
                                    ))}
                                    <TableHead className="text-right">Entregado</TableHead>
                                    <TableHead className="text-right">Deuda hoy</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filasVisibles.map((f) => (
                                    <TableRow key={f.socio_id}>
                                        <TableCell className="font-medium">
                                            <Link href={route('socios.show', f.socio_id)} className="hover:underline">
                                                {f.socio}
                                            </Link>
                                        </TableCell>
                                        {f.meses.map((c, i) => (
                                            <TableCell key={i} className={`text-right ${c ? COLOR[c.estado] : ''}`}>
                                                <CeldaMes celda={c} socioId={f.socio_id} esTesorero={esTesorero} />
                                            </TableCell>
                                        ))}
                                        <TableCell className="text-right">{formatMoney(f.entregado, { entero: true })}</TableCell>
                                        <TableCell className="text-right font-medium">
                                            {Number(f.deuda) > 0 ? formatMoney(f.deuda, { entero: true }) : '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {filasVisibles.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={15} className="text-muted-foreground h-24 text-center">
                                            {filas.length === 0
                                                ? `No hay cuotas devengadas en ${anio}. Asigná planes a los socios y devengá el mes.`
                                                : 'Ningún socio coincide con la búsqueda.'}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={13}>Totales</TableCell>
                                    <TableCell className="text-right">{formatMoney(totales.entregado, { entero: true })}</TableCell>
                                    <TableCell className="text-right">{formatMoney(totales.deuda, { entero: true })}</TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </div>
            </TooltipProvider>
        </AppLayout>
    );
}
